<?php

namespace App\Services\Offers;

use App\Models\Offer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class OfferService
{
    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, Offer>
     */
    public function listForCompanies(array $companyIds, ?string $type = null): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        $query = Offer::query()
            ->whereIn('company_id', $companyIds)
            ->select(['id', 'company_id', 'type', 'title', 'price', 'currency', 'status', 'created_at', 'updated_at'])
            ->orderBy('id');

        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20, ?string $type = null): LengthAwarePaginator
    {
        $query = Offer::query()
            ->select(['id', 'company_id', 'type', 'title', 'price', 'currency', 'status', 'created_at', 'updated_at'])
            ->orderBy('id');

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1')->paginate($perPage);
        }

        $query->whereIn('company_id', $companyIds);
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        return $query->paginate($perPage);
    }

    /**
     * Create a minimal core offer record.
     *
     * @param  array{
     *     company_id:int,
     *     type:string,
     *     title:string,
     *     price:numeric,
     *     currency:string,
     *     status?:string
     * }  $data
     */
    public function create(array $data): Offer
    {
        if (! isset($data['status'])) {
            $data['status'] = Offer::STATUS_DRAFT;
        }

        return Offer::query()->create($data);
    }

    public function publish(Offer $offer): Offer
    {
        $offer->status = Offer::STATUS_PUBLISHED;
        $offer->save();

        $module = $this->loadInventoryModule($offer);
        if ($module !== null && $module->status === 'draft') {
            $module->status = 'active';
            $module->save();
        }

        return $offer;
    }

    public function archive(Offer $offer): Offer
    {
        $offer->status = Offer::STATUS_ARCHIVED;
        $offer->save();

        $module = $this->loadInventoryModule($offer);
        if ($module !== null && $module->status !== 'archived') {
            $module->status = 'archived';
            $module->save();
        }

        return $offer;
    }

    private function loadInventoryModule(Offer $offer): ?\Illuminate\Database\Eloquent\Model
    {
        $relation = match ($offer->type) {
            'flight', 'hotel', 'transfer', 'car', 'excursion' => $offer->type,
            default => null,
        };

        if ($relation === null) {
            return null;
        }

        return $offer->{$relation};
    }

    public function setStatus(Offer $offer, string $status): Offer
    {
        $offer->status = $status;
        $offer->save();

        return $offer;
    }
}
