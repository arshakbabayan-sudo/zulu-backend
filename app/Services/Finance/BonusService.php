<?php

namespace App\Services\Finance;

use App\Models\Bonus;
use App\Models\Company;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BonusService
{
    public function calculateAndRecordBonus(Invoice $invoice): ?Bonus
    {
        if ((string) $invoice->status !== Invoice::STATUS_PAID) {
            return null;
        }

        $invoice->loadMissing(['booking.items.offer', 'booking.company']);

        $companyId = (int) ($invoice->booking?->company_id ?? 0);
        if ($companyId <= 0) {
            return null;
        }

        $existing = Bonus::query()->where('invoice_id', $invoice->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $percent = $this->resolveCompanyBonusPercent($invoice->booking?->company);
        if ($percent <= 0) {
            return null;
        }

        $baseAmount = (string) ($invoice->total_amount ?? '0');
        $bonusAmount = bcmul($baseAmount, bcdiv((string) $percent, '100', 6), 2);
        if (bccomp($bonusAmount, '0', 2) <= 0) {
            return null;
        }

        $bookingId = (int) ($invoice->booking_id ?? 0);
        $reference = (string) ($invoice->unique_booking_reference ?? ('#'.$bookingId));

        return Bonus::query()->create([
            'company_id' => $companyId,
            'booking_id' => $bookingId > 0 ? $bookingId : null,
            'invoice_id' => (int) $invoice->id,
            'amount' => $bonusAmount,
            'status' => Bonus::STATUS_PENDING,
            'description' => 'Bonus for Booking '.$reference,
        ]);
    }

    public function makeBonusAvailable(int $bonusId): bool
    {
        return Bonus::query()
            ->where('id', $bonusId)
            ->where('status', Bonus::STATUS_PENDING)
            ->update(['status' => Bonus::STATUS_AVAILABLE]) > 0;
    }

    /**
     * @return array{total_earned: float, total_available: float, total_redeemed: float}
     */
    public function getCompanyBonusSummary(int $companyId): array
    {
        $cid = max(0, $companyId);

        return [
            'total_earned' => (float) (Bonus::query()->where('company_id', $cid)->sum('amount') ?? 0),
            'total_available' => (float) (Bonus::query()
                ->where('company_id', $cid)
                ->where('status', Bonus::STATUS_AVAILABLE)
                ->sum('amount') ?? 0),
            'total_redeemed' => (float) (Bonus::query()
                ->where('company_id', $cid)
                ->where('status', Bonus::STATUS_REDEEMED)
                ->sum('amount') ?? 0),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getCompanySalesRows(int $companyId, int $limit = 100): array
    {
        $rows = DB::table('bonuses')
            ->leftJoin('bookings', 'bookings.id', '=', 'bonuses.booking_id')
            ->leftJoin('booking_items', 'booking_items.booking_id', '=', 'bookings.id')
            ->leftJoin('offers', 'offers.id', '=', 'booking_items.offer_id')
            ->select([
                'bonuses.id',
                'bonuses.status',
                'bonuses.amount as bonus_amount',
                'bonuses.created_at',
                'bonuses.booking_id',
                'offers.type as service_type',
                'bookings.total_price as sale_amount',
            ])
            ->where('bonuses.company_id', $companyId)
            ->groupBy(
                'bonuses.id',
                'bonuses.status',
                'bonuses.amount',
                'bonuses.created_at',
                'bonuses.booking_id',
                'offers.type',
                'bookings.total_price'
            )
            ->orderByDesc('bonuses.id')
            ->limit(max(1, min($limit, 500)))
            ->get();

        return $rows->map(function (object $row): array {
            return [
                'id' => (int) $row->id,
                'date' => $row->created_at,
                'booking_id' => $row->booking_id !== null ? (int) $row->booking_id : null,
                'service_type' => (string) ($row->service_type ?? 'unknown'),
                'sale_amount' => (float) ($row->sale_amount ?? 0),
                'bonus_earned' => (float) ($row->bonus_amount ?? 0),
                'status' => (string) $row->status,
            ];
        })->all();
    }

    private function resolveCompanyBonusPercent(?Company $company): float
    {
        if ($company === null) {
            return 0.0;
        }

        // If a company-specific percent is stored, use it.
        if (Schema::hasColumn('companies', 'bonus_percent') && is_numeric($company->bonus_percent ?? null)) {
            return (float) $company->bonus_percent;
        }

        if (Schema::hasColumn('companies', 'bonus_rate_percent') && is_numeric($company->bonus_rate_percent ?? null)) {
            return (float) $company->bonus_rate_percent;
        }

        return (float) config('zulu_platform.finance.default_bonus_percent', 1.0);
    }
}
