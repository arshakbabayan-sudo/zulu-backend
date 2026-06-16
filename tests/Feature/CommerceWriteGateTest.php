<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RbacBootstrapSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * §7 server-gate lock-in for the commerce write endpoints (invoices / payments).
 *
 * These routes are gated INSIDE their controllers via AuthorizesCommerceAccess
 * (ensureCommerceAccess) + AdminAccessService::companyIdsForCommerceList — NOT
 * with route-level `permission:*` middleware. Route middleware was deliberately
 * NOT added because it would 403 `platform_admin` staff, whom the controller
 * gate explicitly allows (companyIdsForCommerceList grants them every company).
 *
 * This test locks the controller-level gate so a refactor can't silently drop
 * it: a logged-in user WITHOUT the permission must get 403, while a super_admin
 * (whom the gate allows) must pass through (not 403).
 */
class CommerceWriteGateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Company, 1: Order, 2: Invoice} */
    private function orderWithInvoice(Company $company, string $invoiceStatus): array
    {
        $order = Order::query()->create([
            'order_number' => 'ORD-'.str()->uuid(),
            'user_id' => User::factory()->create()->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'pending_payment',
            'currency' => 'usd',
            'subtotal' => 100,
            'tax' => 0,
            'total' => 100,
        ]);

        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'total_amount' => 100.00,
            'currency' => 'usd',
            'status' => $invoiceStatus,
        ]);

        return [$company, $order, $invoice];
    }

    public function test_invoice_pay_is_forbidden_for_user_without_permission(): void
    {
        $company = Company::query()->create(['name' => 'Gate Co', 'type' => 'agency']);
        [, , $invoice] = $this->orderWithInvoice($company, Invoice::STATUS_ISSUED);

        Sanctum::actingAs(User::factory()->create()); // plain user, no role/permission

        $this->postJson("/api/invoices/{$invoice->id}/pay")->assertStatus(403);
    }

    public function test_payment_refund_is_forbidden_for_user_without_permission(): void
    {
        $company = Company::query()->create(['name' => 'Gate Co 2', 'type' => 'agency']);
        [, , $invoice] = $this->orderWithInvoice($company, Invoice::STATUS_ISSUED);

        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => 100.00,
            'currency' => 'usd',
            'status' => Payment::STATUS_PAID,
            'reference_code' => 'pi_test_'.str()->uuid(),
        ]);

        Sanctum::actingAs(User::factory()->create()); // plain user, no role/permission

        $this->postJson("/api/payments/{$payment->id}/refund")->assertStatus(403);
    }

    public function test_super_admin_passes_the_invoice_pay_gate(): void
    {
        $this->seed(RbacBootstrapSeeder::class);
        $company = Company::query()->where('name', 'ZULU Test Agency')->firstOrFail();
        [, , $invoice] = $this->orderWithInvoice($company, Invoice::STATUS_ISSUED);

        Sanctum::actingAs(User::query()->where('email', 'admin@zulu.local')->firstOrFail());

        // Super is allowed by the commerce gate → it must NOT be the gate that
        // blocks (403). Downstream may 200; the point is the gate let it through.
        $response = $this->postJson("/api/invoices/{$invoice->id}/pay");
        $this->assertNotSame(403, $response->status());
    }
}
