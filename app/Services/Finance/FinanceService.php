<?php

namespace App\Services\Finance;

use App\Models\Company;
use App\Models\Order;
use App\Models\Settlement;
use App\Models\SupplierEntitlement;
use App\Models\User;
use App\Services\Commissions\CommissionRuleResolver;
use App\Services\Commissions\DTOs\CommissionResolutionContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FinanceService
{
    public function __construct(
        private CommissionRuleResolver $resolver,
    ) {}

    /**
     * @return list<SupplierEntitlement>
     */
    public function createEntitlementsForOrder(Order $order): array
    {
        try {
            return DB::transaction(function () use ($order) {
                $order->loadMissing(['items']);

                /** @var list<SupplierEntitlement> $created */
                $created = [];

                foreach ($order->items as $item) {
                    $snapshot = $item->service_snapshot ?? [];
                    $sellerId = (int) ($snapshot['company_id'] ?? $order->company_id ?? 0);
                    $gross = bcadd((string) $item->total, '0', 2);

                    $ctx = CommissionResolutionContext::make(
                        sellerId: $sellerId,
                        serviceType: (string) $item->item_type,
                        opts: [
                            'atTime' => now(),
                            'categoryId' => isset($snapshot['category_id']) ? (int) $snapshot['category_id'] : null,
                            'partnerAgreementId' => null,
                        ]
                    );
                    $rule = $this->resolver->resolve($ctx)->chosenRule;

                    $commission = '0';
                    if ($rule !== null) {
                        $baseCurrency = (string) $item->currency;

                        if ($rule->type === 'percentage') {
                            $commission = bcdiv(bcmul($gross, (string) $rule->percentage_value, 8), '100', 4);
                        } elseif ($rule->type === 'fixed') {
                            if ($rule->fixed_currency !== null && strtoupper((string) $rule->fixed_currency) !== strtoupper($baseCurrency)) {
                                Log::warning('Commission fixed currency mismatch during entitlement accrual', [
                                    'seller_id' => $sellerId,
                                    'rule_id' => $rule->id,
                                    'fixed_currency' => $rule->fixed_currency,
                                    'base_currency' => $baseCurrency,
                                ]);
                            }

                            $commission = bcadd((string) $rule->fixed_value, '0', 4);
                        } else {
                            $commission = bcadd(
                                bcdiv(bcmul($gross, (string) $rule->percentage_value, 8), '100', 4),
                                (string) $rule->fixed_value,
                                4
                            );
                        }
                    }

                    $net = bcsub($gross, $commission, 2);

                    $created[] = SupplierEntitlement::query()->create([
                        'package_order_id' => null,
                        'package_order_item_id' => null,
                        'booking_id' => null,
                        'booking_item_id' => null,
                        'company_id' => $sellerId,
                        'service_type' => $item->item_type,
                        'gross_amount' => $gross,
                        'commission_amount' => $commission,
                        'net_amount' => $net,
                        'currency' => (string) $item->currency,
                        'status' => 'accrued',
                        'notes' => 'order_id:'.$order->id.';order_item_id:'.$item->id,
                    ]);
                }

                return $created;
            });
        } catch (\Throwable $e) {
            Log::warning('Entitlement creation failed for order', [
                'order_id' => $order->id,
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
            ->with(['order', 'orderItem'])
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
}
