<?php

namespace Tests\Unit\Models;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherVerificationLog;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VoucherModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_all_fillable_columns_and_rehydrates_with_correct_casts(): void
    {
        $orderItem = $this->createOrderItem();
        $company = $this->createCompany();

        $payload = [
            'voucher_number' => 'V-ABC12345-001',
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'John Doe',
            'holder_passport' => 'AM1234567',
            'passenger_data' => [
                ['name' => 'John Doe', 'passport' => 'AM1234567'],
                ['name' => 'Jane Doe', 'passport' => 'AM7654321'],
            ],
            'service_snapshot' => [
                'hotel_name' => 'Grand Hotel Yerevan',
                'check_in' => '2026-06-01',
                'check_out' => '2026-06-05',
            ],
            'qr_token' => str_repeat('a', 64),
            'qr_image_url' => null,
            'pdf_url' => null,
            'status' => 'issued',
            'valid_from' => '2026-06-01 14:00:00',
            'valid_to' => '2026-06-05 12:00:00',
            'used_at' => null,
            'used_by_ip' => null,
            'verification_count' => 0,
            'reissued_from_id' => null,
            'language' => 'hy',
        ];

        $created = Voucher::query()->create($payload);
        $voucher = Voucher::query()->findOrFail($created->id);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $voucher->id);
        $this->assertSame('V-ABC12345-001', $voucher->voucher_number);
        $this->assertSame($orderItem->order_id, $voucher->order_id);
        $this->assertSame($orderItem->id, $voucher->order_item_id);
        $this->assertSame('hotel', $voucher->service_type);
        $this->assertSame($company->id, $voucher->issuer_company_id);
        $this->assertSame('John Doe', $voucher->holder_name);
        $this->assertSame('AM1234567', $voucher->holder_passport);
        $this->assertIsArray($voucher->passenger_data);
        $this->assertCount(2, $voucher->passenger_data);
        $this->assertSame('Grand Hotel Yerevan', $voucher->service_snapshot['hotel_name']);
        $this->assertSame('issued', $voucher->status);
        $this->assertSame('hy', $voucher->language);
        $this->assertSame(0, $voucher->verification_count);
    }

    public function test_it_loads_belongs_to_relations(): void
    {
        $orderItem = $this->createOrderItem();
        $company = $this->createCompany();
        $voucher = $this->createVoucher($orderItem, $company);

        $fresh = $voucher->fresh();

        $this->assertInstanceOf(BelongsTo::class, $fresh->order());
        $this->assertInstanceOf(BelongsTo::class, $fresh->orderItem());
        $this->assertInstanceOf(BelongsTo::class, $fresh->issuerCompany());
        $this->assertSame($orderItem->order_id, $fresh->order->id);
        $this->assertSame($orderItem->id, $fresh->orderItem->id);
        $this->assertSame($company->id, $fresh->issuerCompany->id);
    }

    public function test_reissued_from_self_referential_relation_works(): void
    {
        $orderItem = $this->createOrderItem();
        $company = $this->createCompany();

        $original = $this->createVoucher($orderItem, $company, ['voucher_number' => 'V-ORIG-001']);
        $reissued = $this->createVoucher($orderItem, $company, [
            'voucher_number' => 'V-REIS-001',
            'reissued_from_id' => $original->id,
        ]);

        $this->assertSame($original->id, $reissued->reissuedFrom->id);
        $this->assertInstanceOf(HasMany::class, $original->reissues());
        $this->assertCount(1, $original->fresh()->reissues);
    }

    public function test_is_usable_returns_false_when_status_is_not_issued(): void
    {
        $orderItem = $this->createOrderItem();
        $company = $this->createCompany();

        $void = $this->createVoucher($orderItem, $company, ['status' => 'void', 'voucher_number' => 'V-VOID-001']);
        $used = $this->createVoucher($orderItem, $company, ['status' => 'used', 'voucher_number' => 'V-USED-001']);
        $issued = $this->createVoucher($orderItem, $company, ['status' => 'issued', 'voucher_number' => 'V-ISSU-001']);

        $this->assertFalse($void->isUsable());
        $this->assertFalse($used->isUsable());
        $this->assertTrue($issued->isUsable());
    }

    public function test_is_expired_when_valid_to_is_past(): void
    {
        $orderItem = $this->createOrderItem();
        $company = $this->createCompany();

        $expired = $this->createVoucher($orderItem, $company, [
            'voucher_number' => 'V-EXP-001',
            'valid_from' => now()->subDays(10),
            'valid_to' => now()->subDays(1),
        ]);

        $this->assertTrue($expired->isExpired());
        $this->assertFalse($expired->isUsable());
    }

    public function test_verification_logs_relation_returns_logs(): void
    {
        $orderItem = $this->createOrderItem();
        $company = $this->createCompany();
        $voucher = $this->createVoucher($orderItem, $company);

        VoucherVerificationLog::query()->create([
            'voucher_id' => $voucher->id,
            'scanned_at' => now(),
            'ip' => '203.0.113.5',
            'user_agent' => 'Mozilla/5.0',
            'location' => 'Yerevan',
            'result' => 'valid',
        ]);

        $this->assertCount(1, $voucher->fresh()->verificationLogs);
    }

    private function createVoucher(OrderItem $orderItem, Company $company, array $overrides = []): Voucher
    {
        return Voucher::query()->create(array_merge([
            'voucher_number' => 'V-TEST-'.str()->random(6),
            'order_id' => $orderItem->order_id,
            'order_item_id' => $orderItem->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Test Holder',
            'service_snapshot' => ['note' => 'test'],
            'qr_token' => str()->random(64),
            'status' => 'issued',
            'language' => 'en',
        ], $overrides));
    }

    private function createOrderItem(): OrderItem
    {
        $company = $this->createCompany();
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-VOUCHER-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 100.00,
            'tax' => 20.00,
            'total' => 120.00,
            'metadata' => ['source' => 'voucher-test'],
        ]);

        return OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'status' => 'confirmed',
            'unit_price' => 100.00,
            'total' => 100.00,
        ]);
    }

    private function createCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Voucher Issuer '.str()->random(6),
            'type' => 'operator',
            'status' => 'active',
        ]);
    }
}
