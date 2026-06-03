<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherVerificationLog;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminVoucherControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_returns_403_for_non_admin(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/platform-admin/vouchers');

        $response->assertStatus(403);
    }

    public function test_index_lists_paginated_vouchers_for_admin(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->createVoucher();
        $this->createVoucher();
        $this->createVoucher();

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/vouchers?per_page=2');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('meta.total', 3);
        $response->assertJsonPath('meta.per_page', 2);
        $response->assertJsonCount(2, 'data');
    }

    public function test_index_filters_by_status(): void
    {
        $admin = $this->createPlatformAdmin();
        $issued = $this->createVoucher(['status' => 'issued', 'voucher_number' => 'V-FILT-ISS']);
        $void = $this->createVoucher(['status' => 'void', 'voucher_number' => 'V-FILT-VD']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/vouchers?status=void');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.voucher_number', $void->voucher_number);
    }

    public function test_index_filters_by_search_term_q(): void
    {
        $admin = $this->createPlatformAdmin();
        $this->createVoucher(['voucher_number' => 'V-NEEDLE-001', 'holder_name' => 'Random A']);
        $this->createVoucher(['voucher_number' => 'V-OTHER-001', 'holder_name' => 'Mr Needle']);
        $this->createVoucher(['voucher_number' => 'V-MISS-001', 'holder_name' => 'Random B']);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/vouchers?q=needle');

        $response->assertOk();
        $response->assertJsonPath('meta.total', 2);
    }

    public function test_show_returns_detail_with_verification_logs(): void
    {
        $admin = $this->createPlatformAdmin();
        $voucher = $this->createVoucher();
        VoucherVerificationLog::query()->create([
            'voucher_id' => $voucher->id,
            'scanned_at' => now(),
            'ip' => '203.0.113.5',
            'result' => 'valid',
        ]);

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/vouchers/'.$voucher->id);

        $response->assertOk();
        $response->assertJsonPath('data.voucher.id', $voucher->id);
        $response->assertJsonCount(1, 'data.verification_logs');
    }

    public function test_show_returns_404_for_unknown_voucher(): void
    {
        $admin = $this->createPlatformAdmin();
        $unknown = '00000000-0000-0000-0000-000000000000';

        Sanctum::actingAs($admin);
        $response = $this->getJson('/api/platform-admin/vouchers/'.$unknown);

        $response->assertStatus(404);
    }

    public function test_void_action_flips_status_and_returns_voucher(): void
    {
        $admin = $this->createPlatformAdmin();
        $voucher = $this->createVoucher(['status' => 'issued']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/vouchers/'.$voucher->id.'/void');

        $response->assertOk();
        $response->assertJsonPath('data.status', 'void');
        $this->assertSame('void', $voucher->fresh()->status);
    }

    public function test_void_action_rejects_already_void_voucher(): void
    {
        $admin = $this->createPlatformAdmin();
        $voucher = $this->createVoucher(['status' => 'void']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/vouchers/'.$voucher->id.'/void');

        $response->assertStatus(422);
    }

    public function test_reissue_creates_new_voucher_and_marks_original(): void
    {
        $admin = $this->createPlatformAdmin();
        $voucher = $this->createVoucher(['status' => 'issued']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/vouchers/'.$voucher->id.'/reissue', [
            'holder_name' => 'Corrected Name',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', 'issued');
        $response->assertJsonPath('data.holder_name', 'Corrected Name');
        $response->assertJsonPath('data.reissued_from_id', $voucher->id);
        $this->assertSame('reissued', $voucher->fresh()->status);
    }

    public function test_reissue_validates_language_field(): void
    {
        $admin = $this->createPlatformAdmin();
        $voucher = $this->createVoucher(['status' => 'issued']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/vouchers/'.$voucher->id.'/reissue', [
            'language' => 'fr',
        ]);

        $response->assertStatus(422);
    }

    public function test_reissue_rejects_void_voucher(): void
    {
        $admin = $this->createPlatformAdmin();
        $voucher = $this->createVoucher(['status' => 'void']);

        Sanctum::actingAs($admin);
        $response = $this->postJson('/api/platform-admin/vouchers/'.$voucher->id.'/reissue');

        $response->assertStatus(422);
    }

    private function createPlatformAdmin(): User
    {
        // RbacBootstrapSeeder creates admin@zulu.local as super_admin; reuse it.
        $this->seed(RbacBootstrapSeeder::class);

        return User::query()->where('email', 'admin@zulu.local')->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVoucher(array $overrides = []): Voucher
    {
        $company = Company::query()->create([
            'name' => 'Admin Test Co '.str()->random(6),
            'type' => 'operator',
        ]);

        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-ADM-'.str()->random(4),
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
            'voucher_number' => 'V-ADM-'.str()->random(6),
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Admin Test Holder',
            'service_snapshot' => ['note' => 'admin test'],
            'qr_token' => bin2hex(random_bytes(32)),
            'status' => 'issued',
            'language' => 'en',
        ], $overrides));
    }
}
