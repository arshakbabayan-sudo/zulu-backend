<?php

namespace App\Services\Connections;

use App\Events\ConnectionAccepted;
use App\Events\ConnectionRejected;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\ServiceConnection;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectionService
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(array $data, User $actor): ServiceConnection
    {
        $validated = Validator::make($data, [
            'source_type' => ['required', 'string', Rule::in(ServiceConnection::SOURCE_TYPES)],
            'source_id' => ['required', 'integer'],
            'target_type' => ['required', 'string', Rule::in(ServiceConnection::TARGET_TYPES)],
            'target_id' => ['required', 'integer'],
            'connection_type' => ['sometimes', 'string', Rule::in(['only', 'both'])],
            'client_targeting' => ['sometimes', 'string', Rule::in(ServiceConnection::CLIENT_TARGETING)],
            'selected_client_ids' => ['sometimes', 'array'],
            'selected_client_ids.*' => ['integer', 'distinct'],
            'targeting' => ['sometimes', 'array'],
            'targeting.mode' => ['sometimes', 'string', Rule::in(ServiceConnection::CLIENT_TARGETING)],
            'targeting.client_ids' => ['sometimes', 'array'],
            'targeting.client_ids.*' => ['integer', 'distinct'],
            'city_rules' => ['sometimes', 'array'],
            'city_rules.mode' => ['required_with:city_rules', 'string', Rule::in(ServiceConnection::CITY_MATCH_MODES)],
            'city_rules.source_cities' => ['sometimes', 'array'],
            'city_rules.source_cities.*' => ['string'],
            'city_rules.target_cities' => ['sometimes', 'array'],
            'city_rules.target_cities.*' => ['string'],
            'notes' => ['nullable', 'string'],
        ])->validate();

        if ($validated['source_type'] === $validated['target_type']) {
            throw ValidationException::withMessages([
                'target_type' => ['The target type must be different from source type.'],
            ]);
        }

        $companyId = (int) $actor->companies()->value('companies.id');
        if ($companyId <= 0) {
            throw ValidationException::withMessages([
                'company_id' => ['The authenticated user has no company assigned.'],
            ]);
        }

        $targeting = $validated['targeting'] ?? [];
        $validated['client_targeting'] = $targeting['mode'] ?? ($validated['client_targeting'] ?? 'all');
        $selectedClientInput = $targeting['client_ids'] ?? ($validated['selected_client_ids'] ?? []);

        $validated['company_id'] = $companyId;
        $validated['status'] = 'pending';
        $validated['connection_type'] = $validated['connection_type'] ?? 'only';
        $validated['selected_client_ids'] = null;
        $validated['city_rules'] = null;
        $validated['status_history'] = [];

        if ($validated['client_targeting'] === 'selected') {
            $selectedClientIds = collect($selectedClientInput)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values()
                ->all();

            if ($selectedClientIds === []) {
                throw ValidationException::withMessages([
                    'selected_client_ids' => ['At least one client is required for selected targeting.'],
                ]);
            }

            $allowedClientIds = DB::table('user_companies')
                ->where('company_id', $companyId)
                ->whereIn('user_id', $selectedClientIds)
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();

            $missingClientIds = array_values(array_diff($selectedClientIds, $allowedClientIds));
            if ($missingClientIds !== []) {
                throw ValidationException::withMessages([
                    'selected_client_ids' => ['Some selected clients are not part of your company.'],
                ]);
            }

            $validated['selected_client_ids'] = $allowedClientIds;
        }

        if (config('service_connections.advanced_enabled', true) && array_key_exists('city_rules', $validated)) {
            $validated['city_rules'] = $this->normalizeCityRules(
                $validated['city_rules'],
                $validated['source_type'],
                (int) $validated['source_id'],
                $validated['target_type'],
                (int) $validated['target_id']
            );
        }

        $validated['status_history'] = [
            $this->makeHistoryEntry(
                from: null,
                to: 'pending',
                actor: $actor,
                notes: $validated['notes'] ?? null
            ),
        ];

        return ServiceConnection::query()->create($validated);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, ServiceConnection>
     */
    public function list(array $filters = []): Collection
    {
        $query = ServiceConnection::query();

        foreach (['source_type', 'target_type', 'status', 'company_id'] as $key) {
            if (! array_key_exists($key, $filters)) {
                continue;
            }

            $value = $filters[$key];
            if ($value === null || $value === '') {
                continue;
            }

            $query->where($key, $value);
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function findOrFail(int $id): ServiceConnection
    {
        $connection = ServiceConnection::query()->find($id);
        if ($connection === null) {
            throw new ModelNotFoundException('ServiceConnection not found.');
        }

        return $connection;
    }

    public function accept(ServiceConnection $connection, User $actor, ?string $notes = null): ServiceConnection
    {
        $notes = $this->normalizeOptionalNotes($notes);

        if ($connection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Connection cannot be accepted in its current state.'],
            ]);
        }

        $fromStatus = $connection->status;
        $connection->status = 'accepted';
        if ($notes !== null) {
            $connection->notes = $connection->notes
                ? $connection->notes.PHP_EOL.$notes
                : $notes;
        }
        $this->appendStatusHistory($connection, $actor, $fromStatus, 'accepted', $notes);

        $connection->save();
        $fresh = $connection->fresh();

        if ($fresh instanceof ServiceConnection) {
            event(new ConnectionAccepted($fresh));

            return $fresh;
        }

        return $connection;
    }

    public function reject(ServiceConnection $connection, User $actor, ?string $notes = null): ServiceConnection
    {
        $notes = $this->normalizeOptionalNotes($notes);

        if ($connection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Connection cannot be rejected in its current state.'],
            ]);
        }

        if (config('service_connections.require_notes_for_reject', true) && $notes === null) {
            throw ValidationException::withMessages([
                'notes' => ['Notes are required when rejecting a connection.'],
            ]);
        }

        $fromStatus = $connection->status;
        $connection->status = 'rejected';
        if ($notes !== null) {
            $connection->notes = $connection->notes
                ? $connection->notes.PHP_EOL.$notes
                : $notes;
        }
        $this->appendStatusHistory($connection, $actor, $fromStatus, 'rejected', $notes);

        $connection->save();
        $fresh = $connection->fresh();

        if ($fresh instanceof ServiceConnection) {
            event(new ConnectionRejected($fresh));

            return $fresh;
        }

        return $connection;
    }

    public function cancel(ServiceConnection $connection, User $actor): ServiceConnection
    {
        return $this->cancelWithNotes($connection, $actor, null);
    }

    public function cancelWithNotes(ServiceConnection $connection, User $actor, ?string $notes = null): ServiceConnection
    {
        $notes = $this->normalizeOptionalNotes($notes);

        if (! in_array($connection->status, ['pending', 'accepted'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Connection cannot be canceled in its current state.'],
            ]);
        }

        if (config('service_connections.require_notes_for_cancel', false) && $notes === null) {
            throw ValidationException::withMessages([
                'notes' => ['Notes are required when canceling a connection.'],
            ]);
        }

        $fromStatus = $connection->status;
        $connection->status = 'canceled';
        if ($notes !== null) {
            $connection->notes = $connection->notes
                ? $connection->notes.PHP_EOL.$notes
                : $notes;
        }
        $this->appendStatusHistory($connection, $actor, $fromStatus, 'canceled', $notes);
        $connection->save();

        return $connection->fresh() ?? $connection;
    }

    /**
     * @param  array<string, mixed>  $cityRules
     * @return array{mode:string,source_cities:list<string>,target_cities:list<string>}
     */
    private function normalizeCityRules(
        array $cityRules,
        string $sourceType,
        int $sourceId,
        string $targetType,
        int $targetId
    ): array {
        $sourceCandidates = $this->collectEntityCities($sourceType, $sourceId);
        $targetCandidates = $this->collectEntityCities($targetType, $targetId);

        $sourceCities = collect($cityRules['source_cities'] ?? [])
            ->map(fn ($city): string => trim((string) $city))
            ->filter(fn (string $city): bool => $city !== '')
            ->unique(fn (string $city): string => mb_strtolower($city))
            ->values()
            ->all();

        $targetCities = collect($cityRules['target_cities'] ?? [])
            ->map(fn ($city): string => trim((string) $city))
            ->filter(fn (string $city): bool => $city !== '')
            ->unique(fn (string $city): string => mb_strtolower($city))
            ->values()
            ->all();

        if ($sourceCities !== [] && ! $this->containsAllCities($sourceCandidates, $sourceCities)) {
            throw ValidationException::withMessages([
                'city_rules.source_cities' => ['Source cities do not match the selected source entity.'],
            ]);
        }

        if ($targetCities !== [] && ! $this->containsAllCities($targetCandidates, $targetCities)) {
            throw ValidationException::withMessages([
                'city_rules.target_cities' => ['Target cities do not match the selected target entity.'],
            ]);
        }

        if ($sourceCities === [] && $targetCities === []) {
            throw ValidationException::withMessages([
                'city_rules' => ['At least one city must be provided when city_rules are used.'],
            ]);
        }

        return [
            'mode' => (string) $cityRules['mode'],
            'source_cities' => $sourceCities,
            'target_cities' => $targetCities,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectEntityCities(string $type, int $id): array
    {
        if ($type === 'flight') {
            $flight = Flight::query()->find($id);
            if ($flight === null) {
                return [];
            }

            return collect([$flight->departure_city, $flight->arrival_city])
                ->filter(fn ($city): bool => is_string($city) && trim($city) !== '')
                ->map(fn (string $city): string => trim($city))
                ->unique(fn (string $city): string => mb_strtolower($city))
                ->values()
                ->all();
        }

        if ($type === 'hotel') {
            $hotel = Hotel::query()->find($id);
            if ($hotel === null || ! is_string($hotel->city) || trim($hotel->city) === '') {
                return [];
            }

            return [trim($hotel->city)];
        }

        if ($type === 'transfer') {
            $transfer = Transfer::query()->find($id);
            if ($transfer === null) {
                return [];
            }

            return collect([$transfer->pickup_city, $transfer->dropoff_city])
                ->filter(fn ($city): bool => is_string($city) && trim($city) !== '')
                ->map(fn (string $city): string => trim($city))
                ->unique(fn (string $city): string => mb_strtolower($city))
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $expected
     */
    private function containsAllCities(array $existing, array $expected): bool
    {
        $normalizedExisting = collect($existing)
            ->map(fn (string $city): string => mb_strtolower(trim($city)))
            ->values()
            ->all();

        foreach ($expected as $city) {
            if (! in_array(mb_strtolower(trim($city)), $normalizedExisting, true)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeOptionalNotes(?string $notes): ?string
    {
        if ($notes === null) {
            return null;
        }

        $trimmed = trim($notes);

        return $trimmed === '' ? null : $trimmed;
    }

    private function appendStatusHistory(ServiceConnection $connection, User $actor, ?string $fromStatus, string $toStatus, ?string $notes = null): void
    {
        $history = is_array($connection->status_history) ? $connection->status_history : [];
        $history[] = $this->makeHistoryEntry($fromStatus, $toStatus, $actor, $notes);
        $connection->status_history = $history;
    }

    /**
     * @return array<string, mixed>
     */
    private function makeHistoryEntry(?string $from, string $to, User $actor, ?string $notes = null): array
    {
        return [
            'from' => $from,
            'to' => $to,
            'actor_id' => (int) $actor->id,
            'at' => now()->toIso8601String(),
            'notes' => $notes,
        ];
    }
}
