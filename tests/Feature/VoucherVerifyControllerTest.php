<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherVerificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherVerifyControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_voucher_renders_valid_result_and_logs_scan(): void
    {
        $voucher = $this->createVoucher(['status' => 'issued']);

        $response = $this->get('/verify/'.$voucher->qr_token);

        $response->assertOk();
        $response->assertSee('result-valid', false);
        $response->assertSee($voucher->voucher_number);

        $logs = VoucherVerificationLog::query()->where('voucher_id', $voucher->id)->get();
        $this->assertCount(1, $logs);
        $this->assertSame('valid', $logs->first()->result);

        $voucher->refresh();
        $this->assertSame(1, $voucher->verification_count);
    }

    public function test_used_status_renders_used_result(): void
    {
        $voucher = $this->createVoucher(['status' => 'used']);

        $response = $this->get('/verify/'.$voucher->qr_token);

        $response->assertOk();
        $response->assertSee('result-used', false);
        $this->assertSame('used', VoucherVerificationLog::query()->where('voucher_id', $voucher->id)->value('result'));
    }

    public function test_void_status_renders_void_result(): void
    {
        $voucher = $this->createVoucher(['status' => 'void']);

        $response = $this->get('/verify/'.$voucher->qr_token);

        $response->assertOk();
        $response->assertSee('result-void', false);
        $this->assertSame('void', VoucherVerificationLog::query()->where('voucher_id', $voucher->id)->value('result'));
    }

    public function test_reissued_voucher_treated_as_void_to_prevent_using_old_qr(): void
    {
        $voucher = $this->createVoucher(['status' => 'reissued']);

        $response = $this->get('/verify/'.$voucher->qr_token);

        $response->assertOk();
        $response->assertSee('result-void', false);
    }

    public function test_expired_voucher_renders_expired_result(): void
    {
        $voucher = $this->createVoucher([
            'status' => 'issued',
            'valid_from' => now()->subDays(10),
            'valid_to' => now()->subDays(1),
        ]);

        $response = $this->get('/verify/'.$voucher->qr_token);

        $response->assertOk();
        $response->assertSee('result-expired', false);
        $this->assertSame('expired', VoucherVerificationLog::query()->where('voucher_id', $voucher->id)->value('result'));
    }

    public function test_invalid_token_renders_invalid_result_without_logging(): void
    {
        // 64 hex chars but no matching voucher
        $bogusToken = str_repeat('f', 64);

        $response = $this->get('/verify/'.$bogusToken);

        $response->assertOk();
        $response->assertSee('result-invalid', false);
        // We don't log invalid attempts (FK would block; fraud-detection out of MVP scope)
        $this->assertSame(0, VoucherVerificationLog::query()->count());
    }

    public function test_token_not_matching_64_hex_pattern_returns_404(): void
    {
        $response = $this->get('/verify/short-token');

        $response->assertNotFound();
    }

    public function test_verification_count_increments_on_repeat_scan(): void
    {
        $voucher = $this->createVoucher(['status' => 'issued']);

        $this->get('/verify/'.$voucher->qr_token);
        $this->get('/verify/'.$voucher->qr_token);
        $this->get('/verify/'.$voucher->qr_token);

        $voucher->refresh();
        $this->assertSame(3, $voucher->verification_count);
        $this->assertSame(3, VoucherVerificationLog::query()->where('voucher_id', $voucher->id)->count());
    }

    public function test_page_renders_in_voucher_language(): void
    {
        $voucher = $this->createVoucher(['language' => 'hy', 'status' => 'issued']);

        $response = $this->get('/verify/'.$voucher->qr_token);

        $response->assertOk();
        // Armenian title contains 'Վավեր'
        $response->assertSee('Վավեր', false);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVoucher(array $overrides = []): Voucher
    {
        $company = Company::query()->create([
            'name' => 'Verify Test Co '.str()->random(6),
            'type' => 'operator',
        ]);

        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-VRF-'.str()->random(4),
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
            'voucher_number' => 'V-VRF-'.str()->random(6),
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Verify Test Holder',
            'service_snapshot' => ['note' => 'verify test'],
            'qr_token' => bin2hex(random_bytes(32)),
            'status' => 'issued',
            'language' => 'en',
        ], $overrides));
    }
}
