<?php

namespace App\Services\Availability;

use App\Models\BlockedDate;
use App\Models\Car;
use App\Models\Excursion;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Offer;
use App\Models\Package;
use App\Models\Transfer;
use App\Models\Visa;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Roadmap §4 — booking-time enforcement for operator "Block dates"
 * (Phase 7.2 `blocked_dates` table).
 *
 * A booking is refused when its requested [start, end] window intersects
 * a BlockedDate row for the same company and item type. A row with a
 * NULL item_id blocks the WHOLE item type for that company; otherwise
 * only the matching item id is blocked.
 *
 * Every entry point is a deliberate no-op when the company or dates are
 * unknown so flows without dates are never broken.
 */
class BlockedDateChecker
{
    public function assertNotBlocked(int $companyId, string $itemType, ?int $itemId, ?string $startDate, ?string $endDate): void
    {
        $start = $this->normalizeDate($startDate);
        if ($companyId <= 0 || $itemType === '' || $start === null) {
            return;
        }
        $end = $this->normalizeDate($endDate) ?? $start;

        $blocked = BlockedDate::query()
            ->where('company_id', $companyId)
            ->where('item_type', $itemType)
            ->where(function ($q) use ($itemId) {
                // NULL item_id = the whole item type is blocked.
                $q->whereNull('item_id');
                if ($itemId !== null) {
                    $q->orWhere('item_id', $itemId);
                }
            })
            ->where('blocked_from', '<=', $end)
            ->where('blocked_to', '>=', $start)
            ->orderBy('blocked_from')
            ->first();

        if ($blocked !== null) {
            throw ValidationException::withMessages([
                'items' => [
                    sprintf(
                        'Item is blocked from %s to %s%s',
                        $blocked->blocked_from->format('Y-m-d'),
                        $blocked->blocked_to->format('Y-m-d'),
                        $blocked->reason ? ' ('.$blocked->reason.')' : ''
                    ),
                ],
            ]);
        }
    }

    /**
     * Offer-centric check: refuses when either the offer itself
     * (item_type=offer) or the vertical item behind it (hotel/flight/…)
     * is blocked for the requested window.
     */
    public function assertOfferNotBlocked(Offer $offer, ?string $startDate, ?string $endDate): void
    {
        $companyId = (int) ($offer->company_id ?? 0);
        if ($companyId <= 0) {
            return;
        }

        $this->assertNotBlocked($companyId, 'offer', (int) $offer->id, $startDate, $endDate);

        $itemType = (string) $offer->type;
        if ($itemType !== '' && $itemType !== 'offer' && in_array($itemType, BlockedDate::ITEM_TYPES, true)) {
            $this->assertNotBlocked(
                $companyId,
                $itemType,
                $this->resolveOfferVerticalId($offer, $itemType),
                $startDate,
                $endDate
            );
        }
    }

    /**
     * Order-item-centric check (cart add / checkout): resolves the owning
     * company from the vertical row (directly, or through its offer).
     * Unknown items, types without blockable inventory (e.g. insurance)
     * and date-less items are skipped.
     */
    public function assertOrderItemNotBlocked(?string $itemType, int|string|null $itemId, ?string $startDate, ?string $endDate): void
    {
        if ($itemType === null || $itemType === '' || $itemId === null || ! is_numeric($itemId)) {
            return;
        }
        if ($this->normalizeDate($startDate) === null) {
            return;
        }

        $modelClass = match ($itemType) {
            'hotel' => Hotel::class,
            'flight' => Flight::class,
            'car' => Car::class,
            'transfer' => Transfer::class,
            'excursion' => Excursion::class,
            'visa' => Visa::class,
            'package' => Package::class,
            default => null,
        };
        if ($modelClass === null) {
            return;
        }

        $row = $modelClass::query()->find((int) $itemId);
        if ($row === null) {
            return;
        }

        $companyId = (int) ($row->company_id ?? 0);
        if ($companyId <= 0) {
            $companyId = (int) ($row->offer?->company_id ?? 0);
        }
        if ($companyId <= 0) {
            return;
        }

        $this->assertNotBlocked($companyId, $itemType, (int) $itemId, $startDate, $endDate);
    }

    /**
     * For per-vertical items (hotel/flight/etc.) the blocked row's item_id
     * is the typed-row id behind the offer — resolved from the direct
     * column when present, otherwise through the HasOne relation.
     */
    private function resolveOfferVerticalId(Offer $offer, string $itemType): ?int
    {
        $relation = match ($itemType) {
            'hotel', 'flight', 'car', 'transfer', 'excursion', 'visa', 'package' => $itemType,
            default => null,
        };
        if ($relation === null) {
            return null;
        }

        $directId = $offer->{$relation.'_id'} ?? null;
        if (is_numeric($directId)) {
            return (int) $directId;
        }

        if (method_exists($offer, $relation)) {
            $offer->loadMissing($relation);
            $related = $offer->getRelation($relation);
            if ($related !== null && isset($related->id)) {
                return (int) $related->id;
            }
        }

        return null;
    }

    private function normalizeDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return Carbon::parse($date)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }
}
