<?php

namespace App\Services\Packages;

use App\Models\Company;
use App\Models\Package;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * §10 — package review gate (Arshak 2026-06-16: "only the first package needs
 * ZULU approval, then the partner publishes freely").
 *
 * A company's first package goes draft → pending_review → active via a ZULU
 * admin approval. The approval flags the company trusted
 * (companies.packages_trusted_at), after which PackageController::activate lets
 * the operator self-publish directly.
 *
 * Transitions owned here:
 *   draft|rejected → pending_review   (operator submits)
 *   pending_review → active           (ZULU approves → PackageService::activate)
 *   pending_review → rejected         (ZULU rejects, reason required)
 */
class PackageReviewService
{
    public function __construct(
        private readonly PackageService $packages,
        private readonly NotificationService $notifications,
    ) {}

    /** Operator submits a draft (or previously rejected) package for ZULU review. */
    public function submitForReview(Package $package): Package
    {
        $allowedFrom = ['draft', 'rejected'];
        if (! in_array($package->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => ["Only draft or rejected packages can be submitted for review (current: {$package->status})."],
            ]);
        }

        $package->status = 'pending_review';
        $package->submitted_for_review_at = now();
        $package->rejection_reason = null;
        $package->save();

        return $package->fresh();
    }

    /** ZULU admin approves a pending_review package → active, and trusts the company. */
    public function approve(Package $package, User $admin): Package
    {
        if ($package->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => ["Only pending_review packages can be approved (current: {$package->status})."],
            ]);
        }

        // Runs the full activation validation (required components must
        // reference published offers) and flips status→active + is_public via
        // the pending_review→active transition.
        $package = $this->packages->activate($package);

        $package->reviewed_at = now();
        $package->reviewed_by = $admin->id;
        $package->rejection_reason = null;
        $package->save();

        // First approval makes the company a trusted self-publisher.
        if ($package->company_id !== null) {
            Company::query()
                ->whereKey($package->company_id)
                ->whereNull('packages_trusted_at')
                ->update(['packages_trusted_at' => now()]);
        }

        $fresh = $package->fresh();
        $this->notifyCompanyUsers($fresh, 'package.approved');

        return $fresh;
    }

    /** ZULU admin rejects a pending_review package → rejected (reason required). */
    public function reject(Package $package, User $admin, string $reason): Package
    {
        if ($package->status !== 'pending_review') {
            throw ValidationException::withMessages([
                'status' => ["Only pending_review packages can be rejected (current: {$package->status})."],
            ]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => ['Rejection reason is required.']]);
        }

        $package->status = 'rejected';
        $package->reviewed_at = now();
        $package->reviewed_by = $admin->id;
        $package->rejection_reason = trim($reason);
        $package->save();

        $fresh = $package->fresh();
        $this->notifyCompanyUsers($fresh, 'package.rejected');

        return $fresh;
    }

    /**
     * Paginated queue of packages waiting for ZULU review.
     *
     * @param  array<string, mixed>  $filters
     */
    public function pendingReviewQueue(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Package::query()
            ->where('status', 'pending_review')
            ->with(['company:id,name,country']);

        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['q'])) {
            $like = '%'.addcslashes((string) $filters['q'], '%_\\').'%';
            $query->where('package_title', 'ilike', $like);
        }

        return $query->orderBy('submitted_for_review_at', 'asc')->paginate($perPage);
    }

    /**
     * In-app + email notification to every user of the package's company.
     * Failures are logged but never bubble up — the review decision is already
     * persisted, so a notification glitch must not roll it back.
     */
    private function notifyCompanyUsers(Package $package, string $eventType): void
    {
        if ($package->company_id === null) {
            return;
        }

        $approved = $eventType === 'package.approved';
        $title = $approved ? 'Your package was approved' : 'Your package was rejected';
        $label = $package->package_title !== null && $package->package_title !== ''
            ? '"'.$package->package_title.'"'
            : "#{$package->id}";
        $message = $approved
            ? "Your package {$label} has been approved by the ZULU team and is now live. Your company can publish future packages directly."
            : "Your package {$label} was rejected. Reason: {$package->rejection_reason}";

        $userIds = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $package->company_id))
            ->pluck('id');

        foreach ($userIds as $userId) {
            try {
                $this->notifications->createForEventWithEmail([
                    'user_id' => (int) $userId,
                    'event_type' => $eventType,
                    'title' => $title,
                    'message' => $message,
                    'subject_type' => Package::class,
                    'subject_id' => $package->id,
                    'related_company_id' => $package->company_id,
                    'priority' => $approved ? 'normal' : 'high',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Package review notification failed', [
                    'package_id' => $package->id,
                    'event_type' => $eventType,
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
