<?php

namespace Tests\Feature\Sprint3;

use App\Mail\GenericNotificationMail;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderEventNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_failed_dispatches_notification_and_email(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'pay-fail@example.com']);
        $payment = $this->createPaymentForUser($user);

        $service = app(PaymentService::class);
        $service->markFailed($payment);

        $this->assertSame(1, Notification::query()
            ->where('user_id', $user->id)
            ->where('event_type', 'payment.failed')
            ->count());

        Mail::assertQueued(GenericNotificationMail::class, fn ($m) => $m->hasTo($user->email));
    }

    public function test_payment_failed_skips_notification_when_no_user_on_order(): void
    {
        Mail::fake();

        $payment = $this->createPaymentForUser(null);

        app(PaymentService::class)->markFailed($payment);

        $this->assertSame(0, Notification::query()->where('event_type', 'payment.failed')->count());
        Mail::assertNothingQueued();
    }

    private function createPaymentForUser(?User $user): Payment
    {
        $company = Company::query()->create([
            'name' => 'Notif Pay Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_number' => 'ZULU-NPY-'.str()->random(4),
            'user_id' => $user?->id,
            'company_id' => $company->id,
            'buyer_type' => $user ? 'client' : 'guest',
            'status' => 'pending_payment',
            'currency' => 'EUR',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
            'metadata' => [],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'unit_price' => 100,
            'total' => 100,
            'status' => 'pending',
        ]);

        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'invoice_number' => 'INV-'.str()->random(6),
            'company_id' => $company->id,
            'user_id' => $user?->id,
            'status' => 'pending',
            'currency' => 'EUR',
            'total_amount' => 100,
            'issued_at' => now(),
        ]);

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'currency' => 'EUR',
            'status' => Payment::STATUS_PENDING,
            'payment_method' => 'card',
        ]);
    }
}
