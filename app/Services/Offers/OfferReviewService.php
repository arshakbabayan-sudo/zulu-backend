<?php

namespace App\Services\Offers;

use App\Models\Offer;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Review-gate state machine for offers.
 *
 * Allowed transitions:
 *   draft           → pending_review  (operator submits)
 *   pending_review  → published       (super admin approves)
 *   pending_review  → rejected        (super admin rejects, requires reason)
 *   rejected        → pending_review  (operator resubmits after fixing)
 *   published       → archived        (operator/admin archives)
 *
 * draft → published is NOT allowed by this service. Direct flips remain
 * possible via tinker / SQL for emergency unblocks (e.g. mass-publishing
 * pre-existing draft offers during system rollout).
 */
class OfferReviewService
{
    public function __construct(private readonly NotificationService $notifications) {}

    /** Operator submits a draft offer for super-admin review. */
    public function submitForReview(Offer $offer, User $operator): Offer
    {
        $allowedFrom = [Offer::STATUS_DRAFT, Offer::STATUS_REJECTED];
        if (! in_array($offer->status, $allowedFrom, true)) {
            throw ValidationException::withMessages([
                'status' => ["Only draft or rejected offers can be submitted for review (current: {$offer->status})."],
            ]);
        }

        $offer->status = Offer::STATUS_PENDING_REVIEW;
        $offer->submitted_for_review_at = now();
        // Clear any prior rejection so resubmits show clean
        $offer->rejection_reason = null;
        $offer->save();

        return $offer->fresh();
    }

    /** Super admin approves a pending_review offer → published. */
    public function approve(Offer $offer, User $admin): Offer
    {
        if ($offer->status !== Offer::STATUS_PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'status' => ["Only pending_review offers can be approved (current: {$offer->status})."],
            ]);
        }

        $offer->status = Offer::STATUS_PUBLISHED;
        $offer->reviewed_at = now();
        $offer->reviewed_by = $admin->id;
        $offer->rejection_reason = null;
        $offer->save();

        $fresh = $offer->fresh();
        $this->notifyCompanyUsers($fresh, 'offer.approved');

        return $fresh;
    }

    /** Super admin rejects a pending_review offer → rejected (reason required). */
    public function reject(Offer $offer, User $admin, string $reason): Offer
    {
        if ($offer->status !== Offer::STATUS_PENDING_REVIEW) {
            throw ValidationException::withMessages([
                'status' => ["Only pending_review offers can be rejected (current: {$offer->status})."],
            ]);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['Rejection reason is required.'],
            ]);
        }

        $offer->status = Offer::STATUS_REJECTED;
        $offer->reviewed_at = now();
        $offer->reviewed_by = $admin->id;
        $offer->rejection_reason = trim($reason);
        $offer->save();

        $fresh = $offer->fresh();
        $this->notifyCompanyUsers($fresh, 'offer.rejected');

        return $fresh;
    }

    /**
     * Send in-app + email notification to every user belonging to the offer's
     * company. Failures are logged but never bubble up: the review-decision
     * itself has already been persisted, so a downstream notification glitch
     * must not roll it back.
     */
    private function notifyCompanyUsers(Offer $offer, string $eventType): void
    {
        if ($offer->company_id === null) {
            return;
        }

        $title = $eventType === 'offer.approved'
            ? 'Your listing was approved'
            : 'Your listing was rejected';

        $offerLabel = $offer->title !== null && $offer->title !== ''
            ? '"'.$offer->title.'"'
            : "#{$offer->id}";

        $message = $eventType === 'offer.approved'
            ? "Your {$offer->type} listing {$offerLabel} has been approved by the platform team and is now live for customers."
            : "Your {$offer->type} listing {$offerLabel} was rejected. Reason: {$offer->rejection_reason}";

        $userIds = User::query()
            ->whereHas('companies', fn ($q) => $q->where('companies.id', $offer->company_id))
            ->pluck('id');

        foreach ($userIds as $userId) {
            try {
                $this->notifications->createForEventWithEmail([
                    'user_id' => (int) $userId,
                    'event_type' => $eventType,
                    'title' => $title,
                    'message' => $message,
                    'subject_type' => Offer::class,
                    'subject_id' => $offer->id,
                    'related_company_id' => $offer->company_id,
                    'priority' => $eventType === 'offer.rejected' ? 'high' : 'normal',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Offer review notification failed', [
                    'offer_id' => $offer->id,
                    'event_type' => $eventType,
                    'user_id' => $userId,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Paginated queue of offers waiting for super-admin review.
     *
     * @param  array<string, mixed>  $filters
     */
    public function pendingReviewQueue(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Offer::query()
            ->where('status', Offer::STATUS_PENDING_REVIEW)
            ->with(['company:id,name,country']);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (! empty($filters['company_id'])) {
            $query->where('company_id', (int) $filters['company_id']);
        }
        if (! empty($filters['q'])) {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where('title', 'ilike', $like);
        }

        return $query
            ->orderBy('submitted_for_review_at', 'asc')
            ->paginate($perPage);
    }
}
