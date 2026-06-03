<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherVerificationLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherVerificationLogModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_and_links_to_voucher(): void
    {
        $voucher = $this->createVoucher();

        $log = VoucherVerificationLog::query()->create([
            'voucher_id' => $voucher->id,
            'scanned_at' => '2026-06-01 14:30:00',
            'ip' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0 (Test)',
            'location' => 'Yerevan, AM',
            'result' => 'valid',
        ]);

        $fresh = VoucherVerificationLog::query()->findOrFail($log->id);

        $this->assertSame($voucher->id, $fresh->voucher_id);
        $this->assertSame('203.0.113.5', $fresh->ip);
        $this->assertSame('valid', $fresh->result);
        $this->assertSame('Yerevan, AM', $fresh->location);
        $this->assertNotNull($fresh->scanned_at);
        $this->assertInstanceOf(BelongsTo::class, $fresh->voucher());
        $this->assertSame($voucher->id, $fresh->voucher->id);
    }

    public function test_cascade_delete_removes_logs_when_voucher_deleted(): void
    {
        $voucher = $this->createVoucher();

        VoucherVerificationLog::query()->create([
            'voucher_id' => $voucher->id,
            'scanned_at' => now(),
            'ip' => '203.0.113.5',
            'result' => 'valid',
        ]);

        $voucher->forceDelete();

        $this->assertSame(0, VoucherVerificationLog::query()->where('voucher_id', $voucher->id)->count());
    }

    private function createVoucher(): Voucher
    {
        $company = Company::query()->create([
            'name' => 'Log Test Co '.str()->random(6),
            'type' => 'operator',
        ]);

        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-LOG-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 50.00,
            'tax' => 10.00,
            'total' => 60.00,
            'metadata' => ['source' => 'log-test'],
        ]);

        $item = OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'status' => 'confirmed',
            'unit_price' => 50.00,
            'total' => 50.00,
        ]);

        return Voucher::query()->create([
            'voucher_number' => 'V-LOG-'.str()->random(6),
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Log Holder',
            'service_snapshot' => ['note' => 'log test'],
            'qr_token' => str()->random(64),
            'status' => 'issued',
            'language' => 'en',
        ]);
    }
}
