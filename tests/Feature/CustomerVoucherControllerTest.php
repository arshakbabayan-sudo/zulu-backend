<?php

namespace Tests\Feature;

use App\Mail\VoucherIssuedMail;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Voucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerVoucherControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_index_returns_only_authenticated_users_vouchers(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        $aliceVoucher = $this->createVoucherFor($alice);
        $this->createVoucherFor($bob); // not visible to alice

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/customer/vouchers');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.voucher_number', $aliceVoucher->voucher_number);
    }

    public function test_index_hides_reissued_vouchers(): void
    {
        $user = User::factory()->create();
        $original = $this->createVoucherFor($user);
        $original->status = 'reissued';
        $original->save();
        $this->createVoucherFor($user); // active one

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/customer/vouchers');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/customer/vouchers');

        $response->assertStatus(401);
    }

    public function test_download_returns_404_when_voucher_belongs_to_other_user(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobVoucher = $this->createVoucherFor($bob);

        Sanctum::actingAs($alice);
        $response = $this->getJson('/api/customer/vouchers/'.$bobVoucher->id.'/download');

        $response->assertNotFound();
    }

    public function test_download_streams_existing_pdf(): void
    {
        $user = User::factory()->create();
        $voucher = $this->createVoucherFor($user);

        // Pre-place a PDF on the fake disk
        Storage::disk('local')->put('vouchers/'.$voucher->id.'.pdf', '%PDF-1.4 test');
        $voucher->pdf_url = 'vouchers/'.$voucher->id.'.pdf';
        $voucher->save();

        Sanctum::actingAs($user);
        $response = $this->get('/api/customer/vouchers/'.$voucher->id.'/download');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_download_lazy_renders_pdf_when_pdf_url_missing(): void
    {
        $user = User::factory()->create();
        $voucher = $this->createVoucherFor($user);
        $this->assertNull($voucher->pdf_url);

        Sanctum::actingAs($user);
        $response = $this->get('/api/customer/vouchers/'.$voucher->id.'/download');

        $response->assertOk();
        $voucher->refresh();
        $this->assertNotNull($voucher->pdf_url);
        Storage::disk('local')->assertExists($voucher->pdf_url);
    }

    public function test_resend_dispatches_voucher_mail(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => 'test-resend@example.com']);
        $voucher = $this->createVoucherFor($user);

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/customer/vouchers/'.$voucher->id.'/resend');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        Mail::assertQueued(VoucherIssuedMail::class, fn ($m) => $m->hasTo('test-resend@example.com'));
    }

    public function test_resend_returns_404_when_voucher_belongs_to_other_user(): void
    {
        Mail::fake();

        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $bobVoucher = $this->createVoucherFor($bob);

        Sanctum::actingAs($alice);
        $response = $this->postJson('/api/customer/vouchers/'.$bobVoucher->id.'/resend');

        $response->assertNotFound();
        Mail::assertNothingQueued();
    }

    private function createVoucherFor(User $user): Voucher
    {
        $company = Company::query()->create([
            'name' => 'Cust Test Co '.str()->random(6),
            'type' => 'operator',
        ]);

        $order = Order::query()->create([
            'order_number' => 'ZULU-CUST-'.str()->random(4),
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

        return Voucher::query()->create([
            'voucher_number' => 'V-CUST-'.str()->random(6),
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'service_type' => 'hotel',
            'issuer_company_id' => $company->id,
            'holder_name' => 'Customer Test',
            'service_snapshot' => ['note' => 'customer test'],
            'qr_token' => bin2hex(random_bytes(32)),
            'status' => 'issued',
            'language' => 'en',
        ]);
    }
}
