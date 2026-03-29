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
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return Offer::query()
            ->whereIn('company_id', $companyIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        $query = Offer::query()->orderBy('id');

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1')->paginate($perPage);
        }

        return $query
            ->whereIn('company_id', $companyIds)
            ->paginate($perPage);
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

        return $offer->fresh();
    }

    public function archive(Offer $offer): Offer
    {
        $offer->status = Offer::STATUS_ARCHIVED;
        $offer->save();

        return $offer->fresh();
    }

    public function setStatus(Offer $offer, string $status): Offer
    {
        $offer->status = $status;
        $offer->save();

        return $offer;
    }
}
