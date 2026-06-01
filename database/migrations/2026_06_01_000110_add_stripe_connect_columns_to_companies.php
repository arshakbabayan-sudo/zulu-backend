<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-1 step 1.1 — Stripe Connect connected accounts (Marketplace mode).
 *
 * Each operator/agent company connects its own Stripe Express account via
 * /api/companies/{id}/stripe-connect/onboarding-link. After the user
 * completes Stripe-hosted Express onboarding, transfers land on their
 * account; ZULU keeps the application_fee (commission) on the platform
 * account. See App\Services\Payments\StripeConnectService.
 *
 *   - stripe_connect_id          Stripe Account id (acct_...) once created.
 *                                NULL until the company starts onboarding.
 *   - stripe_charges_enabled     Stripe says "true" once the account can
 *                                accept charges (KYC complete enough).
 *   - stripe_payouts_enabled     Stripe says "true" once payouts to the
 *                                connected bank are unlocked.
 *   - stripe_details_submitted   Stripe says "true" once the account holder
 *                                finished the Express onboarding form. May
 *                                be true while charges/payouts still pending
 *                                Stripe review.
 *
 * Status booleans mirror what Stripe reports — synced on demand via
 * StripeConnectService::syncAccountStatus() and (in step 1.4) via
 * account.updated webhook.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->string('stripe_connect_id', 64)->nullable()->after('google_drive_connected_email');
            $table->boolean('stripe_charges_enabled')->default(false)->after('stripe_connect_id');
            $table->boolean('stripe_payouts_enabled')->default(false)->after('stripe_charges_enabled');
            $table->boolean('stripe_details_submitted')->default(false)->after('stripe_payouts_enabled');

            $table->index('stripe_connect_id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropIndex(['stripe_connect_id']);
            $table->dropColumn([
                'stripe_connect_id',
                'stripe_charges_enabled',
                'stripe_payouts_enabled',
                'stripe_details_submitted',
            ]);
        });
    }
};
