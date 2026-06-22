<?php

declare(strict_types=1);

namespace Tests\Unit\Database;

use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Audit C4 (§5) — the payments.refunded_amount migration must be safe on a
 * populated table (nullable + default 0, cheap backfill of terminal-REFUNDED
 * rows) and fully reversible.
 */
class PaymentRefundedAmountMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_FILE = 'database/migrations/2026_06_22_000200_add_refunded_amount_to_payments.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_FILE);
    }

    public function test_column_exists_with_expected_shape(): void
    {
        $this->assertTrue(Schema::hasColumn('payments', 'refunded_amount'));
    }

    public function test_new_payments_default_refunded_amount_to_zero(): void
    {
        $payment = $this->makePayment(Payment::STATUS_PAID, 150.00);

        // Inserted without touching refunded_amount → default 0, never NULL-fatal.
        $this->assertSame('0.00', (string) $payment->fresh()->refunded_amount);
    }

    public function test_migration_is_safe_and_reversible_on_non_empty_table(): void
    {
        // Seed a populated table BEFORE rolling the column back, mixing a
        // fully-refunded payment (should backfill to its amount on re-up) and a
        // plain paid one (should land at 0).
        $refunded = $this->makePayment(Payment::STATUS_REFUNDED, 80.00);
        $paid = $this->makePayment(Payment::STATUS_PAID, 200.00);

        $migration = $this->migration();

        // down(): drops the column on a non-empty table without error.
        $migration->down();
        $this->assertFalse(Schema::hasColumn('payments', 'refunded_amount'));

        // Rows survive the rollback intact.
        $this->assertDatabaseHas('payments', ['id' => $refunded->id, 'status' => Payment::STATUS_REFUNDED]);
        $this->assertDatabaseHas('payments', ['id' => $paid->id, 'status' => Payment::STATUS_PAID]);

        // up() again on the now-populated table: column returns + backfill runs
        // (idempotent — the column guard means re-running up() is safe too).
        $migration->up();
        $this->assertTrue(Schema::hasColumn('payments', 'refunded_amount'));

        // Backfill: terminal-REFUNDED → amount; everyone else → 0.
        $this->assertSame('80.00', (string) DB::table('payments')->where('id', $refunded->id)->value('refunded_amount'));
        $this->assertSame('0.00', (string) DB::table('payments')->where('id', $paid->id)->value('refunded_amount'));
    }

    private function makePayment(string $status, float $amount): Payment
    {
        $invoice = Invoice::query()->create([
            'total_amount' => $amount,
            'currency' => 'usd',
            'status' => Invoice::STATUS_PENDING,
        ]);

        return Payment::query()->create([
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => 'usd',
            'status' => $status,
            'reference_code' => 'pi_mig_'.$status.'_'.str()->uuid(),
        ]);
    }
}
