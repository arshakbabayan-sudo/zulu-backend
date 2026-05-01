<?php

namespace Tests\Unit\Services\Vouchers;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VoucherPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_render_writes_pdf_to_storage_and_updates_pdf_url(): void
    {
        $voucher = $this->createVoucher();
        $service = new VoucherPdfService;

        $relativePath = $service->render($voucher);

        $this->assertSame('vouchers/'.$voucher->id.'.pdf', $relativePath);
        Storage::disk('local')->assertExists($relativePath);

        $voucher->refresh();
        $this->assertSame($relativePath, $voucher->pdf_url);

        $contents = Storage::disk('local')->get($relativePath);
        $this->assertNotEmpty($contents);
        $this->assertStringStartsWith('%PDF-', $contents, 'Output should be a valid PDF (starts with %PDF-).');
    }

    public function test_render_works_for_all_three_supported_languages(): void
    {
        $service = new VoucherPdfService;

        foreach (['hy', 'en', 'ru'] as $language) {
            $voucher = $this->createVoucher(['language' => $language, 'voucher_number' => 'V-LANG-'.$language]);
            $path = $service->render($voucher);

            Storage::disk('local')->assertExists($path);
            $contents = Storage::disk('local')->get($path);
            $this->assertStringStartsWith('%PDF-', $contents);
        }
    }

    public function test_verify_url_uses_app_url_and_voucher_token(): void
    {
        config(['app.url' => 'https://api.zulu.am']);
        $voucher = $this->createVoucher(['qr_token' => 'abc123token']);
        $service = new VoucherPdfService;

        $url = $service->verifyUrl($voucher);

        $this->assertSame('https://api.zulu.am/verify/abc123token', $url);
    }

    public function test_verify_url_strips_trailing_slash_from_app_url(): void
    {
        config(['app.url' => 'https://api.zulu.am/']);
        $voucher = $this->createVoucher(['qr_token' => 'xyz']);
        $service = new VoucherPdfService;

        $this->assertSame('https://api.zulu.am/verify/xyz', $service->verifyUrl($voucher));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVoucher(array $overrides = []): Voucher
    {
        $company = Company::query()->create([
            'name' => 'PDF Test Co '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-PDF-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 100.00,
            'tax' => 0,
            'total' => 100.00,
            'metadata' => ['source' => 'pdf-test'],
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'unit_price' => 100.00,
            'total' => 100.00,
            'status' => 'confirmed',
            'service_snapshot' => [
                'hotel_name' => 'Grand Hotel',
                'check_in' => '2026-06-01',
                'check_out' => '2026-06-05',
            ],
        ]);

        return Voucher::query()->create(array_merge([
            'voucher_number' => 'V-PDF-'.str()->random(6),
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Test Holder',
            'holder_passport' => 'AM12345',
            'passenger_data' => [
                ['name' => 'Test Holder', 'passport' => 'AM12345'],
            ],
            'service_snapshot' => [
                'hotel_name' => 'Grand Hotel Yerevan',
                'check_in' => '2026-06-01',
                'check_out' => '2026-06-05',
            ],
            'qr_token' => str()->random(64),
            'status' => 'issued',
            'language' => 'en',
            'valid_from' => '2026-06-01 14:00:00',
            'valid_to' => '2026-06-05 12:00:00',
        ], $overrides));
    }
}
