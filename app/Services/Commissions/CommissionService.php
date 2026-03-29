<?php

namespace App\Services\Commissions;

use App\Models\CommissionPolicy;
use App\Models\CommissionRecord;
use App\Models\Company;
use App\Models\CompanySellerPermission;
use App\Models\PackageOrder;
use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CommissionService
{
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
    public function accrueForPackageOrder(PackageOrder $packageOrder): ?CommissionRecord
    {
        return $this->accrue(
            $packageOrder->company_id,
            'package',
            (string) $packageOrder->final_total_snapshot,
            $packageOrder->currency,
            'package_order',
            $packageOrder->id
        );
    }

    /**
     * Accrue commission for a generic Booking.
     */
    public function accrueForBooking(Booking $booking): ?CommissionRecord
    {
        // For individual bookings, we might need to iterate through items to find service types
        // but for now, we accrue based on the booking's total if a general policy exists.
        return $this->accrue(
            $booking->company_id,
            'general', // or specific type if known
            (string) $booking->total_price,
            'USD', // Default currency if not in booking
            'booking',
            $booking->id
        );
    }

    /**
     * Generic commission calculation and record creation.
     */
    private function accrue(int $companyId, string $serviceType, string $totalAmount, string $currency, string $subjectType, int $subjectId): ?CommissionRecord
    {
        try {
            $policy = CommissionPolicy::query()
                ->where('company_id', $companyId)
                ->where('service_type', $serviceType)
                ->where('status', 'active')
                ->orderByDesc('id')
                ->first();

            if ($policy === null) {
                // Try to find a 'general' policy if specific one is not found
                $policy = CommissionPolicy::query()
                    ->where('company_id', $companyId)
                    ->where('service_type', 'general')
                    ->where('status', 'active')
                    ->orderByDesc('id')
                    ->first();
            }

            if ($policy === null) {
                return null;
            }

            $mode = $policy->commission_mode ?? 'percent';

            if ($mode === 'fixed_amount') {
                $amount = bcadd((string) $policy->percent, '0', 2);
                $commissionValue = (string) $policy->percent;
            } else {
                $pct = (string) $policy->percent;
                $amount = bcmul(bcdiv(bcmul($totalAmount, $pct, 6), '100', 6), '1', 2);
                $commissionValue = $pct;

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
            }

            return $this->createRecord([
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'company_id' => $companyId,
                'service_type' => $serviceType,
                'commission_mode' => $mode,
                'commission_value' => $commissionValue,
                'commission_amount_snapshot' => $amount,
                'currency' => $currency,
                'commission_policy_id' => $policy->id,
                'status' => 'accrued',
            ]);
        } catch (\Throwable $e) {
            Log::warning('Commission accrual failed', [
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
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
