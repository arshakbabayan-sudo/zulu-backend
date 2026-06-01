<?php

namespace App\Services\Payments;

use App\Models\Company;
use App\Models\PaymentLog;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * P0-1 step 1.1 — Stripe Connect (Express) connected-account lifecycle.
 *
 * Separate from {@see Gateways\StripeGateway} (which handles transactional
 * PaymentIntents/refunds) because Connect accounts are a different domain:
 * one Stripe Account per seller company, created once, mutated via
 * Stripe-hosted onboarding, then mirrored back into our `companies` row.
 *
 * Marketplace pattern (chosen 2026-06-01): ZULU is merchant of record.
 * Customer pays the platform, platform takes application_fee, the rest
 * lands on the operator's connected Stripe account via a transfer (step
 * 1.2). To make that work, the connected account needs the `transfers`
 * capability — requested at creation below.
 *
 * Country handling: Stripe Connect supports ~47 countries; Armenia is
 * NOT one of them. For step 1.1 test runs we fall back to the configured
 * default (US) when the company's country isn't a supported ISO-2 code.
 * Real-world Armenian-operator onboarding is a separate product call
 * (Stripe Atlas vs alternative payout) flagged for step 1.5+.
 */
class StripeConnectService
{
    private ?StripeClient $stripe = null;

    private ?string $configurationError = null;

    public function __construct()
    {
        $stripeSecret = trim((string) config('payment.stripe.secret', ''));
        if ($stripeSecret === '') {
            $this->configurationError = 'STRIPE_SECRET is missing — Stripe Connect cannot be used.';

            return;
        }

        $this->stripe = new StripeClient($stripeSecret);
    }

    public function isConfigured(): bool
    {
        return $this->configurationError === null;
    }

    public function configurationError(): ?string
    {
        return $this->configurationError;
    }

    /**
     * Ensure the company has a Stripe Connect Account. Creates one with
     * type=express if missing, persists the ID. Idempotent — safe to call
     * before every onboarding link request.
     *
     * @return array{success: bool, account_id?: string, error?: string}
     */
    public function ensureAccount(Company $company): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => $this->configurationError];
        }

        if (! empty($company->stripe_connect_id)) {
            return ['success' => true, 'account_id' => $company->stripe_connect_id];
        }

        $country = $this->resolveCountry($company);

        try {
            $account = $this->stripe->accounts->create([
                'type' => 'express',
                'country' => $country,
                'email' => $this->resolveEmail($company),
                'capabilities' => [
                    'card_payments' => ['requested' => true],
                    'transfers' => ['requested' => true],
                ],
                'business_type' => 'company',
                'metadata' => [
                    'zulu_company_id' => (string) $company->id,
                    'zulu_company_name' => (string) $company->name,
                ],
            ]);

            $company->forceFill([
                'stripe_connect_id' => $account->id,
                'stripe_charges_enabled' => (bool) ($account->charges_enabled ?? false),
                'stripe_payouts_enabled' => (bool) ($account->payouts_enabled ?? false),
                'stripe_details_submitted' => (bool) ($account->details_submitted ?? false),
            ])->save();

            $this->logEvent($company, 'connect.account.created', $account->id, $account->toArray());

            return ['success' => true, 'account_id' => $account->id];
        } catch (ApiErrorException $e) {
            Log::error('Stripe Connect ensureAccount failed', [
                'company_id' => $company->id,
                'country' => $country,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Create a Stripe-hosted Express onboarding URL. The seller clicks it,
     * fills the Stripe form (bank, KYC), and is sent back to $returnUrl.
     * If they bail mid-flow, $refreshUrl is where Stripe sends them so we
     * can issue a fresh link.
     *
     * @return array{success: bool, url?: string, expires_at?: int, error?: string}
     */
    public function createOnboardingLink(Company $company, string $returnUrl, string $refreshUrl): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => $this->configurationError];
        }

        $ensure = $this->ensureAccount($company);
        if (! ($ensure['success'] ?? false)) {
            return ['success' => false, 'error' => $ensure['error'] ?? 'Failed to ensure Stripe account.'];
        }

        try {
            $link = $this->stripe->accountLinks->create([
                'account' => $ensure['account_id'],
                'return_url' => $returnUrl,
                'refresh_url' => $refreshUrl,
                'type' => 'account_onboarding',
            ]);

            $this->logEvent($company, 'connect.account_link.created', $ensure['account_id'], [
                'url' => $link->url,
                'expires_at' => $link->expires_at,
            ]);

            return [
                'success' => true,
                'url' => $link->url,
                'expires_at' => $link->expires_at,
            ];
        } catch (ApiErrorException $e) {
            Log::error('Stripe Connect createOnboardingLink failed', [
                'company_id' => $company->id,
                'stripe_account_id' => $ensure['account_id'],
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Pull the latest Account state from Stripe and mirror the three
     * boolean flags onto the company row. Called from the GET /status
     * endpoint and (later) from the account.updated webhook handler.
     *
     * @return array{
     *   success: bool,
     *   connected: bool,
     *   charges_enabled?: bool,
     *   payouts_enabled?: bool,
     *   details_submitted?: bool,
     *   error?: string,
     * }
     */
    public function syncAccountStatus(Company $company): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'connected' => false, 'error' => $this->configurationError];
        }

        if (empty($company->stripe_connect_id)) {
            return [
                'success' => true,
                'connected' => false,
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'details_submitted' => false,
            ];
        }

        try {
            $account = $this->stripe->accounts->retrieve($company->stripe_connect_id);

            $chargesEnabled = (bool) ($account->charges_enabled ?? false);
            $payoutsEnabled = (bool) ($account->payouts_enabled ?? false);
            $detailsSubmitted = (bool) ($account->details_submitted ?? false);

            $company->forceFill([
                'stripe_charges_enabled' => $chargesEnabled,
                'stripe_payouts_enabled' => $payoutsEnabled,
                'stripe_details_submitted' => $detailsSubmitted,
            ])->save();

            return [
                'success' => true,
                'connected' => true,
                'charges_enabled' => $chargesEnabled,
                'payouts_enabled' => $payoutsEnabled,
                'details_submitted' => $detailsSubmitted,
            ];
        } catch (ApiErrorException $e) {
            Log::error('Stripe Connect syncAccountStatus failed', [
                'company_id' => $company->id,
                'stripe_account_id' => $company->stripe_connect_id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'connected' => true,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Pick an ISO-3166 alpha-2 country code Stripe Connect accepts. The
     * company.country column may hold anything; we accept a 2-letter code
     * verbatim and fall back to the configured default otherwise.
     */
    private function resolveCountry(Company $company): string
    {
        $default = strtoupper((string) config('payment.stripe.connect_default_country', 'US'));

        $raw = trim((string) ($company->country ?? ''));
        if (strlen($raw) === 2 && ctype_alpha($raw)) {
            return strtoupper($raw);
        }

        return $default !== '' ? $default : 'US';
    }

    private function resolveEmail(Company $company): ?string
    {
        $owner = $company->users()->orderBy('user_company.id')->first();

        return $owner?->email;
    }

    /**
     * Log a Connect lifecycle event into payment_logs (re-using the
     * existing audit table — payment_id null since this isn't tied to a
     * specific Payment row).
     */
    private function logEvent(Company $company, string $eventType, ?string $reference, array $payload): void
    {
        try {
            PaymentLog::query()->create([
                'event_type' => $eventType,
                'gateway' => 'stripe',
                'gateway_reference' => $reference,
                'response_payload' => array_merge(['zulu_company_id' => $company->id], $payload),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Stripe Connect log failed (non-fatal)', [
                'event_type' => $eventType,
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
