<?php

namespace App\Services\Finance;

use App\Models\Booking;
use App\Models\CommissionPolicy;
use App\Models\Company;
use App\Models\PackageOrder;
use App\Models\Settlement;
use App\Models\SupplierEntitlement;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    /**
     * @return list<SupplierEntitlement>
     */
    public function createEntitlementsForOrder(PackageOrder $order): array
    {
        try {
            return DB::transaction(function () use ($order) {
                $order->loadMissing(['items.offer']);

                $existing = SupplierEntitlement::query()
                    ->where('package_order_id', $order->id)
                    ->get();

                if ($existing->isNotEmpty()) {
                    return $existing->all();
                }

                /** @var list<SupplierEntitlement> $created */
                $created = [];

                foreach ($order->items as $item) {
                    $gross = bcadd((string) $item->price_snapshot, '0', 2);

                    $policy = CommissionPolicy::query()
                        ->where('company_id', $item->company_id)
                        ->where('service_type', $item->module_type)
                        ->where('status', 'active')
                        ->orderByDesc('id')
                        ->first();

                    $commission = '0';
                    if ($policy !== null) {
                        $commission = $this->commissionAmountForGross($policy, $gross);
                    }

                    $net = bcsub($gross, $commission, 2);

                    $created[] = SupplierEntitlement::query()->create([
                        'package_order_id' => $order->id,
                        'package_order_item_id' => $item->id,
                        'company_id' => $item->company_id,
                        'service_type' => $item->module_type,
                        'gross_amount' => $gross,
                        'commission_amount' => $commission,
                        'net_amount' => $net,
                        'currency' => (string) $item->currency_snapshot,
                        'status' => 'accrued',
                    ]);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            Log::warning('Entitlement creation failed for package order', [
                'package_order_id' => $order->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return list<SupplierEntitlement>
     */
    public function createEntitlementsForBooking(Booking $booking): array
    {
        try {
            return DB::transaction(function () use ($booking) {
                $booking->loadMissing(['items.offer', 'company']);

                $existing = SupplierEntitlement::query()
                    ->where('booking_id', $booking->id)
                    ->get();

                if ($existing->isNotEmpty()) {
                    return $existing->all();
                }

                /** @var list<SupplierEntitlement> $created */
                $created = [];

                foreach ($booking->items as $item) {
                    $gross = bcadd((string) $item->price, '0', 2);
                    $offerType = $item->offer?->type ?? 'unknown';

                    $policy = CommissionPolicy::query()
                        ->where('company_id', $booking->company_id)
                        ->where('service_type', $offerType)
                        ->where('status', 'active')
                        ->orderByDesc('id')
                        ->first();

                    $commission = '0';
                    if ($policy !== null) {
                        $commission = $this->commissionAmountForGross($policy, $gross);
                    }

                    $net = bcsub($gross, $commission, 2);

                    $created[] = SupplierEntitlement::query()->create([
                        'package_order_id' => null,
                        'package_order_item_id' => null,
                        'booking_id' => $booking->id,
                        'booking_item_id' => $item->id,
                        'company_id' => $booking->company_id,
                        'service_type' => $offerType,
                        'gross_amount' => $gross,
                        'commission_amount' => $commission,
                        'net_amount' => $net,
                        'currency' => $booking->currency ?? 'usd',
                        'status' => 'accrued',
                    ]);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            Log::warning('Entitlement creation failed for booking', [
                'booking_id' => $booking->id,
                'message' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getCompanyFinanceSummary(Company $company): array
    {
        $cid = (int) $company->id;

        $totalGross = (float) (DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->sum('gross_amount') ?? 0);

        $totalCommission = (float) (DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->sum('commission_amount') ?? 0);

        $totalNet = (float) (DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->sum('net_amount') ?? 0);

        $pendingAmount = (float) (DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->whereIn('status', ['pending', 'accrued'])
            ->sum('net_amount') ?? 0);

        $payableAmount = (float) (DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->where('status', 'payable')
            ->sum('net_amount') ?? 0);

        $settledAmount = (float) (DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->where('status', 'settled')
            ->sum('net_amount') ?? 0);

        $entitlementsCount = (int) DB::table('supplier_entitlements')
            ->where('company_id', $cid)
            ->count();

        $settlementsCount = (int) DB::table('settlements')
            ->where('company_id', $cid)
            ->count();

        $lastSettlementAt = DB::table('settlements')
            ->where('company_id', $cid)
            ->max('settled_at');

        $currencyRow = DB::table('supplier_entitlements')
            ->select('currency', DB::raw('COUNT(*) as cnt'))
            ->where('company_id', $cid)
            ->groupBy('currency')
            ->orderByDesc('cnt')
            ->orderBy('currency')
            ->first();

        $currency = $currencyRow !== null ? (string) $currencyRow->currency : null;

        return [
            'company_id' => $cid,
            'currency' => $currency,
            'total_gross_earned' => $totalGross,
            'total_commission_charged' => $totalCommission,
            'total_net_earned' => $totalNet,
            'pending_amount' => $pendingAmount,
            'payable_amount' => $payableAmount,
            'settled_amount' => $settledAmount,
            'entitlements_count' => $entitlementsCount,
            'settlements_count' => $settlementsCount,
            'last_settlement_at' => $lastSettlementAt !== null ? (string) $lastSettlementAt : null,
        ];
    }

    /**
     * @param  list<int>  $entitlementIds
     * @param  array{currency: string, period_label?: string|null, notes?: string|null}  $data
     */
    public function createSettlement(Company $company, User $actor, array $entitlementIds, array $data): Settlement
    {
        $currency = $data['currency'];
        $periodLabel = $data['period_label'] ?? null;
        $notes = $data['notes'] ?? null;

        return DB::transaction(function () use ($company, $entitlementIds, $currency, $periodLabel, $notes) {
            $ids = array_values(array_unique(array_map('intval', $entitlementIds)));

            $entitlements = SupplierEntitlement::query()
                ->whereIn('id', $ids)
                ->where('company_id', $company->id)
                ->where('status', 'payable')
                ->lockForUpdate()
                ->get();

            if ($entitlements->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'entitlement_ids' => ['One or more entitlements are invalid, not payable, or do not belong to this company.'],
                ]);
            }

            foreach ($entitlements as $ent) {
                if ($ent->currency !== $currency) {
                    throw ValidationException::withMessages([
                        'currency' => ['All entitlements must use the settlement currency.'],
                    ]);
                }
            }

            $totalGross = '0';
            $totalCommission = '0';
            $totalNet = '0';

            foreach ($entitlements as $ent) {
                $totalGross = bcadd($totalGross, bcadd((string) $ent->gross_amount, '0', 2), 2);
                $totalCommission = bcadd($totalCommission, bcadd((string) $ent->commission_amount, '0', 2), 2);
                $totalNet = bcadd($totalNet, bcadd((string) $ent->net_amount, '0', 2), 2);
            }

            /** @var Settlement $settlement */
            $settlement = Settlement::query()->create([
                'company_id' => $company->id,
                'currency' => $currency,
                'total_gross_amount' => $totalGross,
                'total_commission_amount' => $totalCommission,
                'total_net_amount' => $totalNet,
                'entitlements_count' => count($ids),
                'status' => 'pending',
                'period_label' => $periodLabel,
                'notes' => $notes,
            ]);

            SupplierEntitlement::query()
                ->whereIn('id', $ids)
                ->where('company_id', $company->id)
                ->where('status', 'payable')
                ->update([
                    'status' => 'settled',
                    'settlement_id' => $settlement->id,
                ]);

            return $settlement->fresh(['entitlements', 'company']);
        });
    }

    /**
     * @param  list<int>  $entitlementIds
     */
    public function markEntitlementsPayable(array $entitlementIds, Company $company): int
    {
        return SupplierEntitlement::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $entitlementIds)
            ->where('status', 'accrued')
            ->update(['status' => 'payable']);
    }

    /**
     * @param  array{status?: string, package_order_id?: int}  $filters
     */
    public function listEntitlementsForCompany(Company $company, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        $query = SupplierEntitlement::query()
            ->where('company_id', $company->id)
            ->with(['packageOrder', 'packageOrderItem'])
            ->orderByDesc('id');

        if (isset($filters['status']) && $filters['status'] !== '') {
            $query->where('status', (string) $filters['status']);
        }

        if (isset($filters['package_order_id'])) {
            $query->where('package_order_id', (int) $filters['package_order_id']);
        }

        return $query->paginate($perPage);
    }

    public function listSettlementsForCompany(Company $company, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return Settlement::query()
            ->where('company_id', $company->id)
            ->with(['company'])
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function updateSettlementStatus(Settlement $settlement, string $newStatus, ?string $notes = null): Settlement
    {
        if (! in_array($newStatus, Settlement::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid settlement status.'],
            ]);
        }

        $settlement->status = $newStatus;
        if ($notes !== null) {
            $settlement->notes = $notes;
        }
        if ($newStatus === 'settled') {
            $settlement->settled_at = now();
        }
        $settlement->save();

        return $settlement->fresh();
    }

    private function commissionAmountForGross(CommissionPolicy $policy, string $gross): string
    {
        $mode = $policy->commission_mode ?? 'percent';

        if ($mode === 'fixed_amount') {
            return bcadd((string) $policy->percent, '0', 2);
        }

        $pct = (string) $policy->percent;
        $amount = bcmul(bcdiv(bcmul($gross, $pct, 6), '100', 6), '1', 2);

        if ($policy->min_amount !== null) {
            $min = bcadd((string) $policy->min_amount, '0', 2);
            if (bccomp($amount, $min, 2) < 0) {
                $amount = $min;
            }
        }
        if ($policy->max_amount !== null) {
            $max = bcadd((string) $policy->max_amount, '0', 2);
            if (bccomp($amount, $max, 2) > 0) {
                $amount = $max;
            }
        }

        return $amount;
    }
}
