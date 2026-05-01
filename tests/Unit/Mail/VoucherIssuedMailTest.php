<?php

namespace Tests\Unit\Mail;

use App\Mail\VoucherIssuedMail;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherPdfService;
use App\Services\Vouchers\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoucherIssuedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_subject_uses_voucher_language(): void
    {
        $voucher = $this->createVoucher(['language' => 'hy', 'voucher_number' => 'V-MAIL-HY']);
        $mailable = new VoucherIssuedMail($voucher);

        $envelope = $mailable->envelope();

        $this->assertStringContainsString('V-MAIL-HY', $envelope->subject);
        // Armenian "Ձեր" appears in the HY subject
        $this->assertStringContainsString('Ձեր', $envelope->subject);
    }

    public function test_subject_uses_english_when_language_en(): void
    {
        $voucher = $this->createVoucher(['language' => 'en', 'voucher_number' => 'V-MAIL-EN']);
        $mailable = new VoucherIssuedMail($voucher);

        $envelope = $mailable->envelope();

        $this->assertStringContainsString('V-MAIL-EN', $envelope->subject);
        $this->assertStringContainsString('ZULU voucher', $envelope->subject);
    }

    public function test_attaches_pdf_when_pdf_url_present_and_file_exists(): void
    {
        $voucher = $this->createVoucher(['voucher_number' => 'V-ATT-001']);
        (new VoucherPdfService)->render($voucher); // produces PDF + sets pdf_url

        $voucher = $voucher->fresh();
        $mailable = new VoucherIssuedMail($voucher);

        $attachments = $mailable->attachments();
        $this->assertCount(1, $attachments);
    }

    public function test_no_attachment_when_pdf_url_is_null(): void
    {
        $voucher = $this->createVoucher(['voucher_number' => 'V-NOPDF-001']);
        $this->assertNull($voucher->pdf_url);

        $mailable = new VoucherIssuedMail($voucher);

        $this->assertSame([], $mailable->attachments());
    }

    public function test_voucher_service_dispatches_mail_to_order_user(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'customer@example.com']);
        $order = $this->createOrderWithItem($user);

        (new VoucherService)->issueForOrder($order);

        Mail::assertQueued(VoucherIssuedMail::class, function (VoucherIssuedMail $mail): bool {
            return $mail->hasTo('customer@example.com');
        });
    }

    public function test_voucher_service_skips_mail_when_user_is_null(): void
    {
        Mail::fake();

        $order = $this->createOrderWithItem(null);

        (new VoucherService)->issueForOrder($order);

        Mail::assertNothingQueued();
    }

    private function createOrderWithItem(?User $user): Order
    {
        $company = Company::query()->create([
            'name' => 'Mail Test Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $order = Order::query()->create([
            'order_number' => 'ZULU-MAIL-'.str()->random(4),
            'user_id' => $user?->id,
            'company_id' => $company->id,
            'buyer_type' => $user ? 'client' : 'guest',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 50.00,
            'tax' => 0,
            'total' => 50.00,
            'metadata' => [],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'unit_price' => 50.00,
            'total' => 50.00,
            'status' => 'confirmed',
        ]);

        return $order->fresh(['items', 'user']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVoucher(array $overrides = []): Voucher
    {
        $company = Company::query()->create([
            'name' => 'Mail Voucher Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-MV-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 50.00,
            'tax' => 0,
            'total' => 50.00,
            'metadata' => [],
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'unit_price' => 50.00,
            'total' => 50.00,
            'status' => 'confirmed',
        ]);

        return Voucher::query()->create(array_merge([
            'voucher_number' => 'V-MV-'.str()->random(6),
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Mail Test Holder',
            'service_snapshot' => ['hotel_name' => 'Hotel for mail test'],
            'qr_token' => str()->random(64),
            'status' => 'issued',
            'language' => 'en',
        ], $overrides));
    }
}
