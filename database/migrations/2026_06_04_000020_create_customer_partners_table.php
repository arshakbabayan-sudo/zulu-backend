<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A B2C customer's ordered list of preferred sellers ("My partners").
 *
 * Seller-attribution architecture (2026-06-04, see
 * docs/blueprints/seller-attribution-architecture-2026-06-04.md). When Ani
 * (customer) browses, the price she sees on a product is her #1 partner's
 * price for that product; the picker reveals the rest. A partner is either an
 * AGENT (sells the supplier operator's product + own margin) or an OPERATOR
 * (buy direct). The list is per destination COUNTRY — Arshak's rule: max 10
 * partners per country (enforced in the controller, not the DB).
 *
 *   - rank: 1 = primary/default for that (customer, country); lower = higher up.
 *   - cascadeOnDelete on both FKs: removing the customer or the company drops
 *     the preference row (it's a pure relationship, no history to keep).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_partners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('partner_company_id')->constrained('companies')->cascadeOnDelete();
            // Destination/market country this preference applies to (e.g. "Armenia").
            $table->string('country');
            // 1 = primary (default price shown); lower number = higher preference.
            $table->unsignedSmallInteger('rank');
            $table->timestamps();

            $table->unique(['customer_user_id', 'partner_company_id', 'country'], 'customer_partners_unique');
            $table->index(['customer_user_id', 'country', 'rank']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_partners');
    }
};
