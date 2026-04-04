<?php

namespace App\Services\Approvals;

use App\Events\CompanyApplicationApproved;
use App\Events\CompanyApplicationRejected;
use App\Models\Approval;
use App\Models\Company;
use App\Models\CompanyApplication;
use App\Models\Role;
use App\Models\User;
use App\Services\Pdf\ContractPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyApplicationApprovalService
{
    /** @var list<string> */
    private const OPERATOR_ROLE_COMPAT_NAMES = ['operator_admin', 'company_admin', 'company_operator', 'admin'];

    public function createPendingApprovalForApplication(CompanyApplication $application): void
    {
        Approval::query()->firstOrCreate(
            [
                'entity_type' => 'company_application',
                'entity_id' => (int) $application->id,
            ],
            [
                'status' => 'pending',
                'priority' => 'normal',
                'notes' => 'Initial company onboarding application submitted.',
            ]
        );
    }

    /**
     * @return array{company: Company, user: User, temporary_password: string}
     */
    public function approve(CompanyApplication $application, User $reviewer, ?string $notes = null): array
    {
        if (! in_array($application->status, [CompanyApplication::STATUS_PENDING, CompanyApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'application' => ['Application cannot be approved in its current state.'],
            ]);
        }

        if (User::query()->where('email', $application->business_email)->exists()) {
            throw ValidationException::withMessages([
                'business_email' => ['A user with this business email already exists.'],
            ]);
        }

        $operatorRole = Role::query()
            ->whereIn('name', self::OPERATOR_ROLE_COMPAT_NAMES)
            ->orderByRaw("CASE name
                WHEN 'operator_admin' THEN 0
                WHEN 'company_admin' THEN 1
                WHEN 'company_operator' THEN 2
                WHEN 'admin' THEN 3
                ELSE 99
            END")
            ->first();

        if ($operatorRole === null) {
            throw ValidationException::withMessages([
                'role' => ['operator_admin compatibility role is not configured.'],
            ]);
        }

        $temporaryPassword = Str::random(16);

        [$company, $user] = DB::transaction(function () use ($application, $reviewer, $operatorRole, $temporaryPassword, $notes): array {
            $company = Company::query()->create([
                'name' => $application->company_name,
                'legal_name' => $application->company_name,
                'type' => $this->mapCompanyTypeToCompanyDomainType((string) $application->company_type),
                'governance_status' => 'active',
                'country' => $application->country,
                'city' => $application->city,
                'address' => $application->actual_address,
                'phone' => $application->phone,
                'tax_id' => $application->tax_id,
                'status' => 'active',
                'profile_completed' => false,
            ]);

            $user = User::query()->create([
                'name' => $application->contact_person,
                'email' => $application->business_email,
                'password' => $temporaryPassword,
                'status' => User::STATUS_ACTIVE,
            ]);

            $user->companies()->attach($company->id, ['role_id' => $operatorRole->id]);

            $application->update([
                'status' => CompanyApplication::STATUS_APPROVED,
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'company_id' => $company->id,
                'notes' => $notes,
            ]);

            Approval::query()->updateOrCreate(
                [
                    'entity_type' => 'company_application',
                    'entity_id' => (int) $application->id,
                ],
                [
                    'status' => 'approved',
                    'reviewed_by' => $reviewer->id,
                    'reviewed_at' => now(),
                    'approved_by' => $reviewer->id,
                    'approved_at' => now(),
                    'decision_notes' => $notes,
                    'priority' => 'normal',
                ]
            );

            return [$company, $user];
        });

        try {
            event(new CompanyApplicationApproved($application->fresh(), $user, $temporaryPassword));
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch approval event', ['error' => $e->getMessage()]);
        }

        try {
            $contract = app(ContractPdfService::class)->generate($company);
            $pdfContent = $contract->getContent();
            Storage::disk('local')->put('contracts/company-'.$company->id.'-'.time().'.pdf', $pdfContent);
        } catch (\Throwable $e) {
            Log::warning('Contract PDF generation failed', ['error' => $e->getMessage()]);
        }

        return [
            'company' => $company,
            'user' => $user,
            'temporary_password' => $temporaryPassword,
        ];
    }

    public function reject(CompanyApplication $application, User $reviewer, string $rejectionReason): CompanyApplication
    {
        if (! in_array($application->status, [CompanyApplication::STATUS_PENDING, CompanyApplication::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'application' => ['Application cannot be rejected in its current state.'],
            ]);
        }

        $application->update([
            'status' => CompanyApplication::STATUS_REJECTED,
            'rejection_reason' => $rejectionReason,
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        Approval::query()->updateOrCreate(
            [
                'entity_type' => 'company_application',
                'entity_id' => (int) $application->id,
            ],
            [
                'status' => 'rejected',
                'reviewed_by' => $reviewer->id,
                'reviewed_at' => now(),
                'decision_notes' => $rejectionReason,
                'priority' => 'normal',
            ]
        );

        try {
            event(new CompanyApplicationRejected($application->fresh()));
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch rejection event', ['error' => $e->getMessage()]);
        }

        return $application->fresh();
    }

    private function mapCompanyTypeToCompanyDomainType(string $applicationType): string
    {
        return $applicationType === CompanyApplication::TYPE_AGENT ? 'agency' : 'operator';
    }
}
