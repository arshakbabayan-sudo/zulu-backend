<?php

namespace Tests\Unit\Services\Notifications;

use App\Mail\GenericNotificationMail;
use App\Mail\VoucherIssuedMail;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationServiceEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_for_event_with_email_dispatches_generic_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'order@example.com']);
        $service = new NotificationService;

        $service->createForEventWithEmail([
            'user_id' => $user->id,
            'event_type' => 'order.paid',
            'title' => 'Payment Successful',
            'message' => 'Your order ZULU-1 has been paid.',
            'priority' => 'high',
        ]);

        Mail::assertQueued(GenericNotificationMail::class, function (GenericNotificationMail $mail) use ($user): bool {
            return $mail->hasTo($user->email);
        });

        $this->assertSame(1, Notification::query()->where('user_id', $user->id)->count());
    }

    public function test_dispatch_email_for_voucher_issued_is_skipped_to_avoid_double_email(): void
    {
        // voucher.issued has its own VoucherIssuedMail, so the generic
        // dispatcher must NOT send a second email for it.
        Mail::fake();

        $user = User::factory()->create();
        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'voucher.issued',
            'event_type' => 'voucher.issued',
            'title' => 'Voucher issued',
            'message' => 'Your voucher V-X is ready.',
            'status' => 'unread',
            'priority' => 'normal',
        ]);

        (new NotificationService)->dispatchEmailFor($notification);

        Mail::assertNotQueued(GenericNotificationMail::class);
        Mail::assertNotQueued(VoucherIssuedMail::class); // also not double-sent
    }

    public function test_dispatch_email_skips_when_user_has_no_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => '']);
        $notification = Notification::query()->create([
            'user_id' => $user->id,
            'type' => 'order.paid',
            'event_type' => 'order.paid',
            'title' => 'Payment Successful',
            'message' => 'Order paid.',
            'status' => 'unread',
            'priority' => 'high',
        ]);

        (new NotificationService)->dispatchEmailFor($notification);

        Mail::assertNothingQueued();
    }

    public function test_create_for_event_alone_does_not_send_email(): void
    {
        // The non-_with_email variant must NOT auto-email, so callers
        // can opt in deliberately (preserves existing behavior).
        Mail::fake();

        $user = User::factory()->create();
        $service = new NotificationService;

        $service->createForEvent([
            'user_id' => $user->id,
            'event_type' => 'order.paid',
            'title' => 'Test',
            'message' => 'Test message',
            'priority' => 'normal',
        ]);

        Mail::assertNothingQueued();
    }
}
