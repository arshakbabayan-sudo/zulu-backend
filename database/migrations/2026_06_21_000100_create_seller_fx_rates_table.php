<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Seller FX-rate model — foundation table.
 *
 * Arshak's decided currency model: customers pay at the OPERATOR's chosen
 * bank rate plus an ABSOLUTE per-unit margin, so ZULU takes no FX risk.
 *
 * A `seller_fx_rates` row is a per-seller currency SETTING (not a rate
 * history). It says, for a given scope (platform | operator | agent) and
 * target currency (the currency the customer is charged in, default AMD):
 *   - which base rate to use — `source = 'cba'` (auto Central-Bank rate from
 *     exchange_rates) or `source = 'manual'` (operator-entered manual_rates)
 *   - the ABSOLUTE margin ADDED to the per-unit rate (rate 400 + margin 2 =
 *     402 AMD per 1 USD)
 *
 * Precedence at resolve time: agent → operator → platform; first active wins.
 *
 * This is purely additive foundation (data layer). Booking/payment wiring
 * and admin UI come later; at booking the resolved rate is snapshotted so
 * the customer-paid amount is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_fx_rates')) {
            return;
        }

        Schema::create('seller_fx_rates', function (Blueprint $table): void {
            $table->bigIncrements('id');

            // 'platform' | 'operator' | 'agent'
            $table->string('scope', 16);

            // The operator OR agent company; NULL for platform scope.
            $table->unsignedBigInteger('company_id')->nullable();
            $table->foreign('company_id')
                ->references('id')->on('companies')
                ->nullOnDelete();

            // The currency the customer is charged in.
            $table->string('target_currency', 3)->default('AMD');

            // 'cba' (Central Bank auto rate) | 'manual' (operator-entered).
            $table->string('source', 16)->default('cba');

            // ABSOLUTE amount ADDED to the per-unit rate
            // (e.g. rate 400 + margin 2 = 402 AMD per 1 USD).
            $table->decimal('margin', 14, 4)->default(0);

            // When source='manual': { "USD": "400.0000", "EUR": "430.0000" }
            // per-source-currency rate to the target currency.
            $table->json('manual_rates')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(
                ['scope', 'company_id', 'target_currency'],
                'seller_fx_rates_scope_company_target_uq'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_fx_rates');
    }
};
