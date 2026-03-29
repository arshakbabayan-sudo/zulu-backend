<?php

namespace App\Services\Excursions;

use App\Models\Excursion;
use App\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExcursionService
{
    /**
     * @var list<string>
     */
    public const LISTING_FILTER_KEYS = [
        'company_id',
        'location',
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
     * @return Collection<int, Excursion>
     */
    public function listForCompanies(array $companyIds, array $filters = []): Collection
    {
        $query = $this->baseTenantExcursionQuery($companyIds);
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
        $query = $this->baseTenantExcursionQuery($companyIds);
        $this->applyListingFilters($query, $filters);
        $this->applyDefaultListOrdering($query);

        return $query->with(['offer'])->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function findForCompanyScope(int|string $id, array $companyIds): ?Excursion
    {
        if ($companyIds === []) {
            return null;
        }

        return $this->baseTenantExcursionQuery($companyIds)
            ->whereKey($id)
            ->with(['offer'])
            ->first();
    }

    public function findByIdWithExcursionOffer(int|string $id): ?Excursion
    {
        return Excursion::query()
            ->whereKey($id)
            ->whereHas('offer', function (Builder $q): void {
                $q->where('type', 'excursion');
            })
            ->with(['offer'])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Excursion
    {
        $offer = Offer::query()->findOrFail((int) ($data['offer_id'] ?? 0));

        if ($offer->type !== 'excursion') {
            throw ValidationException::withMessages([
                'offer_id' => ['Offer must be of type excursion.'],
            ]);
        }

        if (isset($data['company_id']) && (int) $data['company_id'] !== (int) $offer->company_id) {
            throw ValidationException::withMessages([
                'company_id' => ['Company must match the offer company.'],
            ]);
        }

        if (Excursion::query()->where('offer_id', $offer->id)->exists()) {
            throw ValidationException::withMessages([
                'offer_id' => ['An excursion already exists for this offer.'],
            ]);
        }

        $fillable = (new Excursion)->getFillable();
        $payload = Arr::only($data, $fillable);

        return DB::transaction(fn () => Excursion::query()->create($payload));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Excursion $excursion, array $data): Excursion
    {
        $fillable = (new Excursion)->getFillable();
        $data = Arr::only($data, $fillable);
        unset($data['offer_id']);

        if ($data === []) {
            throw ValidationException::withMessages([
                '' => ['No updatable fields provided.'],
            ]);
        }

        return DB::transaction(function () use ($excursion, $data): Excursion {
            $excursion->fill($data);
            $excursion->save();

            return $excursion->refresh();
        });
    }

    public function delete(Excursion $excursion): void
    {
        DB::transaction(fn () => $excursion->delete());
    }

    /**
     * @param  list<int>  $companyIds
     */
    private function baseTenantExcursionQuery(array $companyIds): Builder
    {
        $query = Excursion::query();
        if ($companyIds === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereHas('offer', function (Builder $q) use ($companyIds): void {
            $q->where('type', 'excursion')
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

        if (array_key_exists('location', $filters)) {
            $value = $filters['location'];
            if ($value !== null && $value !== '' && (is_string($value) || is_numeric($value))) {
                $table = $query->getModel()->getTable();
                $query->where($table.'.location', 'like', '%'.addcslashes((string) $value, '%_\\').'%');
            }
        }
    }
}
