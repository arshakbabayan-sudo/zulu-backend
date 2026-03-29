<?php

namespace App\Services\Packages;

use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Package;
use App\Models\PackageComponent;
use App\Models\Transfer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PackageService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Package
    {
        $offer = Offer::query()->findOrFail($data['offer_id'] ?? 0);

        if ($offer->type !== 'package') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type package.'],
            ]);
        }

        if (Package::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['A package already exists for this offer.'],
            ]);
        }

        if (array_key_exists('company_id', $data) && (int) $data['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        $data['company_id'] = $offer->company_id;

        foreach ($this->packageCreateDefaults() as $key => $value) {
            if (! array_key_exists($key, $data)) {
                $data[$key] = $value;
            }
        }

        $rules = $this->packageCreateValidationRules();
        $attrs = Validator::make($data, $rules)->validate();

        return DB::transaction(function () use ($attrs) {
            return Package::query()->create(
                Arr::only($attrs, (new Package)->getFillable())
            );
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Package $package, array $data): Package
    {
        Validator::make($data, [
            'offer_id' => ['prohibited'],
            'company_id' => ['prohibited'],
        ])->validate();

        $rules = $this->packageUpdateValidationRules();
        $attrs = Validator::make($data, $rules)->validate();

        if ($attrs === []) {
            return $package->fresh();
        }

        $package->fill(Arr::only($attrs, (new Package)->getFillable()));
        $package->save();

        return $package->fresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addComponent(Package $package, array $data): PackageComponent
    {
        $rules = [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'module_type' => ['required', 'string', 'in:'.implode(',', PackageComponent::MODULE_TYPES)],
            'package_role' => ['required', 'string', 'in:'.implode(',', PackageComponent::PACKAGE_ROLES)],
            'is_required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'selection_mode' => ['sometimes', 'string', 'in:'.implode(',', PackageComponent::SELECTION_MODES)],
            'price_override' => ['nullable', 'numeric', 'min:0'],
        ];

        $payload = Validator::make($data, $rules)->validate();

        $offer = Offer::query()->findOrFail((int) $payload['offer_id']);

        if ($offer->type === 'package') {
            throw ValidationException::withMessages([
                'offer_id' => ['A package offer cannot be added as a component.'],
            ]);
        }

        if ($offer->type !== $payload['module_type']) {
            throw ValidationException::withMessages([
                'module_type' => ['module_type must match the offer type.'],
            ]);
        }

        if ((int) $offer->company_id !== (int) $package->company_id) {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must belong to the same company as the package.'],
            ]);
        }

        $this->assertOfferIsPackageEligibleOnModule($offer);

        if (PackageComponent::query()
            ->where('package_id', $package->id)
            ->where('offer_id', $offer->id)
            ->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['This offer is already a component of the package.'],
            ]);
        }

        foreach ($this->componentCreateDefaults() as $key => $value) {
            if (! array_key_exists($key, $payload)) {
                $payload[$key] = $value;
            }
        }

        return DB::transaction(function () use ($package, $payload) {
            return $package->components()->create(
                Arr::only($payload, (new PackageComponent)->getFillable())
            );
        });
    }

    public function removeComponent(Package $package, int $componentId): void
    {
        PackageComponent::query()
            ->where('package_id', $package->id)
            ->whereKey($componentId)
            ->delete();
    }

    /**
     * @param  list<int>  $orderedComponentIds
     */
    public function reorderComponents(Package $package, array $orderedComponentIds): void
    {
        $ids = array_map('intval', $orderedComponentIds);
        $existingIds = $package->components()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        sort($ids);

        if ($existingIds !== $ids) {
            throw ValidationException::withMessages([
                'ordered_component_ids' => ['Must include every component id for this package exactly once.'],
            ]);
        }

        DB::transaction(function () use ($package, $orderedComponentIds): void {
            foreach (array_values($orderedComponentIds) as $index => $componentId) {
                PackageComponent::query()
                    ->where('package_id', $package->id)
                    ->whereKey((int) $componentId)
                    ->update(['sort_order' => $index]);
            }
        });
    }

    public function calculateBasePrice(Package $package): float
    {
        $package->loadMissing(['components.offer']);

        $sum = 0.0;
        foreach ($package->components as $component) {
            if ($component->price_override !== null) {
                $sum += (float) $component->price_override;
            } elseif ($component->offer !== null) {
                $sum += (float) $component->offer->price;
            }
        }

        return round($sum, 2);
    }

    /**
     * @return array{
     *     items: list<array{
     *         component_id: int,
     *         module_type: string,
     *         package_role: string,
     *         price: float,
     *         is_override: bool,
     *         offer_id: int
     *     }>,
     *     total: float,
     *     currency: string,
     *     display_mode: string,
     *     adults_count: int,
     *     per_person: float
     * }
     */
    public function composePricing(Package $package): array
    {
        $package->loadMissing(['components.offer']);

        $items = [];
        $total = 0.0;

        foreach ($package->components as $component) {
            $price = $component->price_override !== null
                ? (float) $component->price_override
                : (float) ($component->offer?->price ?? 0);

            $items[] = [
                'component_id' => $component->id,
                'module_type' => $component->module_type,
                'package_role' => $component->package_role,
                'price' => $price,
                'is_override' => $component->price_override !== null,
                'offer_id' => $component->offer_id,
            ];

            $total += $price;
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
            'currency' => $package->currency ?? 'USD',
            'display_mode' => $package->display_price_mode ?? 'total',
            'adults_count' => $package->adults_count ?? 1,
            'per_person' => $package->adults_count > 0
                ? round($total / (int) $package->adults_count, 2)
                : round($total, 2),
        ];
    }

    public function activate(Package $package): Package
    {
        $compatibility = $this->validateComponentCompatibility($package);
        if (! $compatibility['valid']) {
            throw ValidationException::withMessages([
                'status' => [$compatibility['errors'][0] ?? 'Package is not valid for activation.'],
            ]);
        }

        $package->load(['components.offer']);

        $required = $package->components->where('is_required', true);
        if ($required->isEmpty()) {
            throw ValidationException::withMessages([
                'status' => ['Package must have at least one required component before activation.'],
            ]);
        }

        foreach ($required as $component) {
            if ($component->offer === null || $component->offer->status !== Offer::STATUS_PUBLISHED) {
                throw ValidationException::withMessages([
                    'status' => ['All required components must reference published offers.'],
                ]);
            }
        }

        $this->assertPackageTransitionAllowed($package, 'active');

        $package->status = 'active';
        $package->is_public = true;
        $package->save();

        return $package->fresh();
    }

    public function deactivate(Package $package, ?string $reason = null): Package
    {
        $this->assertPackageTransitionAllowed($package, 'inactive');

        $package->status = 'inactive';
        $package->is_public = false;
        $package->is_bookable = false;
        $package->save();

        return $package->fresh();
    }

    /**
     * @return array{errors: list<string>, valid: bool}
     */
    public function validateComponentCompatibility(Package $package): array
    {
        $package->loadMissing('components');

        $errors = [];
        $components = $package->components;

        if ($components->isEmpty()) {
            $errors[] = 'Package must have at least 1 component to be activated.';
        }

        $serviceTypeCounts = $components
            ->map(fn ($c) => $c->service_type ?: $c->module_type)
            ->filter(fn ($type) => is_string($type) && $type !== '')
            ->countBy();

        foreach ($serviceTypeCounts as $serviceType => $count) {
            if ($serviceType !== 'hotel' && (int) $count > 1) {
                $errors[] = sprintf('Package cannot contain more than one "%s" component.', $serviceType);
            }
        }

        return [
            'errors' => array_values($errors),
            'valid' => $errors === [],
        ];
    }

    public function updatePopularityScore(Package $package, int $delta = 1): void
    {
        $package->popularity_score = ((int) ($package->popularity_score ?? 0)) + $delta;
        $package->saveQuietly();
    }

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, Package>
     */
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Package::query()
            ->whereIn('company_id', $companyIds)
            ->with(['offer'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        $query = Package::query()
            ->with(['offer'])
            ->orderBy('id');

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1')->paginate($perPage);
        }

        return $query->whereIn('company_id', $companyIds)->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Package
    {
        if ($companyIds === []) {
            return null;
        }

        return Package::query()
            ->whereIn('company_id', $companyIds)
            ->whereKey($id)
            ->with(['offer', 'components.offer'])
            ->first();
    }

    public function findByIdWithPackageOffer(int|string $id): ?Package
    {
        return Package::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'package');
            })
            ->first();
    }

    /**
     * Active, public package for storefront detail / pricing (no company scope).
     */
    public function findPublicForStorefront(int|string $id): ?Package
    {
        return Package::query()
            ->whereKey($id)
            ->where('status', 'active')
            ->where('is_public', true)
            ->with(['offer', 'components.offer'])
            ->first();
    }

    public function delete(Package $package): void
    {
        $package->delete();
    }

    private function assertPackageTransitionAllowed(Package $package, string $targetStatus): void
    {
        /** @var array<string, list<string>> $allowed */
        $allowed = [
            'draft' => ['active', 'archived'],
            'active' => ['inactive', 'archived'],
            'inactive' => ['active', 'archived', 'draft'],
            'archived' => [],
        ];

        $current = $package->status;
        $next = $allowed[$current] ?? [];

        if (! in_array($targetStatus, $next, true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot transition package from {$current} to {$targetStatus}."],
            ]);
        }
    }

    private function assertOfferIsPackageEligibleOnModule(Offer $offer): void
    {
        if ($offer->type === 'flight') {
            $row = Flight::query()->where('offer_id', $offer->id)->first();
            if ($row === null || ! $row->is_package_eligible) {
                throw ValidationException::withMessages([
                    'offer_id' => ['Flight offer must exist and be package-eligible.'],
                ]);
            }
            if ($row->appears_in_packages === false) {
                throw ValidationException::withMessages([
                    'offer_id' => ['This service is not enabled for package inclusion.'],
                ]);
            }

            return;
        }

        if ($offer->type === 'hotel') {
            $row = Hotel::query()->where('offer_id', $offer->id)->first();
            if ($row === null || ! $row->is_package_eligible) {
                throw ValidationException::withMessages([
                    'offer_id' => ['Hotel offer must exist and be package-eligible.'],
                ]);
            }
            if ($row->appears_in_packages === false) {
                throw ValidationException::withMessages([
                    'offer_id' => ['This service is not enabled for package inclusion.'],
                ]);
            }

            return;
        }

        if ($offer->type === 'transfer') {
            $row = Transfer::query()->where('offer_id', $offer->id)->first();
            if ($row === null || ! $row->is_package_eligible) {
                throw ValidationException::withMessages([
                    'offer_id' => ['Transfer offer must exist and be package-eligible.'],
                ]);
            }
            if ($row->appears_in_packages === false) {
                throw ValidationException::withMessages([
                    'offer_id' => ['This service is not enabled for package inclusion.'],
                ]);
            }

            return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function packageCreateDefaults(): array
    {
        return [
            'children_count' => 0,
            'infants_count' => 0,
            'display_price_mode' => 'total',
            'is_public' => false,
            'is_bookable' => false,
            'is_package_eligible' => true,
            'status' => 'draft',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function componentCreateDefaults(): array
    {
        return [
            'is_required' => true,
            'sort_order' => 0,
            'selection_mode' => 'fixed',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageCreateValidationRules(): array
    {
        return [
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'package_type' => ['required', 'string', 'max:32', 'in:'.implode(',', Package::PACKAGE_TYPES)],
            'package_title' => ['nullable', 'string', 'max:255'],
            'package_subtitle' => ['nullable', 'string', 'max:255'],
            'destination_country' => ['nullable', 'string', 'max:255'],
            'destination_city' => ['nullable', 'string', 'max:255'],
            'duration_days' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'min_nights' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'adults_count' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'children_count' => ['integer', 'min:0', 'max:65535'],
            'infants_count' => ['integer', 'min:0', 'max:65535'],
            'base_price' => ['nullable', 'numeric', 'min:0'],
            'display_price_mode' => ['string', 'max:32', 'in:'.implode(',', Package::DISPLAY_PRICE_MODES)],
            'currency' => ['nullable', 'string', 'size:3'],
            'is_public' => ['boolean'],
            'is_bookable' => ['boolean'],
            'is_package_eligible' => ['boolean'],
            'status' => ['string', 'max:32', 'in:'.implode(',', Package::STATUSES)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function packageUpdateValidationRules(): array
    {
        return [
            'package_type' => ['sometimes', 'string', 'max:32', 'in:'.implode(',', Package::PACKAGE_TYPES)],
            'package_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'package_subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_country' => ['sometimes', 'nullable', 'string', 'max:255'],
            'destination_city' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'min_nights' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'adults_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:65535'],
            'children_count' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'infants_count' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'base_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'display_price_mode' => ['sometimes', 'string', 'max:32', 'in:'.implode(',', Package::DISPLAY_PRICE_MODES)],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
            'is_public' => ['sometimes', 'boolean'],
            'is_bookable' => ['sometimes', 'boolean'],
            'is_package_eligible' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'max:32', 'in:'.implode(',', Package::STATUSES)],
        ];
    }
}
