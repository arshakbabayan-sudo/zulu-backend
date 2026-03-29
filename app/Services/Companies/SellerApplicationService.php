<?php

namespace App\Services\Companies;

use App\Events\SellerApplicationApproved;
use App\Events\SellerApplicationRejected;
use App\Models\Company;
use App\Models\CompanySellerApplication;
use App\Models\CompanySellerPermission;
use App\Services\Pdf\ContractPdfService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class SellerApplicationService
{
    public function applyForService(Company $company, string $serviceType, int $requestedByUserId): CompanySellerApplication
    {
        if (! in_array($serviceType, CompanySellerPermission::SERVICE_TYPES, true)) {
            throw ValidationException::withMessages([
                'service_type' => ['Invalid service type.'],
            ]);
        }

        $hasApprovedPermission = CompanySellerPermission::query()
            ->where('company_id', $company->id)
            ->where('service_type', $serviceType)
            ->where('status', 'active')
            ->exists();

        if ($hasApprovedPermission) {
            throw ValidationException::withMessages([
                'service_type' => ['Your company already has seller permission for this service.'],
            ]);
        }

        $existing = CompanySellerApplication::query()
            ->where('company_id', $company->id)
            ->where('service_type', $serviceType)
            ->first();

        if ($existing !== null && in_array($existing->status, [CompanySellerApplication::STATUS_PENDING, CompanySellerApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'service_type' => ['An application for this service is already pending review.'],
            ]);
        }

        if ($existing !== null && $existing->status === CompanySellerApplication::STATUS_REJECTED) {
            $existing->update([
                'status' => CompanySellerApplication::STATUS_PENDING,
                'rejection_reason' => null,
                'applied_at' => now(),
                'reviewed_at' => null,
                'reviewed_by' => null,
            ]);

            return $existing->fresh();
        }

        return CompanySellerApplication::query()->create([
            'company_id' => $company->id,
            'service_type' => $serviceType,
            'status' => CompanySellerApplication::STATUS_PENDING,
            'applied_at' => now(),
        ]);
    }

    public function approve(CompanySellerApplication $application, int $approvedByUserId, ?string $notes = null): CompanySellerApplication
    {
        if (! in_array($application->status, [CompanySellerApplication::STATUS_PENDING, CompanySellerApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'status' => ['Application cannot be approved in its current state.'],
            ]);
        }

        DB::transaction(function () use ($application, $approvedByUserId, $notes): void {
            $application->update([
                'status' => CompanySellerApplication::STATUS_APPROVED,
                'reviewed_by' => $approvedByUserId,
                'reviewed_at' => now(),
                'notes' => $notes,
            ]);

            CompanySellerPermission::query()->firstOrCreate([
                'company_id' => $application->company_id,
                'service_type' => $application->service_type,
            ]);

            $company = Company::query()->find($application->company_id);
            if ($company !== null && ! $company->is_seller) {
                $company->is_seller = true;
                if ($company->seller_activated_at === null) {
                    $company->seller_activated_at = now();
                }
                $company->save();
            }
        });

        $fresh = $application->fresh();

        if ($fresh !== null) {
            event(new SellerApplicationApproved($fresh));
        }

        try {
            $company = $fresh->company()->with('sellerPermissions')->first();
            if ($company !== null) {
                $serviceTypes = $company->sellerPermissions->pluck('service_type')->toArray();
                $pdf = app(ContractPdfService::class)->generate($company, $serviceTypes);
                $path = 'contracts/seller-'.$company->id.'-'.$fresh->service_type.'-'.time().'.pdf';
                Storage::disk('local')->put($path, $pdf->getContent());
            }
        } catch (\Throwable $e) {
            Log::warning('Seller contract PDF failed', ['error' => $e->getMessage()]);
        }

        return $fresh;
    }

    public function reject(CompanySellerApplication $application, int $rejectedByUserId, string $rejectionReason): CompanySellerApplication
    {
        if (! in_array($application->status, [CompanySellerApplication::STATUS_PENDING, CompanySellerApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'status' => ['Application cannot be rejected in its current state.'],
            ]);
        }

        $application->update([
            'status' => CompanySellerApplication::STATUS_REJECTED,
            'rejection_reason' => $rejectionReason,
            'reviewed_by' => $rejectedByUserId,
            'reviewed_at' => now(),
        ]);

        $fresh = $application->fresh();

        if ($fresh !== null) {
            event(new SellerApplicationRejected($fresh));
        }

        return $fresh;
    }

    /**
     * @return Collection<int, CompanySellerApplication>
     */
    public function listForCompany(Company $company): Collection
    {
        return CompanySellerApplication::query()
            ->where('company_id', $company->id)
            ->orderByDesc('id')
            ->get();
    }

    public function listPending(int $perPage = 20): LengthAwarePaginator
    {
        return CompanySellerApplication::query()
            ->whereIn('status', [CompanySellerApplication::STATUS_PENDING, CompanySellerApplication::STATUS_UNDER_REVIEW])
            ->with('company')
            ->orderByDesc('id')
            ->paginate($perPage);
    }
}

