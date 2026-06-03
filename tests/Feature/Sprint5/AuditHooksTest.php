<?php

namespace Tests\Feature\Sprint5;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\ContractTemplate;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Contracts\ContractService;
use App\Services\Payments\PaymentService;
use App\Services\Vouchers\VoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuditHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_contract_send_writes_audit_log(): void
    {
        $admin = User::factory()->create();
        $template = $this->makeTemplate();
        $seller = $this->makeCompany();

        $contract = app(ContractService::class)->generateFromTemplate(
            $template, null, $seller, $admin,
            ['commission_clause' => [], 'payment_terms' => []],
        );

        app(ContractService::class)->send($contract, $admin);

        $log = AuditLog::query()
            ->where('subject_type', 'Contract')
            ->where('subject_id', $contract->id)
            ->where('action', 'sent')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('contract', $log->category);
        $this->assertSame($admin->id, $log->actor_id);
    }

    public function test_contract_terminate_writes_audit_log_with_reason(): void
    {
        $admin = User::factory()->create();
        $template = $this->makeTemplate();
        $seller = $this->makeCompany();

        $contract = app(ContractService::class)->generateFromTemplate(
            $template, null, $seller, $admin,
            ['commission_clause' => [], 'payment_terms' => []],
        );

        app(ContractService::class)->terminate($contract, 'Mutual cancel', $admin);

        $log = AuditLog::query()
            ->where('subject_type', 'Contract')
            ->where('subject_id', $contract->id)
            ->where('action', 'terminated')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('Mutual cancel', $log->context['reason']);
    }

    public function test_voucher_issue_writes_audit_log(): void
    {
        $order = $this->makeOrderWithItem();

        app(VoucherService::class)->issueForOrder($order);

        $log = AuditLog::query()
            ->where('subject_type', 'Voucher')
            ->where('action', 'issued')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('data_change', $log->category);
        $this->assertArrayHasKey('voucher_number', $log->context);
    }

    public function test_voucher_void_writes_audit_log(): void
    {
        $order = $this->makeOrderWithItem();
        $voucher = app(VoucherService::class)->issueForOrder($order)->first();

        app(VoucherService::class)->void($voucher);

        $log = AuditLog::query()
            ->where('subject_type', 'Voucher')
            ->where('subject_id', $voucher->id)
            ->where('action', 'voided')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('issued', $log->changes['status']['from']);
        $this->assertSame('void', $log->changes['status']['to']);
    }

    public function test_payment_failed_writes_financial_audit_log(): void
    {
        $payment = $this->makePayment();

        app(PaymentService::class)->markFailed($payment);

        $log = AuditLog::query()
            ->where('subject_type', 'Payment')
            ->where('subject_id', $payment->id)
            ->where('action', 'failed')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('financial', $log->category);
    }

    public function test_audit_chain_remains_intact_after_multiple_service_calls(): void
    {
        $admin = User::factory()->create();
        $template = $this->makeTemplate();
        $seller = $this->makeCompany();

        // 3 service mutations → ≥3 audit rows expected (plus voucher.issued/voided in chain).
        $contract = app(ContractService::class)->generateFromTemplate(
            $template, null, $seller, $admin,
            ['commission_clause' => [], 'payment_terms' => []],
        );
        app(ContractService::class)->send($contract, $admin);
        app(ContractService::class)->terminate($contract->fresh(), 'reason', $admin);

        $service = app(AuditService::class);
        $corrupted = $service->verifyIntegrity();

        $this->assertSame([], $corrupted, 'Audit chain must be intact across multiple service calls.');
        $this->assertGreaterThanOrEqual(2, AuditLog::query()->where('subject_type', 'Contract')->count());
    }

    private function makeTemplate(): ContractTemplate
    {
        return ContractTemplate::query()->create([
            'name' => 'Audit Hook T '.str()->random(4),
            'type' => 'platform',
            'language' => 'en',
            'version' => 1,
            'body' => 'Body',
            'variables' => [],
            'active' => true,
        ]);
    }

    private function makeCompany(): Company
    {
        return Company::query()->create([
            'name' => 'Audit Hook Co '.str()->random(6),
            'type' => 'operator',
        ]);
    }

    private function makeOrderWithItem(): Order
    {
        $company = $this->makeCompany();
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-AH-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
            'status' => 'paid',
            'currency' => 'EUR',
            'subtotal' => 50,
            'tax' => 0,
            'total' => 50,
            'metadata' => [],
        ]);

        OrderItem::query()->create([
            'order_id' => $order->id,
            'item_type' => 'hotel',
            'item_id' => 1,
            'currency' => 'EUR',
            'unit_price' => 50,
            'total' => 50,
            'status' => 'confirmed',
        ]);

        return $order->fresh(['items', 'user']);
    }

    private function makePayment(): Payment
    {
        $company = $this->makeCompany();
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_number' => 'ZULU-AHP-'.str()->random(4),
            'user_id' => $user->id,
            'company_id' => $company->id,
            'buyer_type' => 'client',
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
            'invoice_number' => 'INV-AH-'.str()->random(4),
            'company_id' => $company->id,
            'user_id' => $user->id,
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
