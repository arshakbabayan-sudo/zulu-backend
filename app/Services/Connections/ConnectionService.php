<?php

namespace App\Services\Connections;

use App\Events\ConnectionAccepted;
use App\Events\ConnectionRejected;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $validated['company_id'] = $companyId;
        $validated['status'] = 'pending';
        $validated['connection_type'] = $validated['connection_type'] ?? 'only';
        $validated['client_targeting'] = $validated['client_targeting'] ?? 'all';

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
        if ($connection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Connection cannot be accepted in its current state.'],
            ]);
        }

        $connection->status = 'accepted';
        if ($notes !== null && $notes !== '') {
            $connection->notes = $connection->notes
                ? $connection->notes.PHP_EOL.$notes
                : $notes;
        }

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
        if ($connection->status !== 'pending') {
            throw ValidationException::withMessages([
                'status' => ['Connection cannot be rejected in its current state.'],
            ]);
        }

        $connection->status = 'rejected';
        if ($notes !== null && $notes !== '') {
            $connection->notes = $connection->notes
                ? $connection->notes.PHP_EOL.$notes
                : $notes;
        }

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
        if (! in_array($connection->status, ['pending', 'accepted'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Connection cannot be canceled in its current state.'],
            ]);
        }

        $connection->status = 'canceled';
        $connection->save();

        return $connection->fresh() ?? $connection;
    }
}
