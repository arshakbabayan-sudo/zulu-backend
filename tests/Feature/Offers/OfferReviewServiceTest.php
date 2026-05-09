<?php

namespace Tests\Feature\Offers;

use App\Models\Company;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use App\Services\Offers\OfferReviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Covers the review-gate state machine introduced in Sprint 84
 * (zulu-backend@b8ad6c3, a488533).
 */
class OfferReviewServiceTest extends TestCase
{
    use RefreshDatabase;

    private OfferReviewService $service;

    private Company $company;

    private User $operator;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->service = app(OfferReviewService::class);

        $this->company = Company::query()->create([
            'name' => 'Test Operator',
            'type' => 'tour_operator',
            'status' => 'active',
        ]);

        $this->operator = $this->makeUserAttached('op');
        $this->admin = User::query()->create([
            'name' => 'Admin',
            'email' => 'admin-'.str()->random(6).'@test.local',
            'password' => bcrypt('x'),
            'status' => 'active',
        ]);
    }

    private function makeUserAttached(string $prefix): User
    {
        $user = User::query()->create([
            'name' => $prefix,
            'email' => $prefix.'-'.str()->random(6).'@test.local',
            'password' => bcrypt('x'),
            'status' => 'active',
        ]);
        $role = Role::query()->firstOrCreate(
            ['name' => 'Test Operator Role'],
            ['slug' => 'test-operator-role'],
        );
        DB::table('user_company')->insert([
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user->fresh();
    }

    private function makeOffer(string $status = Offer::STATUS_DRAFT): Offer
    {
        return Offer::query()->create([
            'company_id' => $this->company->id,
            'type' => 'hotel',
            'title' => 'Test Hotel Offer',
            'price' => 100,
            'currency' => 'USD',
            'status' => $status,
        ]);
    }

    // ─── submitForReview ──────────────────────────────────────────────

    public function test_submit_for_review_flips_draft_to_pending_review(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_DRAFT);

        $result = $this->service->submitForReview($offer, $this->operator);

        $this->assertSame(Offer::STATUS_PENDING_REVIEW, $result->status);
        $this->assertNotNull($result->submitted_for_review_at);
        $this->assertNull($result->rejection_reason);
    }

    public function test_submit_for_review_clears_prior_rejection_reason_on_resubmit(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_REJECTED);
        $offer->rejection_reason = 'old reason';
        $offer->save();

        $result = $this->service->submitForReview($offer->fresh(), $this->operator);

        $this->assertSame(Offer::STATUS_PENDING_REVIEW, $result->status);
        $this->assertNull($result->rejection_reason);
    }

    public function test_submit_for_review_blocks_already_pending_offer(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $this->expectException(ValidationException::class);
        $this->service->submitForReview($offer, $this->operator);
    }

    public function test_submit_for_review_blocks_published_offer(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PUBLISHED);

        $this->expectException(ValidationException::class);
        $this->service->submitForReview($offer, $this->operator);
    }

    public function test_submit_for_review_blocks_archived_offer(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_ARCHIVED);

        $this->expectException(ValidationException::class);
        $this->service->submitForReview($offer, $this->operator);
    }

    // ─── approve ──────────────────────────────────────────────────────

    public function test_approve_flips_pending_review_to_published(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $result = $this->service->approve($offer, $this->admin);

        $this->assertSame(Offer::STATUS_PUBLISHED, $result->status);
        $this->assertNotNull($result->reviewed_at);
        $this->assertSame($this->admin->id, $result->reviewed_by);
    }

    public function test_approve_blocks_draft_offer(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_DRAFT);

        $this->expectException(ValidationException::class);
        $this->service->approve($offer, $this->admin);
    }

    public function test_approve_blocks_already_published_offer(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PUBLISHED);

        $this->expectException(ValidationException::class);
        $this->service->approve($offer, $this->admin);
    }

    public function test_approve_creates_notification_for_each_company_user(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        // Add second operator user under the same company
        $second = $this->makeUserAttached('op2');

        $this->service->approve($offer, $this->admin);

        $this->assertSame(2, Notification::query()
            ->where('event_type', 'offer.approved')
            ->where('subject_id', $offer->id)
            ->count());

        $userIds = Notification::query()
            ->where('event_type', 'offer.approved')
            ->where('subject_id', $offer->id)
            ->pluck('user_id')
            ->sort()
            ->values()
            ->toArray();
        $this->assertSame(
            collect([$this->operator->id, $second->id])->sort()->values()->toArray(),
            $userIds,
        );
    }

    public function test_approve_notification_carries_offer_label_and_company_id(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $this->service->approve($offer, $this->admin);

        $notif = Notification::query()
            ->where('event_type', 'offer.approved')
            ->where('subject_id', $offer->id)
            ->firstOrFail();

        $this->assertStringContainsString('"Test Hotel Offer"', $notif->message);
        $this->assertSame(Offer::class, $notif->subject_type);
        $this->assertSame($this->company->id, $notif->related_company_id);
        $this->assertSame('normal', $notif->priority);
    }

    // ─── reject ───────────────────────────────────────────────────────

    public function test_reject_flips_pending_review_to_rejected(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $result = $this->service->reject($offer, $this->admin, 'Photos are blurry');

        $this->assertSame(Offer::STATUS_REJECTED, $result->status);
        $this->assertSame('Photos are blurry', $result->rejection_reason);
        $this->assertNotNull($result->reviewed_at);
        $this->assertSame($this->admin->id, $result->reviewed_by);
    }

    public function test_reject_trims_whitespace_from_reason(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $result = $this->service->reject($offer, $this->admin, '  not enough detail  ');

        $this->assertSame('not enough detail', $result->rejection_reason);
    }

    public function test_reject_requires_non_empty_reason(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $this->expectException(ValidationException::class);
        $this->service->reject($offer, $this->admin, '   ');
    }

    public function test_reject_blocks_draft_offer(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_DRAFT);

        $this->expectException(ValidationException::class);
        $this->service->reject($offer, $this->admin, 'reason');
    }

    public function test_reject_notification_is_high_priority_and_carries_reason(): void
    {
        $offer = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);

        $this->service->reject($offer, $this->admin, 'Photos are blurry');

        $notif = Notification::query()
            ->where('event_type', 'offer.rejected')
            ->where('subject_id', $offer->id)
            ->firstOrFail();

        $this->assertSame('high', $notif->priority);
        $this->assertStringContainsString('Photos are blurry', $notif->message);
    }

    // ─── pendingReviewQueue ───────────────────────────────────────────

    public function test_queue_returns_only_pending_review_offers(): void
    {
        $this->makeOffer(Offer::STATUS_DRAFT);
        $pending = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);
        $this->makeOffer(Offer::STATUS_PUBLISHED);
        $this->makeOffer(Offer::STATUS_REJECTED);

        $queue = $this->service->pendingReviewQueue();

        $this->assertCount(1, $queue->items());
        $this->assertSame($pending->id, $queue->items()[0]->id);
    }

    public function test_queue_filters_by_type(): void
    {
        $hotel = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);
        $car = Offer::query()->create([
            'company_id' => $this->company->id,
            'type' => 'car',
            'title' => 'Car offer',
            'price' => 50,
            'currency' => 'USD',
            'status' => Offer::STATUS_PENDING_REVIEW,
        ]);

        $queue = $this->service->pendingReviewQueue(['type' => 'car']);

        $this->assertCount(1, $queue->items());
        $this->assertSame($car->id, $queue->items()[0]->id);
    }

    public function test_queue_orders_oldest_submitted_first(): void
    {
        $first = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);
        $first->submitted_for_review_at = now()->subDay();
        $first->save();

        $second = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);
        $second->submitted_for_review_at = now();
        $second->save();

        $queue = $this->service->pendingReviewQueue();
        $ids = collect($queue->items())->pluck('id')->all();

        $this->assertSame([$first->id, $second->id], $ids);
    }

    public function test_queue_filters_by_company_id(): void
    {
        $other = Company::query()->create([
            'name' => 'Other Op',
            'type' => 'tour_operator',
            'status' => 'active',
        ]);

        $mine = $this->makeOffer(Offer::STATUS_PENDING_REVIEW);
        Offer::query()->create([
            'company_id' => $other->id,
            'type' => 'hotel',
            'title' => 'Other hotel',
            'price' => 100,
            'currency' => 'USD',
            'status' => Offer::STATUS_PENDING_REVIEW,
        ]);

        $queue = $this->service->pendingReviewQueue(['company_id' => $this->company->id]);

        $this->assertCount(1, $queue->items());
        $this->assertSame($mine->id, $queue->items()[0]->id);
    }
}
