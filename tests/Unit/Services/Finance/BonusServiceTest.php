<?php

namespace Tests\Unit\Services\Finance;

use App\Models\Bonus;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\Finance\BonusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BonusServiceTest extends TestCase
{
    use RefreshDatabase;

    private BonusService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BonusService;
    }

    private function makeCompany(array $attrs = []): Company
    {
        return Company::query()->create(array_merge([
            'name' => 'Test Co',
            'type' => 'agency',
            'country' => 'Armenia',
        ], $attrs));
    }

    private function makeOrder(Company $company, array $attrs = []): Order
    {
        return Order::query()->create(array_merge([
            'order_number' => 'ORD-'.uniqid(),
            'company_id' => $company->id,
            'buyer_type' => 'company',
            'status' => 'paid',
            'currency' => 'USD',
            'subtotal' => '100.00',
            'tax' => '0.00',
            'total' => '100.00',
        ], $attrs));
    }

    private function makeInvoice(Order $order, array $attrs = []): Invoice
    {
        return Invoice::query()->create(array_merge([
            'order_id' => $order->id,
            'unique_booking_reference' => 'INV-'.uniqid(),
            'total_amount' => '100.00',
            'currency' => 'USD',
            'status' => Invoice::STATUS_PAID,
        ], $attrs));
    }

    public function test_returns_null_when_invoice_not_paid(): void
    {
        $co = $this->makeCompany();
        $order = $this->makeOrder($co);
        $invoice = $this->makeInvoice($order, ['status' => Invoice::STATUS_PENDING]);

        $this->assertNull($this->service->calculateAndRecordBonus($invoice));
        $this->assertSame(0, Bonus::query()->count());
    }

    public function test_returns_null_when_order_has_no_company(): void
    {
        $co = $this->makeCompany();
        $order = $this->makeOrder($co);
        $order->company_id = null;
        $order->save();

        $invoice = $this->makeInvoice($order);

        $this->assertNull($this->service->calculateAndRecordBonus($invoice));
    }

    public function test_returns_existing_bonus_idempotently(): void
    {
        $co = $this->makeCompany();
        $order = $this->makeOrder($co);
        $invoice = $this->makeInvoice($order);

        $first = $this->service->calculateAndRecordBonus($invoice);
        $second = $this->service->calculateAndRecordBonus($invoice);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Bonus::query()->count());
    }

    public function test_creates_bonus_using_default_one_percent(): void
    {
        config(['zulu_platform.finance.default_bonus_percent' => 1.0]);

        $co = $this->makeCompany();
        $order = $this->makeOrder($co);
        $invoice = $this->makeInvoice($order, ['total_amount' => '500.00']);

        $bonus = $this->service->calculateAndRecordBonus($invoice);

        $this->assertNotNull($bonus);
        $this->assertSame(Bonus::STATUS_PENDING, $bonus->status);
        $this->assertSame('5.00', (string) $bonus->amount);
        $this->assertSame($co->id, $bonus->company_id);
        $this->assertSame($invoice->id, $bonus->invoice_id);
    }

    public function test_returns_null_when_resolved_percent_is_zero(): void
    {
        config(['zulu_platform.finance.default_bonus_percent' => 0.0]);

        $co = $this->makeCompany();
        $order = $this->makeOrder($co);
        $invoice = $this->makeInvoice($order);

        $this->assertNull($this->service->calculateAndRecordBonus($invoice));
        $this->assertSame(0, Bonus::query()->count());
    }

    public function test_returns_null_when_computed_amount_rounds_to_zero(): void
    {
        config(['zulu_platform.finance.default_bonus_percent' => 1.0]);

        $co = $this->makeCompany();
        $order = $this->makeOrder($co);
        $invoice = $this->makeInvoice($order, ['total_amount' => '0.10']);

        $this->assertNull($this->service->calculateAndRecordBonus($invoice));
    }

    private function makeBonus(Company $company, string $amount, string $status): Bonus
    {
        $order = $this->makeOrder($company);
        $invoice = $this->makeInvoice($order);

        return Bonus::query()->create([
            'company_id' => $company->id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'status' => $status,
            'description' => 'fixture',
        ]);
    }

    public function test_make_bonus_available_promotes_pending_to_available(): void
    {
        $co = $this->makeCompany();
        $bonus = $this->makeBonus($co, '12.34', Bonus::STATUS_PENDING);

        $this->assertTrue($this->service->makeBonusAvailable($bonus->id));
        $this->assertSame(Bonus::STATUS_AVAILABLE, $bonus->fresh()->status);
    }

    public function test_make_bonus_available_returns_false_when_already_available(): void
    {
        $co = $this->makeCompany();
        $bonus = $this->makeBonus($co, '12.34', Bonus::STATUS_AVAILABLE);

        $this->assertFalse($this->service->makeBonusAvailable($bonus->id));
    }

    public function test_company_summary_aggregates_by_status(): void
    {
        $co = $this->makeCompany();
        $other = $this->makeCompany();

        $this->makeBonus($co, '10.00', Bonus::STATUS_PENDING);
        $this->makeBonus($co, '5.00', Bonus::STATUS_AVAILABLE);
        $this->makeBonus($co, '3.00', Bonus::STATUS_AVAILABLE);
        $this->makeBonus($co, '2.00', Bonus::STATUS_REDEEMED);
        $this->makeBonus($other, '999.00', Bonus::STATUS_AVAILABLE);

        $summary = $this->service->getCompanyBonusSummary($co->id);

        $this->assertSame(20.0, $summary['total_earned']);
        $this->assertSame(8.0, $summary['total_available']);
        $this->assertSame(2.0, $summary['total_redeemed']);
    }

    public function test_company_summary_returns_zeros_for_unknown_company(): void
    {
        $summary = $this->service->getCompanyBonusSummary(99999);

        $this->assertSame(0.0, $summary['total_earned']);
        $this->assertSame(0.0, $summary['total_available']);
        $this->assertSame(0.0, $summary['total_redeemed']);
    }
}
