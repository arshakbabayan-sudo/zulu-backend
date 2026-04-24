<?php

namespace App\Services\Commissions;

use App\Models\Booking;
use App\Models\CommissionPolicy;
use App\Models\CommissionRecord;
use App\Models\CommissionResolutionLog;
use App\Models\CommissionRule;
use App\Models\CommissionTransaction;
use App\Models\Company;
use App\Models\PackageOrder;
use App\Services\Commissions\DTOs\CommissionResolutionContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionService
{
    public function __construct(
        private CommissionRuleResolver $resolver,
    ) {}

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, CommissionPolicy>
     */
    public function listForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return CommissionPolicy::query()
            ->whereIn('company_id', $companyIds)
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        $query = CommissionPolicy::query()->orderBy('id');

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1')->paginate($perPage);
        }

        return $query
            ->whereIn('company_id', $companyIds)
            ->paginate($perPage);
    }

    /**
     * @param  list<int>  $companyIds
     * @return Collection<int, CommissionRecord>
     */
    public function listRecordsForCompanies(array $companyIds): Collection
    {
        if ($companyIds === []) {
            return new Collection;
        }

        return CommissionRecord::query()
            ->whereIn('company_id', $companyIds)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  list<int>  $companyIds
     */
    public function paginateRecordsForCompanies(array $companyIds, int $perPage = 20): LengthAwarePaginator
    {
        $query = CommissionRecord::query()->orderByDesc('id');

        if ($companyIds === []) {
            return $query->whereRaw('0 = 1')->paginate($perPage);
        }

        return $query->whereIn('company_id', $companyIds)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRecord(array $data): CommissionRecord
    {
        $validator = Validator::make($data, [
            'subject_type' => ['required', 'string', Rule::in(CommissionRecord::SUBJECT_TYPES)],
            'subject_id' => ['required', 'integer', 'min:1'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'service_type' => ['required', 'string'],
            'commission_mode' => ['required', 'string', Rule::in(CommissionPolicy::COMMISSION_MODES)],
            'commission_value' => ['required', 'numeric', 'min:0'],
            'commission_amount_snapshot' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'commission_policy_id' => ['nullable', 'integer', 'exists:commission_policies,id'],
            'status' => ['sometimes', 'string', Rule::in(CommissionRecord::STATUSES)],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $clean = $validator->validated();
        $clean['status'] = $clean['status'] ?? CommissionRecord::STATUSES[0];

        return CommissionRecord::query()->create($clean);
    }

    /**
     * Accrue commission for a Package Order.
     */
    public function accrueForPackageOrder(PackageOrder $packageOrder): ?CommissionTransaction
    {
        return $this->accrue(
            $packageOrder->company_id,
            'package',
            (string) $packageOrder->final_total_snapshot,
            $packageOrder->currency,
            null,
            null
        );
    }

    /**
     * Accrue commission for a generic Booking.
     */
    public function accrueForBooking(Booking $booking): ?CommissionTransaction
    {
        return $this->accrue(
            $booking->company_id,
            'general',
            (string) $booking->total_price,
            strtoupper((string) ($booking->currency ?? 'USD')),
            null,
            null
        );
    }

    /**
     * Generic commission calculation and record creation.
     */
    private function accrue(
        int $sellerId,
        string $serviceType,
        string $baseAmount,
        string $baseCurrency,
        ?int $orderId,
        ?int $orderItemId
    ): ?CommissionTransaction {
        try {
            $ctx = CommissionResolutionContext::make(
                sellerId: $sellerId,
                serviceType: $serviceType,
                opts: ['atTime' => now()],
            );
            $result = $this->resolver->resolve($ctx);
            $rule = $result->chosenRule;

            if ($rule === null) {
                return null;
            }

            $fxRate = null;
            if ($rule->type === 'percentage') {
                $commission = bcdiv(bcmul($baseAmount, (string) $rule->percentage_value, 8), '100', 4);
            } elseif ($rule->type === 'fixed') {
                if ($rule->fixed_currency !== null && strtoupper((string) $rule->fixed_currency) !== strtoupper($baseCurrency)) {
                    Log::warning('Commission fixed currency mismatch during accrual', [
                        'seller_id' => $sellerId,
                        'rule_id' => $rule->id,
                        'fixed_currency' => $rule->fixed_currency,
                        'base_currency' => $baseCurrency,
                    ]);
                }

                $commission = bcadd((string) $rule->fixed_value, '0', 4);
            } else {
                $commission = bcadd(
                    bcdiv(bcmul($baseAmount, (string) $rule->percentage_value, 8), '100', 4),
                    (string) $rule->fixed_value,
                    4
                );
            }

            return DB::transaction(function () use (
                $orderId,
                $orderItemId,
                $rule,
                $sellerId,
                $baseAmount,
                $baseCurrency,
                $commission,
                $fxRate,
                $result
            ): CommissionTransaction {
                $transaction = CommissionTransaction::query()->create([
                    'order_id' => $orderId,
                    'order_item_id' => $orderItemId,
                    'rule_id' => $rule->id,
                    'seller_id' => $sellerId,
                    'base_amount' => $baseAmount,
                    'base_currency' => $baseCurrency,
                    'commission_amount' => $commission,
                    'commission_currency' => $baseCurrency,
                    'net_to_seller' => bcsub($baseAmount, $commission, 4),
                    'fx_rate' => $fxRate,
                    'snapshot' => [
                        'rule_id' => $rule->id,
                        'type' => $rule->type,
                        'level' => $rule->level,
                        'percentage_value' => $rule->percentage_value,
                        'fixed_value' => $rule->fixed_value,
                        'fixed_currency' => $rule->fixed_currency,
                        'scope_id' => $rule->scope_id,
                        'service_type' => $rule->service_type,
                        'effective_from' => $rule->effective_from?->toIso8601String(),
                    ],
                    'computed_at' => now(),
                ]);

                CommissionResolutionLog::query()->create([
                    'transaction_id' => $transaction->id,
                    'candidate_rules' => array_map(
                        static fn (CommissionRule $candidate): string => $candidate->id,
                        $result->candidateRules
                    ),
                    'chosen_rule_id' => $rule->id,
                    'reason' => $result->reason,
                ]);

                return $transaction;
            });
        } catch (\Throwable $e) {
            Log::warning('Commission accrual failed', [
                'seller_id' => $sellerId,
                'service_type' => $serviceType,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPolicy(Company $company, array $data): CommissionPolicy
    {
        $validator = Validator::make($data, [
            'service_type' => ['required', 'string'],
            'percent' => ['required', 'numeric', 'min:0'],
            'commission_mode' => ['required', 'string', Rule::in(CommissionPolicy::COMMISSION_MODES)],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $clean = $validator->validated();

        CommissionPolicy::query()
            ->where('company_id', $company->id)
            ->where('service_type', $clean['service_type'])
            ->where('status', 'active')
            ->update(['status' => 'inactive']);

        return CommissionPolicy::query()->create([
            'company_id' => $company->id,
            'service_type' => $clean['service_type'],
            'percent' => $clean['percent'],
            'commission_mode' => $clean['commission_mode'],
            'min_amount' => $clean['min_amount'] ?? null,
            'max_amount' => $clean['max_amount'] ?? null,
            'effective_from' => $clean['effective_from'] ?? null,
            'effective_to' => $clean['effective_to'] ?? null,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePolicy(CommissionPolicy $policy, array $data): CommissionPolicy
    {
        $policy->update($data);

        return $policy->fresh();
    }

    public function deactivatePolicy(CommissionPolicy $policy): CommissionPolicy
    {
        $policy->status = 'inactive';
        $policy->save();

        return $policy->fresh();
    }
}
