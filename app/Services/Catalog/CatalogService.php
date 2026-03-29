<?php

namespace App\Services\Catalog;

use App\Models\Offer;
use Illuminate\Database\Eloquent\Collection;

class CatalogService
{
    /** @var list<string> */
    public const PUBLIC_DETAIL_TYPES = Offer::ALLOWED_TYPES;

    /**
     * Published offers only; optional type filter (offer.type).
     *
     * @return Collection<int, Offer>
     */
    public function listPublishedOffers(?string $type = null): Collection
    {
        $query = Offer::query()
            ->where('status', Offer::STATUS_PUBLISHED)
            ->orderBy('id');

        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }

        return $query->get();
    }

    /**
     * Published offer with module relation loaded for public detail (all catalog detail types).
     */
    public function findPublishedOfferForPublicDetail(int $id): ?Offer
    {
        $offer = Offer::query()
            ->where('status', Offer::STATUS_PUBLISHED)
            ->whereKey($id)
            ->first();

        if ($offer === null) {
            return null;
        }

        if (! in_array($offer->type, self::PUBLIC_DETAIL_TYPES, true)) {
            return null;
        }

        $relation = match ($offer->type) {
            'flight' => 'flight',
            'hotel' => 'hotel',
            'transfer' => 'transfer',
            'car' => 'car',
            'excursion' => 'excursion',
            'package' => 'package',
            'visa' => 'visa',
            default => null,
        };

        if ($relation === null) {
            return null;
        }

        $offer->loadMissing($relation === 'flight' ? 'flight.cabins' : $relation);

        return $offer;
    }

    /**
     * Published offers of a type; flight/hotel/transfer rows must be package-eligible.
     * Other types: published only, no module eligibility predicate.
     *
     * @return Collection<int, Offer>
     */
    public function listPackageEligibleByType(string $moduleType): Collection
    {
        $relation = match ($moduleType) {
            'flight' => 'flight',
            'hotel' => 'hotel',
            'transfer' => 'transfer',
            'car' => 'car',
            'excursion' => 'excursion',
            'package' => 'package',
            'visa' => 'visa',
            default => null,
        };

        if ($relation === null) {
            return new Collection();
        }

        $query = Offer::query()
            ->where('status', Offer::STATUS_PUBLISHED)
            ->where('type', $moduleType)
            ->orderBy('id');

        if (in_array($moduleType, ['flight', 'hotel', 'transfer'], true)) {
            $query->whereHas($relation, fn ($q) => $q->where('is_package_eligible', true));
        }

        if ($relation !== null) {
            $query->with($relation);
        }

        return $query->get();
    }
}
