<?php

namespace App\Services\Cars;

use App\Models\Car;
use App\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CarService
{
    /**
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'company_id',
        'vehicle_class',
    ];

    /**
     * @return array<string, mixed>
     */
    public function listingFiltersFromRequest(Request $request): array
    {
        $filters = [];
        foreach (self::LISTING_FILTER_KEYS as $key) {
            if ($request->query->has($key)) {
                $filters[$key] = $request->query($key);
            }
        }

        return $filters;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Car>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->baseTenantCarQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultListOrdering($query);

        return $query->with(['offer'])->get();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function paginateForCompanies(array $companyIds, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->baseTenantCarQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultListOrdering($query);

        return $query->with(['offer'])->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Car
    {
        if ($companyIds === []) {
            return null;
        }

        return $this->baseTenantCarQuery($companyIds)
            ->whereKey($id)
            ->with(['offer'])
            ->first();
    }

    public function findByIdWithCarOffer(int|string $id): ?Car
    {
        return Car::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'car');
            })
            ->with(['offer'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data  Must include offer_id; company_id used only to validate offer ownership (not stored on cars).
     */
    public function create(array $data): Car
    {
        $offer = Offer::query()->findOrFail((int) ($data['offer_id'] ?? 0));

        if ($offer->type !== 'car') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type car.'],
            ]);
        }

        if (isset($data['company_id']) && (int) $data['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        if (Car::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['A car already exists for this offer.'],
            ]);
        }

        $fillable = (new Car)->getFillable();
        $payload = Arr::only($data, $fillable);

        return DB::transaction(fn () => Car::query()->create($payload));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Car $car, array $data): Car
    {
        $fillable = (new Car)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        return DB::transaction(function () use ($car, $data): Car {
            $car->fill($data);
            $car->save();

            return $car->refresh();
        });
    }

    public function delete(Car $car): void
    {
        DB::transaction(fn () => $car->delete());
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function baseTenantCarQuery(array $companyIds): Builder
    {
        $query = Car::query();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('offer', function (Builder $q) use ($companyIds): void {
            $q->where('type', 'car')
                ->whereIn('company_id', $companyIds);
        });
    }

    private function applyDefaultListOrdering(Builder $query): void
    {
        $table = $query->getModel()->getTable();
        $query->orderBy($table.'.id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyListingFilters(Builder $query, array $filters): void
    {
        if ($filters === []) {
            return;
        }

        if (array_key_exists('company_id', $filters) && $filters['company_id'] !== null && $filters['company_id'] !== '') {
            $companyId = (int) $filters['company_id'];
            $query->whereHas('offer', function (Builder $q) use ($companyId): void {
                $q->where('company_id', $companyId);
            });
        }

        if (array_key_exists('vehicle_class', $filters)) {
            $value = $filters['vehicle_class'];
            if ($value !== null && $value !== '' && (is_string($value) || is_numeric($value))) {
                $table = $query->getModel()->getTable();
                $query->where($table.'.vehicle_class', (string) $value);
            }
        }
    }
}
