<?php

/**
 * ZULU HTTP JSON API (`/api` prefix via bootstrap).
 *
 * Surface map (read this before changing route order):
 * - Auth/session: top-level login/register/logout/password/company apply (mixed public/Sanctum).
 * - Versioned public: `v1/health`, canonical `v1/discovery/*` (see config/zulu_platform.php).
 * - Public reads: localization GETs, reviews GET, catalog/*, storefront `GET packages/{id}` + `GET packages/{id}/pricing`
 *   (throttle:api_public; same path is not used for authenticated package CRUD — list/mutations live in Sanctum group).
 * - Compatibility: unversioned `discovery/*` = same handlers as v1 + DeprecateLegacyDiscoveryApi (Link / optional Sunset).
 * - Webhook: `POST payments/webhook` (no Sanctum).
 * - Sanctum bulk: everything from email verification through `locations/*`, `support/tickets/*`, `rollout/admin-next/screen-view` (observability), `platform-admin/banners` (seller/operator/platform-admin/operator/*).
 *
 * Visa applications: customer endpoints under `visa-applications/*`, admin under `platform-admin/visa-applications/*` (Sprint 63).
 */

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\Admin\PageController;
use App\Http\Controllers\Api\AdminAuditLogController;
use App\Http\Controllers\Api\AdminCartController;
use App\Http\Controllers\Api\AdminConnectionController;
use App\Http\Controllers\Api\AdminContractController;
use App\Http\Controllers\Api\AdminContractTemplateController;
use App\Http\Controllers\Api\AdminInsuranceController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminLocationController;
use App\Http\Controllers\Api\AdminLoyaltyController;
use App\Http\Controllers\Api\AdminNewsletterController;
use App\Http\Controllers\Api\AdminNotificationController;
use App\Http\Controllers\Api\AdminPackageSagaController;
use App\Http\Controllers\Api\AdminPlatformStatisticsController;
use App\Http\Controllers\Api\AdminRbacController;
use App\Http\Controllers\Api\AdminRolloutTelemetryController;
use App\Http\Controllers\Api\AdminSecurityController;
use App\Http\Controllers\Api\AdminVisaApplicationController;
use App\Http\Controllers\Api\AdminVoucherController;
use App\Http\Controllers\Api\AdminWebhookController;
use App\Http\Controllers\Api\AgentOperatorRequestController;
use App\Http\Controllers\Api\AIAssistantController;
use App\Http\Controllers\Api\AISearchController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BlockedDatesController;
use App\Http\Controllers\Api\BrandSettingsController;
use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\CasesController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CompanyApplicationController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\CustomerCartController;
use App\Http\Controllers\Api\CustomerInsuranceController;
use App\Http\Controllers\Api\CustomerLoyaltyController;
use App\Http\Controllers\Api\CustomerStatsController;
use App\Http\Controllers\Api\CustomerSupportController;
use App\Http\Controllers\Api\CustomerVoucherController;
use App\Http\Controllers\Api\CustomFieldsController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ErrorReportController;
use App\Http\Controllers\Api\ExcursionController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FlightController;
use App\Http\Controllers\Api\FooterController;
use App\Http\Controllers\Api\HeaderMenuController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\HeroTabsController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\ImportSessionController;
use App\Http\Controllers\Api\ImportUploadController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LocalizationController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\MediaUploadController;
use App\Http\Controllers\Api\Modules\UserVisaApiController;
use App\Http\Controllers\Api\NewsletterController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OAuthController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\OperatorCommissionController;
use App\Http\Controllers\Api\OperatorStatisticsController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PackageOrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\PlatformAdminBannerController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\PublicPageController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\SavedSearchController;
use App\Http\Controllers\Api\SellerConnectionController;
use App\Http\Controllers\Api\SellerContractController;
use App\Http\Controllers\Api\SellerWebhookController;
use App\Http\Controllers\Api\ServiceCatalogController;
use App\Http\Controllers\Api\StorefrontPackageController;
use App\Http\Controllers\Api\SubscriptionsController;
use App\Http\Controllers\Api\SupportAdminController;
use App\Http\Controllers\Api\TimeOffController;
use App\Http\Controllers\Api\TimePunchController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\TwoFactorController;
use App\Http\Controllers\Api\UnsubscribeController;
use App\Http\Controllers\Api\UserFavoritesController;
use App\Http\Controllers\Api\VisaController;
use App\Http\Middleware\DeprecateLegacyDiscoveryApi;
use App\Services\Infrastructure\PlatformReadinessService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

// H3 — deep health endpoint for external uptime monitors. Public, rate-limited.
// The basic Laravel `/up` route stays as the liveness probe.
Route::get('health/deep', [HealthController::class, 'deep'])->middleware('throttle:api_public');

// Phase 9.1 — Sentry smoke-test endpoint. Throws on purpose so the operator
// can verify that production exceptions land in Sentry after the DSN is set.
// Only runs when `?key=<SENTRY_TEST_KEY env>` matches (so it isn't a public DoS).
Route::get('sentry/test', function () {
    $expected = (string) config('app.sentry_test_key', env('SENTRY_TEST_KEY', ''));
    if ($expected === '' || request()->query('key') !== $expected) {
        return response()->json([
            'success' => false,
            'message' => 'Forbidden',
        ], 403);
    }
    throw new RuntimeException('Sentry smoke-test exception — if you see this in Sentry, the integration works.');
})->middleware('throttle:api_public');

// Phase 3.2 GDPR — universal unsubscribe link in marketing emails.
// Token in query string is encrypted+self-contained — no auth required.
Route::get('unsubscribe', [UnsubscribeController::class, 'handle'])
    ->middleware('throttle:api_public');

// Phase 3.3 GDPR — newsletter double opt-in confirmation endpoint.
Route::get('newsletter/confirm', [NewsletterController::class, 'confirm'])
    ->middleware('throttle:api_public');

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('companies/apply', [CompanyApplicationController::class, 'store'])->middleware('throttle:file-upload');
Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:password-reset');
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

// Phase 8 — social login (Google + Facebook OAuth via Laravel Socialite).
// The frontend redirects the browser to /redirect, provider sends back to
// /callback with code+state, we issue a Sanctum token and bounce to the
// frontend's /auth/oauth-success page.
Route::prefix('auth/oauth')->group(function (): void {
    Route::get('{provider}/redirect', [OAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'facebook'])
        ->middleware('throttle:login');
    Route::get('{provider}/callback', [OAuthController::class, 'callback'])
        ->whereIn('provider', ['google', 'facebook']);
});

$registerPublicDiscoveryRoutes = static function (): void {
    Route::get('search', [DiscoveryController::class, 'search'])->name('search');
    Route::get('offers/{id}', [DiscoveryController::class, 'show'])->whereNumber('id')->name('offer');
};

// Public webhook events catalog (PART 30)
Route::get('webhooks/events', [SellerWebhookController::class, 'supportedEvents'])->middleware('throttle:api_public');

// Public OpenAPI spec (Sprint 74). The actual endpoints below remain auth-gated;
// publishing the spec just lets integrators see the surface and generate clients.
Route::get('openapi.json', function () {
    $path = base_path('storage/app/openapi.json');
    if (! file_exists($path)) {
        return response()->json([
            'success' => false,
            'message' => 'Spec not generated yet. Run: php artisan api:generate-openapi',
        ], 404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/json',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->middleware('throttle:api_public');

// Public search helpers (PART 20)
Route::get('search/autocomplete', [SavedSearchController::class, 'autocomplete'])->middleware('throttle:api_public');
Route::get('search/popular', [SavedSearchController::class, 'popular'])->middleware('throttle:api_public');
Route::get('search/cross-suggest', [SavedSearchController::class, 'crossSuggest'])->middleware('throttle:api_public');

// Public location type-ahead — feeds the customer search blocks (header,
// HomeSearch, per-module search pages). No auth required.
Route::get('locations/search', [AdminLocationController::class, 'searchPublic'])->middleware('throttle:api_public');

// AI Search (PART 34 Phase 1) — natural-language → Claude parser → discovery search
Route::post('ai/search', [AISearchController::class, 'search'])
    ->middleware('throttle:10,1');

// AI deeper-track (PART 34 Phase 4.2): public chat (rate-limited, no auth
// required so the homepage assistant works for browsers) + authenticated
// recommendations (Sanctum + per-user history).
Route::post('ai/chat', [AIAssistantController::class, 'chat'])
    ->middleware('throttle:20,1');
Route::get('ai/recommendations', [AIAssistantController::class, 'recommendations'])
    ->middleware(['auth:sanctum', 'throttle:30,1']);

// Image upload endpoint for admin/operator forms.
// Sanctum-authenticated + per-user rate limit. Files land on the public
// disk under uploads/{section}/; the symlink at public/storage exposes
// them at https://api.zulu.am/storage/uploads/...
Route::post('media/upload', [MediaUploadController::class, 'upload'])
    ->middleware(['auth:sanctum', 'throttle:file-upload']);

// Self-hosted frontend error capture (PART 31, Sprint 67)
Route::post('errors/report', [ErrorReportController::class, 'store'])
    ->middleware('throttle:30,1');

Route::prefix('v1')->name('v1.')->group(function () use ($registerPublicDiscoveryRoutes) {
    Route::get('/health', function (PlatformReadinessService $readinessService) {
        return response()->json($readinessService->getHealthPayload());
    });

    // Canonical public discovery contract (pairs with config/zulu_platform.php api.version).
    Route::prefix('discovery')->middleware('throttle:api_public')->name('discovery.')->group($registerPublicDiscoveryRoutes);
});

// Public read endpoints used by admin Blade (API-driven UI) and storefronts.
Route::prefix('localization')->middleware('throttle:api_public')->group(function () {
    Route::get('languages', [LocalizationController::class, 'languages']);
    Route::get('translations', [LocalizationController::class, 'translations']);
    Route::get('templates/{event}', [LocalizationController::class, 'getTemplate']);
    Route::get('ui-translations', [LocalizationController::class, 'uiTranslations']);
});

Route::get('reviews', [ReviewController::class, 'getReviews'])->middleware('throttle:api_public');

Route::prefix('catalog')->middleware('throttle:api_public')->group(function () {
    Route::get('offers/{id}', [CatalogController::class, 'show'])->whereNumber('id');
    Route::get('offers', [CatalogController::class, 'offers']);
    Route::get('banners', [BannerController::class, 'index']);
});

// Storefront package detail + pricing (public; auth seller routes remain under auth:sanctum).
Route::prefix('packages')->middleware('throttle:api_public')->group(function () {
    Route::get('{package}/pricing', [StorefrontPackageController::class, 'pricing'])->whereNumber('package');
    Route::get('{package}', [StorefrontPackageController::class, 'show'])->whereNumber('package');
});

Route::get('pages/{slug}', [PublicPageController::class, 'show'])->middleware('throttle:api_public');

// GDPR — public endpoints (no auth — user comes from email link)
Route::post('account/delete-confirm', [AccountController::class, 'confirmDeletion'])
    ->middleware('throttle:api_public');
Route::get('account/data-export/download', [AccountController::class, 'downloadDataExport'])
    ->middleware('throttle:api_public');

// Newsletter subscribe (public, rate-limited to deter abuse)
Route::post('newsletter/subscribe', [NewsletterController::class, 'subscribe'])
    ->middleware('throttle:newsletter');

// Hero search-tab catalog (public read; admin write)
Route::get('hero-tabs', [HeroTabsController::class, 'show'])->middleware('throttle:api_public');

// Brand settings (logo / contact info / social links / admin-defined custom fields).
// Public read so Header/Footer/Contact-page can fetch with no auth.
Route::get('brand-settings', [BrandSettingsController::class, 'show'])->middleware('throttle:api_public');

// Header menu + Footer columns — public reads (Sprint 2)
Route::get('header-menu', [HeaderMenuController::class, 'show'])->middleware('throttle:api_public');
Route::get('footer', [FooterController::class, 'show'])->middleware('throttle:api_public');

// Backward-compatible alias: same handlers as v1/discovery/*; DeprecateLegacyDiscoveryApi adds Link / optional Sunset.
Route::prefix('discovery')->middleware(['throttle:api_public', DeprecateLegacyDiscoveryApi::class])->name('discovery.legacy.')->group($registerPublicDiscoveryRoutes);

Route::post('payments/webhook', [PaymentWebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class]);

// Driver-aware webhook entrypoint: same handler, dispatches by URL segment.
// Stripe Dashboard can keep posting to /payments/webhook; ArCa/Idram point
// their callbacks at /payments/webhook/arca and /payments/webhook/idram.
Route::post('payments/webhook/{driver}', [PaymentWebhookController::class, 'handleForDriver'])
    ->where('driver', 'stripe|arca|idram')
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->withoutMiddleware(['auth:sanctum', 'throttle:api'])
        ->name('verification.verify');

    Route::post('email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Admin translations UI (ZuluApi): canonical mutation surface stays under /api/localization/*.
    Route::post('localization/translations', [LocalizationController::class, 'setTranslations']);
    Route::delete('localization/translations', [LocalizationController::class, 'deleteTranslations']);
    // Phase B — equal-language content translations: per-entity multi-lang
    // read + admin "AI re-translate" trigger.
    Route::get('localization/content-translations/{entityType}/{entityId}', [LocalizationController::class, 'allLanguagesForEntity'])
        ->where('entityType', '[a-z_]+')
        ->where('entityId', '[0-9]+');
    Route::post('localization/content-translations/{entityType}/{entityId}/retranslate', [LocalizationController::class, 'retranslateEntity'])
        ->where('entityType', '[a-z_]+')
        ->where('entityId', '[0-9]+');
    Route::patch('localization/languages/{language}/toggle', [LocalizationController::class, 'toggleLanguage']);
    Route::get('localization/languages/all', [LocalizationController::class, 'adminLanguages']);
    Route::post('localization/languages', [LocalizationController::class, 'createLanguage']);
    Route::patch('localization/languages/{language}/set-default', [LocalizationController::class, 'setDefaultLanguage']);
    Route::patch('localization/languages/{language}/edit', [LocalizationController::class, 'editLanguage']);
    Route::delete('localization/languages/{language}', [LocalizationController::class, 'deleteLanguage']);
    Route::patch('localization/templates/{event}', [LocalizationController::class, 'updateTemplate'])
        ->where('event', '[A-Za-z0-9._-]+');
    Route::get('localization/ui-translations/admin', [LocalizationController::class, 'uiTranslationsPaginated']);
    Route::post('localization/ui-translations', [LocalizationController::class, 'setUiTranslations']);

    // Phase 13.6 — AI translator wake-up endpoints. POST kicks off
    // the scan (queues jobs for every gap); GET reports queue depth so
    // the admin UI can show progress.
    Route::post('localization/scan', [LocalizationController::class, 'scanTranslationGaps']);
    Route::get('localization/scan/status', [LocalizationController::class, 'scanStatus']);

    Route::get('account/me', [AccountController::class, 'me']);

    // Favorites (Phase 5 — heart icon on listing cards). Endpoints pre-built
    // in Phase 2 so the frontend can wire to a stable URL before the UI lands.
    Route::get('user/favorites', [UserFavoritesController::class, 'index']);
    Route::post('user/favorites', [UserFavoritesController::class, 'store']);
    Route::delete('user/favorites/{itemType}/{itemId}', [UserFavoritesController::class, 'destroy'])
        ->where('itemType', '[a-z_]+')->whereNumber('itemId');

    // Two-factor authentication (PART 29)
    Route::get('account/2fa/status', [TwoFactorController::class, 'status']);
    Route::post('account/2fa/setup', [TwoFactorController::class, 'setup']);
    Route::post('account/2fa/confirm', [TwoFactorController::class, 'confirm']);
    Route::post('account/2fa/verify', [TwoFactorController::class, 'verify'])->middleware('throttle:5,1');
    Route::post('account/2fa/resend', [TwoFactorController::class, 'resend'])->middleware('throttle:3,1');
    Route::post('account/2fa/disable', [TwoFactorController::class, 'disable']);
    Route::post('account/2fa/recovery-codes/regenerate', [TwoFactorController::class, 'regenerateRecoveryCodes']);
    Route::get('account/2fa/recovery-codes', [TwoFactorController::class, 'recoveryCodesStatus']);

    // Per-user PIN for sensitive admin actions (Phase 7.14)
    Route::get('account/pin', [AccountController::class, 'pinStatus']);
    Route::post('account/pin', [AccountController::class, 'setPin'])->middleware('throttle:10,1');
    Route::post('account/pin/verify', [AccountController::class, 'verifyPin'])->middleware('throttle:10,1');
    Route::delete('account/pin', [AccountController::class, 'clearPin']);

    // Blocked dates (Phase 7.2 — overbooking guard)
    Route::get('blocked-dates', [BlockedDatesController::class, 'index']);
    Route::post('blocked-dates', [BlockedDatesController::class, 'store']);
    Route::delete('blocked-dates/{id}', [BlockedDatesController::class, 'destroy'])->whereNumber('id');

    // Payroll (Phase 7.15)
    Route::get('payroll', [PayrollController::class, 'index']);
    Route::post('payroll', [PayrollController::class, 'store']);
    Route::patch('payroll/{id}/status', [PayrollController::class, 'changeStatus'])->whereNumber('id');
    Route::get('payroll/bank-batch', [PayrollController::class, 'bankBatch']);
    Route::get('payroll/{id}/payslip', [PayrollController::class, 'payslip'])->whereNumber('id');

    // Subscriptions (Phase 7.11)
    Route::get('subscription-plans', [SubscriptionsController::class, 'listPlans']);
    Route::get('subscription-plans/feature-catalog', [SubscriptionsController::class, 'listFeatureCatalog']);
    Route::get('subscription-plans/my-features', [SubscriptionsController::class, 'myFeatures']);
    Route::post('subscription-plans', [SubscriptionsController::class, 'storePlan']);
    Route::patch('subscription-plans/{id}', [SubscriptionsController::class, 'updatePlan'])->whereNumber('id');
    Route::get('company-subscriptions', [SubscriptionsController::class, 'listCompanySubscriptions']);
    Route::patch('company-subscriptions/{companyId}', [SubscriptionsController::class, 'assignCompanySubscription'])->whereNumber('companyId');

    // Time-off / non-service hours (Phase 7.13)
    Route::get('time-off', [TimeOffController::class, 'index']);
    Route::post('time-off', [TimeOffController::class, 'store']);
    Route::patch('time-off/{id}/decide', [TimeOffController::class, 'decide'])->whereNumber('id');

    // Time punches — clock in / out (shift attendance counterpart to time-off)
    Route::get('time-punches', [TimePunchController::class, 'index']);
    Route::post('time-punches', [TimePunchController::class, 'store']);
    Route::post('time-punches/clock-in', [TimePunchController::class, 'clockIn']);
    Route::post('time-punches/{id}/clock-out', [TimePunchController::class, 'clockOut'])->whereNumber('id');

    // Custom field definitions (Phase 7.4)
    Route::get('custom-fields', [CustomFieldsController::class, 'index']);
    Route::post('custom-fields', [CustomFieldsController::class, 'store']);
    Route::patch('custom-fields/{id}', [CustomFieldsController::class, 'update'])->whereNumber('id');
    Route::delete('custom-fields/{id}', [CustomFieldsController::class, 'destroy'])->whereNumber('id');

    // Cases & assignments (Phase 7.10)
    Route::get('cases', [CasesController::class, 'index']);
    Route::post('cases', [CasesController::class, 'store']);
    Route::get('cases/{id}', [CasesController::class, 'show'])->whereNumber('id');
    Route::patch('cases/{id}', [CasesController::class, 'update'])->whereNumber('id');
    Route::get('cases/{id}/replies', [CasesController::class, 'listReplies'])->whereNumber('id');
    Route::post('cases/{id}/replies', [CasesController::class, 'storeReply'])->whereNumber('id');

    // Service catalog (Phase 7.12 — generic bookable services)
    Route::get('service-catalog', [ServiceCatalogController::class, 'index']);
    Route::post('service-catalog', [ServiceCatalogController::class, 'store']);
    Route::patch('service-catalog/{id}', [ServiceCatalogController::class, 'update'])->whereNumber('id');
    Route::delete('service-catalog/{id}', [ServiceCatalogController::class, 'destroy'])->whereNumber('id');

    // Agent ↔ Operator requests inbox (Phase 7.7)
    Route::get('agent-operator-requests', [AgentOperatorRequestController::class, 'index']);
    Route::post('agent-operator-requests', [AgentOperatorRequestController::class, 'store']);
    Route::get('agent-operator-requests/{id}', [AgentOperatorRequestController::class, 'show'])->whereNumber('id');
    Route::patch('agent-operator-requests/{id}/status', [AgentOperatorRequestController::class, 'updateStatus'])->whereNumber('id');

    // Operator commission settings (Phase 6B — operator → agent split)
    Route::get('operator/commission-settings', [OperatorCommissionController::class, 'index']);
    Route::patch('operator/commission-settings', [OperatorCommissionController::class, 'upsertDefault']);
    Route::patch('operator/commission-settings/agents/{agent}', [OperatorCommissionController::class, 'upsertOverride'])
        ->whereNumber('agent');
    Route::delete('operator/commission-settings/agents/{agent}', [OperatorCommissionController::class, 'deleteOverride'])
        ->whereNumber('agent');

    // Seller contracts (PART 05)
    Route::get('seller/contracts', [SellerContractController::class, 'index']);
    Route::get('seller/contracts/{contract}', [SellerContractController::class, 'show'])
        ->where('contract', '[0-9a-f-]{36}');
    Route::post('seller/contracts/{contract}/sign', [SellerContractController::class, 'sign'])
        ->where('contract', '[0-9a-f-]{36}');

    // Seller webhooks (PART 30)
    Route::get('seller/webhooks', [SellerWebhookController::class, 'index']);
    Route::post('seller/webhooks', [SellerWebhookController::class, 'store']);
    Route::delete('seller/webhooks/{id}', [SellerWebhookController::class, 'destroy'])->whereNumber('id');
    Route::get('seller/webhooks/{id}/deliveries', [SellerWebhookController::class, 'deliveries'])->whereNumber('id');
    Route::post('seller/webhooks/{id}/deliveries/{deliveryId}/retry', [SellerWebhookController::class, 'retryDelivery'])
        ->whereNumber('id')->whereNumber('deliveryId');

    // Seller B2B partner connections (PART 18)
    Route::get('seller/connections', [SellerConnectionController::class, 'index']);
    Route::post('seller/connections', [SellerConnectionController::class, 'store']);
    Route::get('seller/connections/{id}', [SellerConnectionController::class, 'show'])
        ->where('id', '[0-9a-f-]{36}');
    Route::post('seller/connections/{id}/accept', [SellerConnectionController::class, 'accept'])
        ->where('id', '[0-9a-f-]{36}');
    Route::post('seller/connections/{id}/reject', [SellerConnectionController::class, 'reject'])
        ->where('id', '[0-9a-f-]{36}');
    Route::post('seller/connections/{id}/counter', [SellerConnectionController::class, 'counter'])
        ->where('id', '[0-9a-f-]{36}');
    Route::post('seller/connections/{id}/pause', [SellerConnectionController::class, 'pause'])
        ->where('id', '[0-9a-f-]{36}');
    Route::post('seller/connections/{id}/resume', [SellerConnectionController::class, 'resume'])
        ->where('id', '[0-9a-f-]{36}');
    Route::post('seller/connections/{id}/terminate', [SellerConnectionController::class, 'terminate'])
        ->where('id', '[0-9a-f-]{36}');
    Route::get('seller/visible-suppliers', [SellerConnectionController::class, 'visibleSuppliers']);

    // Customer loyalty (PART 27)
    Route::get('customer/loyalty', [CustomerLoyaltyController::class, 'show']);
    Route::post('customer/loyalty/redeem', [CustomerLoyaltyController::class, 'redeem']);

    // Customer personal statistics (PART 25, Sprint 71)
    Route::get('customer/stats', [CustomerStatsController::class, 'show']);

    // Customer saved searches (PART 20)
    Route::get('customer/saved-searches', [SavedSearchController::class, 'index']);
    Route::post('customer/saved-searches', [SavedSearchController::class, 'store']);
    Route::patch('customer/saved-searches/{id}/alerts', [SavedSearchController::class, 'toggleAlerts'])
        ->whereNumber('id');
    Route::delete('customer/saved-searches/{id}', [SavedSearchController::class, 'destroy'])
        ->whereNumber('id');

    // Customer insurance (PART 17)
    Route::get('customer/insurance/products', [CustomerInsuranceController::class, 'listProducts']);
    Route::post('customer/insurance/quote', [CustomerInsuranceController::class, 'quote']);
    Route::post('customer/insurance/purchase', [CustomerInsuranceController::class, 'purchase']);
    Route::get('customer/insurance/policies', [CustomerInsuranceController::class, 'myPolicies']);
    Route::get('customer/insurance/policies/{id}', [CustomerInsuranceController::class, 'showPolicy'])
        ->where('id', '[0-9a-f-]{36}');

    // Customer cart + checkout (PART 22)
    Route::get('customer/cart', [CustomerCartController::class, 'show']);
    Route::post('customer/cart/items', [CustomerCartController::class, 'addItem']);
    Route::patch('customer/cart/items/{itemId}', [CustomerCartController::class, 'updateItem'])
        ->where('itemId', '[0-9a-f-]{36}');
    Route::delete('customer/cart/items/{itemId}', [CustomerCartController::class, 'removeItem'])
        ->where('itemId', '[0-9a-f-]{36}');
    Route::delete('customer/cart', [CustomerCartController::class, 'clear']);
    Route::post('customer/cart/checkout', [CustomerCartController::class, 'checkout']);

    // Customer notification preferences (PART 23)
    Route::get('customer/notification-preferences', [NotificationPreferenceController::class, 'index']);
    Route::patch('customer/notification-preferences', [NotificationPreferenceController::class, 'update']);

    // Customer vouchers (PART 09)
    Route::get('customer/vouchers', [CustomerVoucherController::class, 'index']);
    Route::get('customer/vouchers/{voucher}/download', [CustomerVoucherController::class, 'download'])
        ->where('voucher', '[0-9a-f-]{36}');
    Route::post('customer/vouchers/{voucher}/resend', [CustomerVoucherController::class, 'resend'])
        ->where('voucher', '[0-9a-f-]{36}')
        ->middleware('throttle:6,1');
    Route::post('rollout/admin-next/screen-view', [AdminRolloutTelemetryController::class, 'screenView']);
    Route::patch('account/profile', [AccountController::class, 'updateProfile']);
    Route::get('account/trips', [AccountController::class, 'tripHistory']);
    Route::get('account/saved-items', [AccountController::class, 'savedItems']);
    Route::post('account/saved-items', [AccountController::class, 'saveItem']);
    Route::delete('account/saved-items/{item}', [AccountController::class, 'removeSavedItem'])->whereNumber('item');

    // GDPR — account deletion + data export
    Route::post('account/delete-request', [AccountController::class, 'requestDeletion']);
    Route::post('account/delete-cancel', [AccountController::class, 'cancelDeletion']);
    Route::post('account/data-export', [AccountController::class, 'requestDataExport']);

    Route::get('account/reviews', [ReviewController::class, 'myReviews']);

    Route::post('reviews', [ReviewController::class, 'createReview']);

    Route::prefix('marketplace')->group(function () {
        Route::post('bookings', [MarketplaceController::class, 'store']);
        Route::get('bookings/{orderId}', [MarketplaceController::class, 'show'])->whereUuid('orderId');
        Route::post('bookings/{orderId}/checkout', [MarketplaceController::class, 'checkout'])->whereUuid('orderId');
    });

    Route::post('import/upload', [ImportUploadController::class, 'store'])->middleware('throttle:file-upload');
    Route::post('import/{import_session}/stage', [ImportSessionController::class, 'stage'])
        ->whereNumber('import_session')
        ->middleware('throttle:file-upload');
    Route::get('import/{import_session}', [ImportSessionController::class, 'show'])->whereNumber('import_session');

    Route::get('companies', [CompanyController::class, 'index']);
    Route::get('companies/{company}/users', [CompanyController::class, 'users'])->whereNumber('company');
    Route::post('companies/{company}/users', [CompanyController::class, 'addUser'])->whereNumber('company');
    Route::patch('companies/{company}/users/{user}/role', [CompanyController::class, 'updateUserRole'])->whereNumber('company')->whereNumber('user');
    Route::patch('companies/{company}/users/{user}/deactivate', [CompanyController::class, 'deactivateUser'])->whereNumber('company')->whereNumber('user');
    Route::patch('companies/{company}/profile', [CompanyController::class, 'updateProfile'])->whereNumber('company');
    Route::get('companies/{company}/dashboard', [CompanyController::class, 'dashboard'])->whereNumber('company');
    Route::get('companies/{company}/seller-permissions', [CompanyController::class, 'sellerPermissions'])->whereNumber('company');
    Route::post('companies/{company}/seller-permissions', [CompanyController::class, 'grantSellerPermission'])->whereNumber('company');
    Route::delete('companies/{company}/seller-permissions/{serviceType}', [CompanyController::class, 'revokeSellerPermission'])->whereNumber('company');
    // Multi-country seller permissions (super admin grants per-country licenses)
    Route::get('companies/{company}/country-permissions', [CompanyController::class, 'countryPermissions'])->whereNumber('company');
    Route::patch('companies/{company}/country-permissions', [CompanyController::class, 'syncCountryPermissions'])->whereNumber('company');
    // Per-company admin module visibility (Phase 6A)
    Route::get('companies/{company}/module-permissions', [CompanyController::class, 'modulePermissions'])->whereNumber('company');
    Route::patch('companies/{company}/module-permissions', [CompanyController::class, 'patchModulePermissions'])->whereNumber('company');
    Route::get('companies/{company}/contract', [CompanyController::class, 'downloadContract'])->whereNumber('company');
    Route::post('companies/{company}/seller-applications', [CompanyController::class, 'submitSellerApplication'])->whereNumber('company');
    Route::get('companies/{company}/seller-applications', [CompanyController::class, 'listSellerApplications'])->whereNumber('company');
    Route::patch('companies/{company}/airline-flag', [CompanyController::class, 'setAirlineFlag'])->whereNumber('company');
    Route::get('companies/{company}', [CompanyController::class, 'show'])->whereNumber('company');

    Route::get('offers', [OfferController::class, 'index']);
    Route::get('offers/{offer}', [OfferController::class, 'show']);
    Route::post('offers', [OfferController::class, 'store'])->middleware('throttle:inventory-write');
    Route::post('offers/{offer}/publish', [OfferController::class, 'publish'])->middleware('throttle:inventory-write');
    Route::post('offers/{offer}/archive', [OfferController::class, 'archive'])->middleware('throttle:inventory-write');
    // Review-gate workflow (Option 2 — quality control before customer-side visibility)
    Route::post('offers/{offer}/submit-for-review', [OfferController::class, 'submitForReview'])->middleware('throttle:inventory-write');
    Route::get('admin/offers/pending-review', [OfferController::class, 'pendingReviewQueue']);
    Route::post('admin/offers/{offer}/approve', [OfferController::class, 'approve'])->middleware('throttle:inventory-write');
    Route::post('admin/offers/{offer}/reject', [OfferController::class, 'reject'])->middleware('throttle:inventory-write');
    // Phase 7.5 — bulk approve from the pending-review queue
    Route::post('admin/offers/bulk-approve', [OfferController::class, 'bulkApprove'])->middleware('throttle:inventory-write');

    Route::get('invoices', [InvoiceController::class, 'index']);
    Route::get('invoices/aggregate', [InvoiceController::class, 'aggregate']);
    // Phase 7.6 — CSV export with same filters as index (from/to/status)
    Route::get('invoices/export', [InvoiceController::class, 'exportCsv']);
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->whereNumber('invoice');
    Route::get('invoices/{invoice}/download', [InvoiceController::class, 'downloadPdf']);
    Route::post('invoices', [InvoiceController::class, 'store']);
    Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue']);
    Route::post('invoices/{invoice}/pay', [InvoiceController::class, 'pay']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel']);

    Route::get('payments', [PaymentController::class, 'index']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::post('payments', [PaymentController::class, 'store']);
    Route::post('payments/{payment}/pay', [PaymentController::class, 'pay']);
    Route::post('payments/{payment}/capture', [PaymentController::class, 'capture']);
    Route::post('payments/{payment}/fail', [PaymentController::class, 'fail']);
    Route::post('payments/{payment}/refund', [PaymentController::class, 'refund']);

    Route::get('commissions', [CommissionController::class, 'index']);
    Route::post('commissions', [CommissionController::class, 'createPolicy']);
    Route::get('commissions/{ruleId}', [CommissionController::class, 'show'])->whereUuid('ruleId');
    Route::patch('commissions/{ruleId}', [CommissionController::class, 'updatePolicy'])->whereUuid('ruleId');
    Route::post('commissions/{ruleId}/deactivate', [CommissionController::class, 'deactivatePolicy'])->whereUuid('ruleId');
    Route::get('commission-records', [CommissionController::class, 'indexRecords']);

    Route::prefix('finance')->group(function () {
        Route::get('summary', [FinanceController::class, 'companySummary']);
        Route::get('entitlements', [FinanceController::class, 'entitlements']);
        Route::post('entitlements/mark-payable', [FinanceController::class, 'markPayable']);
        Route::get('settlements', [FinanceController::class, 'settlements']);
        Route::post('settlements', [FinanceController::class, 'createSettlement']);
        Route::patch('settlements/{settlement}/status', [FinanceController::class, 'updateSettlementStatus'])->whereNumber('settlement');
    });

    Route::get('visas', [VisaController::class, 'index']);
    Route::get('visas/{visa}', [VisaController::class, 'show']);
    Route::post('visas', [VisaController::class, 'store'])->middleware('throttle:inventory-write');

    // Customer visa applications (PART 16, Sprint 63)
    Route::get('visa-applications', [UserVisaApiController::class, 'index']);
    Route::post('visa-applications', [UserVisaApiController::class, 'apply'])->middleware('throttle:file-upload');
    Route::get('visa-applications/{id}', [UserVisaApiController::class, 'show'])->whereNumber('id');
    Route::patch('visas/{visa}', [VisaController::class, 'update'])->whereNumber('visa')->middleware('throttle:inventory-write');
    Route::delete('visas/{visa}', [VisaController::class, 'destroy'])->whereNumber('visa')->middleware('throttle:inventory-write');

    Route::get('cars', [CarController::class, 'index']);
    Route::get('cars/{car}', [CarController::class, 'show'])->whereNumber('car');
    Route::post('cars', [CarController::class, 'store'])->middleware('throttle:inventory-write');
    Route::patch('cars/{car}', [CarController::class, 'update'])->whereNumber('car')->middleware('throttle:inventory-write');
    Route::delete('cars/{car}', [CarController::class, 'destroy'])->whereNumber('car')->middleware('throttle:inventory-write');

    Route::get('excursions', [ExcursionController::class, 'index']);
    Route::get('excursions/{excursion}', [ExcursionController::class, 'show'])->whereNumber('excursion');
    Route::post('excursions', [ExcursionController::class, 'store'])->middleware('throttle:inventory-write');
    Route::patch('excursions/{excursion}', [ExcursionController::class, 'update'])->whereNumber('excursion')->middleware('throttle:inventory-write');
    Route::delete('excursions/{excursion}', [ExcursionController::class, 'destroy'])->whereNumber('excursion')->middleware('throttle:inventory-write');

    Route::get('hotels', [HotelController::class, 'index']);
    Route::post('hotels', [HotelController::class, 'store'])->middleware('throttle:inventory-write');
    Route::get('hotels/{hotel}', [HotelController::class, 'show'])->whereNumber('hotel');
    Route::patch('hotels/{hotel}', [HotelController::class, 'update'])->whereNumber('hotel')->middleware('throttle:inventory-write');
    Route::delete('hotels/{hotel}', [HotelController::class, 'destroy'])->whereNumber('hotel')->middleware('throttle:inventory-write');

    Route::get('transfers', [TransferController::class, 'index']);
    Route::post('transfers', [TransferController::class, 'store'])->middleware('throttle:inventory-write');
    Route::get('transfers/{transfer}', [TransferController::class, 'show'])->whereNumber('transfer');
    Route::patch('transfers/{transfer}', [TransferController::class, 'update'])->whereNumber('transfer')->middleware('throttle:inventory-write');
    Route::delete('transfers/{transfer}', [TransferController::class, 'destroy'])->whereNumber('transfer')->middleware('throttle:inventory-write');

    Route::get('flights', [FlightController::class, 'index']);
    Route::get('flights/{flight}', [FlightController::class, 'show'])->whereNumber('flight');
    Route::post('flights', [FlightController::class, 'store'])->middleware('throttle:inventory-write');
    Route::patch('flights/{flight}', [FlightController::class, 'update'])->whereNumber('flight')->middleware('throttle:inventory-write');
    Route::delete('flights/{flight}', [FlightController::class, 'destroy'])->whereNumber('flight')->middleware('throttle:inventory-write');

    Route::get('flights/{flight}/cabins', [FlightController::class, 'listCabins'])->whereNumber('flight');
    Route::post('flights/{flight}/cabins', [FlightController::class, 'addCabin'])->whereNumber('flight')->middleware('throttle:inventory-write');
    Route::patch('flights/{flight}/cabins/{cabin}', [FlightController::class, 'updateCabin'])->whereNumber('flight')->whereNumber('cabin')->middleware('throttle:inventory-write');
    Route::delete('flights/{flight}/cabins/{cabin}', [FlightController::class, 'deleteCabin'])->whereNumber('flight')->whereNumber('cabin')->middleware('throttle:inventory-write');

    Route::get('packages', [PackageController::class, 'index']);
    Route::post('packages', [PackageController::class, 'store'])->middleware('throttle:inventory-write');
    Route::patch('packages/{package}', [PackageController::class, 'update'])->whereNumber('package')->middleware('throttle:inventory-write');
    Route::delete('packages/{package}', [PackageController::class, 'destroy'])->whereNumber('package')->middleware('throttle:inventory-write');
    Route::post('packages/{package}/components', [PackageController::class, 'addComponent'])->whereNumber('package')->middleware('throttle:inventory-write');
    Route::post('packages/{package}/components/reorder', [PackageController::class, 'reorderComponents'])->whereNumber('package')->middleware('throttle:inventory-write');
    Route::delete('packages/{package}/components/{component}', [PackageController::class, 'removeComponent'])->whereNumber('package')->whereNumber('component')->middleware('throttle:inventory-write');
    Route::post('packages/{package}/activate', [PackageController::class, 'activate'])->whereNumber('package')->middleware('throttle:inventory-write');
    Route::post('packages/{package}/deactivate', [PackageController::class, 'deactivate'])->whereNumber('package')->middleware('throttle:inventory-write');

    Route::post('package-orders', [PackageOrderController::class, 'store']);
    Route::get('package-orders', [PackageOrderController::class, 'index']);
    Route::get('package-orders/{order}', [PackageOrderController::class, 'show'])->whereNumber('order');
    Route::post('package-orders/{order}/pay', [PackageOrderController::class, 'markPaid'])->whereNumber('order');
    Route::get('company/package-orders', [PackageOrderController::class, 'companyIndex']);
    Route::get('company/package-orders/{order}', [PackageOrderController::class, 'companyShow'])->whereNumber('order');
    Route::post('company/package-orders/{order}/items/{item}/confirm', [PackageOrderController::class, 'confirmItem'])
        ->whereNumber('order')
        ->whereNumber('item');
    Route::post('company/package-orders/{order}/items/{item}/fail', [PackageOrderController::class, 'failItem'])
        ->whereNumber('order')
        ->whereNumber('item');
    Route::post('company/package-orders/{order}/cancel', [PackageOrderController::class, 'cancelOrder'])->whereNumber('order');

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('notifications/paginated', [NotificationController::class, 'paginatedIndex']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereNumber('notification');

    // I1 audit F-4: group-level platform-admin guard. The in-controller
    // denyUnlessPlatformAdmin() helpers remain in place as defence-in-depth.
    Route::prefix('platform-admin')->middleware('platform-admin')->group(function () {
        Route::get('applications', [CompanyApplicationController::class, 'index']);
        Route::get('applications/{id}', [CompanyApplicationController::class, 'show'])->whereNumber('id');
        Route::post('applications/{id}/approve', [PlatformAdminController::class, 'approveApplication'])->whereNumber('id');
        Route::post('applications/{id}/reject', [PlatformAdminController::class, 'rejectApplication'])->whereNumber('id');
        Route::get('stats', [PlatformAdminController::class, 'stats']);
        Route::get('companies', [PlatformAdminController::class, 'companies']);
        Route::get('companies/{company}', [PlatformAdminController::class, 'showCompany'])->whereNumber('company');
        Route::patch('companies/{company}/governance', [PlatformAdminController::class, 'changeGovernance'])->whereNumber('company');
        Route::patch('companies/{company}/permissions', [PlatformAdminController::class, 'updateCompanyPermissions'])->whereNumber('company');
        Route::patch('companies/{company}/toggle-seller', [PlatformAdminController::class, 'toggleCompanySellerStatus'])->whereNumber('company');
        Route::patch('companies/{company}/partner-settings', [PlatformAdminController::class, 'updateCompanyPartnerSettings'])->whereNumber('company');
        // Phase 7.2 — admin archive/restore (super-admin only enforced in controller)
        Route::post('companies/{company}/archive', [PlatformAdminController::class, 'archiveCompany'])->whereNumber('company');
        Route::post('companies/{company}/restore', [PlatformAdminController::class, 'restoreCompany'])->whereNumber('company');
        Route::get('seller-applications', [PlatformAdminController::class, 'listSellerApplications']);
        Route::post('seller-applications/{id}/approve', [PlatformAdminController::class, 'approveSellerApplication'])->whereNumber('id');
        Route::post('seller-applications/{id}/reject', [PlatformAdminController::class, 'rejectSellerApplication'])->whereNumber('id');
        Route::get('approvals', [PlatformAdminController::class, 'approvals']);
        Route::post('approvals/bulk-approve', [PlatformAdminController::class, 'bulkApproveApprovals']);
        Route::post('approvals/{approval}/approve', [PlatformAdminController::class, 'approveApproval'])->whereNumber('approval');
        Route::post('approvals/{approval}/reject', [PlatformAdminController::class, 'rejectApproval'])->whereNumber('approval');
        Route::get('package-orders', [PlatformAdminController::class, 'packageOrders']);
        Route::get('payments', [PlatformAdminController::class, 'payments']);
        // Phase 7.7 — CSV export for /platform/payments
        Route::get('payments/export', [PlatformAdminController::class, 'exportPaymentsCsv']);
        Route::get('finance-summary', [PlatformAdminController::class, 'financeSummary']);
        Route::get('packages', [PlatformAdminController::class, 'packages']);
        Route::post('packages/{package}/deactivate', [PlatformAdminController::class, 'deactivatePackage'])->whereNumber('package');
        Route::get('packages/{package}/homepage-features', [PlatformAdminController::class, 'listPackageHomepageFeatures'])->whereNumber('package');
        Route::put('packages/{package}/homepage-features', [PlatformAdminController::class, 'syncPackageHomepageFeatures'])->whereNumber('package');
        Route::get('reviews', [PlatformAdminController::class, 'listAllReviews']);
        Route::get('settings', [PlatformAdminController::class, 'getSettings']);
        Route::patch('settings/{key}', [PlatformAdminController::class, 'updateSetting']);
        Route::post('reviews/{review}/moderate', [ReviewController::class, 'moderateReview'])->whereNumber('review');
        Route::get('users', [PlatformAdminController::class, 'listUsers']);
        Route::get('customers', [PlatformAdminController::class, 'listCustomers']);
        Route::get('unverified-accounts', [PlatformAdminController::class, 'listUnverifiedAccounts']);
        Route::get('users/{id}', [PlatformAdminController::class, 'showUser'])->whereNumber('id');
        Route::patch('users/{id}', [PlatformAdminController::class, 'updateUser'])->whereNumber('id');
        Route::patch('users/{id}/deactivate', [PlatformAdminController::class, 'deactivateUser'])->whereNumber('id');
        // Phase 7.1 — admin-initiated deletion (2-button approach)
        Route::post('users/{id}/anonymize', [PlatformAdminController::class, 'anonymizeUser'])->whereNumber('id');
        Route::delete('users/{id}/hard', [PlatformAdminController::class, 'hardDeleteUser'])->whereNumber('id');

        Route::get('banners', [PlatformAdminBannerController::class, 'index']);
        Route::post('banners', [PlatformAdminBannerController::class, 'store']);
        Route::patch('banners/{banner}', [PlatformAdminBannerController::class, 'update'])->whereNumber('banner');
        Route::delete('banners/{banner}', [PlatformAdminBannerController::class, 'destroy'])->whereNumber('banner');
        // Phase 7.8 — banner bulk ops
        Route::post('banners/bulk-delete', [PlatformAdminBannerController::class, 'bulkDestroy']);
        Route::post('banners/reorder', [PlatformAdminBannerController::class, 'reorder']);

        // Audit logs (PART 26)
        Route::get('audit-logs', [AdminAuditLogController::class, 'index']);
        Route::get('audit-logs/{log}', [AdminAuditLogController::class, 'show'])
            ->where('log', '[0-9a-f-]{36}');
        Route::post('audit-logs/verify-integrity', [AdminAuditLogController::class, 'verifyIntegrity']);

        // OpenAPI spec served to platform-admin (Sprint 74)
        Route::get('openapi.json', function () {
            $path = base_path('storage/app/openapi.json');
            if (! file_exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Spec not generated yet. Run: php artisan api:generate-openapi',
                ], 404);
            }

            return response()->file($path, ['Content-Type' => 'application/json']);
        });

        // Webhook oversight (PART 30, Sprint 52 + 75)
        Route::get('webhooks/subscriptions', [AdminWebhookController::class, 'subscriptions']);
        Route::get('webhooks/deliveries', [AdminWebhookController::class, 'deliveries']);
        Route::get('webhooks/dead-letter', [AdminWebhookController::class, 'deadLetter']);
        Route::post('webhooks/deliveries/{id}/replay', [AdminWebhookController::class, 'replayDelivery'])
            ->whereNumber('id');
        Route::get('webhooks/stats', [AdminWebhookController::class, 'stats']);

        // Notification oversight (PART 23, Sprint 59)
        Route::get('notifications', [AdminNotificationController::class, 'index']);
        Route::get('notifications/stats', [AdminNotificationController::class, 'stats']);
        Route::post('notifications/bulk-send', [AdminNotificationController::class, 'bulkSend']);

        // Hero search-tab catalog write (home-page CMS Phase 2 Step 2.4)
        Route::patch('site-settings/hero-tabs', [HeroTabsController::class, 'update']);

        // Brand settings write (ZULU CMS Sprint 1)
        Route::patch('brand-settings', [BrandSettingsController::class, 'update']);

        // Header menu admin (ZULU CMS Sprint 2)
        Route::get('header-menu', [HeaderMenuController::class, 'adminIndex']);
        Route::put('header-menu', [HeaderMenuController::class, 'adminSync']);

        // Footer admin (ZULU CMS Sprint 2)
        Route::get('footer', [FooterController::class, 'adminIndex']);
        Route::put('footer', [FooterController::class, 'adminSync']);

        // Newsletter subscriber management (home-page CMS Phase 2 Step 2.2)
        Route::get('newsletter/subscriptions', [AdminNewsletterController::class, 'index']);
        Route::get('newsletter/subscriptions/stats', [AdminNewsletterController::class, 'stats']);
        Route::get('newsletter/subscriptions/export.csv', [AdminNewsletterController::class, 'exportCsv']);
        Route::delete('newsletter/subscriptions/{id}', [AdminNewsletterController::class, 'destroy'])->whereNumber('id');

        // Search trends (PART 20, Sprint 68)
        Route::get('search/trends', [SavedSearchController::class, 'trends']);

        // RBAC inventory (PART 28, Sprint 66)
        Route::get('rbac/roles', [AdminRbacController::class, 'roles']);
        Route::get('rbac/permissions', [AdminRbacController::class, 'permissions']);
        Route::get('rbac/matrix', [AdminRbacController::class, 'matrix']);
        Route::get('rbac/stats', [AdminRbacController::class, 'stats']);

        // Phase 1 / Step D.1 — pricing rules CRUD + test panel.
        // Super-admin only (Form Request authorize() + controller guard).
        Route::get('pricing-rules', [\App\Http\Controllers\Api\PricingRuleController::class, 'index']);
        Route::post('pricing-rules', [\App\Http\Controllers\Api\PricingRuleController::class, 'store']);
        Route::post('pricing-rules/test', [\App\Http\Controllers\Api\PricingRuleController::class, 'test']);
        Route::patch('pricing-rules/{pricingRule}', [\App\Http\Controllers\Api\PricingRuleController::class, 'update']);
        Route::delete('pricing-rules/{pricingRule}', [\App\Http\Controllers\Api\PricingRuleController::class, 'destroy']);

        // Phase 1 / Step D.2 — money flow terms CRUD (mirror pattern).
        Route::get('money-flow-terms', [\App\Http\Controllers\Api\MoneyFlowTermController::class, 'index']);
        Route::post('money-flow-terms', [\App\Http\Controllers\Api\MoneyFlowTermController::class, 'store']);
        Route::patch('money-flow-terms/{moneyFlowTerm}', [\App\Http\Controllers\Api\MoneyFlowTermController::class, 'update']);
        Route::delete('money-flow-terms/{moneyFlowTerm}', [\App\Http\Controllers\Api\MoneyFlowTermController::class, 'destroy']);

        // Visa application review queue (PART 16, Sprint 63)
        Route::get('visa-applications', [AdminVisaApplicationController::class, 'index']);
        Route::get('visa-applications/stats', [AdminVisaApplicationController::class, 'stats']);
        Route::get('visa-applications/{id}', [AdminVisaApplicationController::class, 'show'])->whereNumber('id');
        Route::post('visa-applications/{id}/decide', [AdminVisaApplicationController::class, 'decide'])->whereNumber('id');

        // Security oversight (PART 29, Sprint 62)
        Route::get('security/two-factor', [AdminSecurityController::class, 'twoFactorIndex']);
        Route::get('security/stats', [AdminSecurityController::class, 'stats']);
        Route::post('security/users/{userId}/force-disable-2fa', [AdminSecurityController::class, 'forceDisableTwoFactor'])
            ->whereNumber('userId');
        Route::post('security/users/{userId}/force-logout', [AdminSecurityController::class, 'forceLogout'])
            ->whereNumber('userId');

        // Contracts (PART 05)
        Route::get('contracts', [AdminContractController::class, 'index']);
        Route::get('contracts/{contract}', [AdminContractController::class, 'show'])
            ->where('contract', '[0-9a-f-]{36}');
        Route::post('contracts', [AdminContractController::class, 'store']);
        Route::post('contracts/{contract}/send', [AdminContractController::class, 'send'])
            ->where('contract', '[0-9a-f-]{36}');
        Route::post('contracts/{contract}/countersign', [AdminContractController::class, 'countersign'])
            ->where('contract', '[0-9a-f-]{36}');
        Route::post('contracts/{contract}/terminate', [AdminContractController::class, 'terminate'])
            ->where('contract', '[0-9a-f-]{36}');

        // Contract templates (PART 05)
        Route::get('contract-templates', [AdminContractTemplateController::class, 'index']);
        Route::get('contract-templates/{template}', [AdminContractTemplateController::class, 'show'])
            ->where('template', '[0-9a-f-]{36}');
        Route::post('contract-templates', [AdminContractTemplateController::class, 'store']);
        Route::patch('contract-templates/{template}', [AdminContractTemplateController::class, 'update'])
            ->where('template', '[0-9a-f-]{36}');

        // Platform statistics dashboard (PART 25)
        Route::get('statistics/dashboard', [AdminPlatformStatisticsController::class, 'dashboard']);
        Route::get('statistics/revenue-series', [AdminPlatformStatisticsController::class, 'revenueSeries']);
        Route::get('statistics/orders-series', [AdminPlatformStatisticsController::class, 'ordersSeries']);
        Route::get('statistics/sellers', [AdminPlatformStatisticsController::class, 'topSellers']);
        Route::get('statistics/sellers/{companyId}', [AdminPlatformStatisticsController::class, 'sellerDetail'])
            ->whereNumber('companyId');

        // Loyalty (PART 27)
        Route::get('loyalty/accounts', [AdminLoyaltyController::class, 'indexAccounts']);
        Route::get('loyalty/accounts/{userId}/transactions', [AdminLoyaltyController::class, 'accountTransactions'])
            ->whereNumber('userId');
        Route::post('loyalty/accounts/{userId}/adjust', [AdminLoyaltyController::class, 'adjust'])
            ->whereNumber('userId');
        Route::get('loyalty/stats', [AdminLoyaltyController::class, 'stats']);

        // Insurance (PART 17)
        Route::get('insurance/products', [AdminInsuranceController::class, 'indexProducts']);
        Route::post('insurance/products', [AdminInsuranceController::class, 'storeProduct']);
        Route::patch('insurance/products/{id}', [AdminInsuranceController::class, 'updateProduct'])
            ->whereNumber('id');
        Route::get('insurance/policies', [AdminInsuranceController::class, 'indexPolicies']);
        Route::post('insurance/policies/{id}/cancel', [AdminInsuranceController::class, 'cancelPolicy'])
            ->where('id', '[0-9a-f-]{36}');

        // Cart oversight (PART 22)
        Route::get('abandoned-carts', [AdminCartController::class, 'abandoned']);
        Route::post('cart/release-expired-holds', [AdminCartController::class, 'releaseExpiredHolds']);
        Route::get('cart/stats', [AdminCartController::class, 'stats']);

        // Package booking sagas (PART 19 — HEART)
        Route::get('package-sagas', [AdminPackageSagaController::class, 'index']);
        Route::get('package-sagas/stats', [AdminPackageSagaController::class, 'stats']);
        Route::get('package-sagas/{id}', [AdminPackageSagaController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('package-sagas/{id}/retry', [AdminPackageSagaController::class, 'retry'])
            ->where('id', '[0-9a-f-]{36}');

        // Connections (PART 18)
        Route::get('connections', [AdminConnectionController::class, 'index']);
        Route::get('connections/stats', [AdminConnectionController::class, 'stats']);
        Route::get('connections/{id}', [AdminConnectionController::class, 'show'])
            ->where('id', '[0-9a-f-]{36}');
        Route::post('connections/{id}/force-terminate', [AdminConnectionController::class, 'forceTerminate'])
            ->where('id', '[0-9a-f-]{36}');

        // Vouchers (PART 09)
        Route::get('vouchers', [AdminVoucherController::class, 'index']);
        Route::get('vouchers/{voucher}', [AdminVoucherController::class, 'show'])
            ->where('voucher', '[0-9a-f-]{36}');
        Route::post('vouchers/{voucher}/void', [AdminVoucherController::class, 'void'])
            ->where('voucher', '[0-9a-f-]{36}');
        Route::post('vouchers/{voucher}/reissue', [AdminVoucherController::class, 'reissue'])
            ->where('voucher', '[0-9a-f-]{36}');
    });

    Route::prefix('admin/pages')->group(function () {
        Route::get('/', [PageController::class, 'index']);
        Route::post('/', [PageController::class, 'store']);
        Route::get('{id}', [PageController::class, 'show'])->whereNumber('id');
        Route::patch('{id}', [PageController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [PageController::class, 'destroy'])->whereNumber('id');
        Route::patch('change-status', [PageController::class, 'changeStatus']);
        Route::post('widgets/add', [PageController::class, 'addWidget']);
        Route::post('widgets/update', [PageController::class, 'updateWidget']);
        Route::delete('widgets/{id}', [PageController::class, 'deleteWidget'])->whereNumber('id');
        Route::post('widgets/sort', [PageController::class, 'sortWidgets']);
        Route::post('widgets/upload-image', [PageController::class, 'uploadImage']);
    });

    // Operator inventory oversight lists (Sanctum; same RBAC as admin/inventory via AdminAccessService).
    Route::prefix('operator')->group(function () {
        Route::get('statistics', [OperatorStatisticsController::class, 'show']);
        Route::prefix('inventory')->name('api.operator.inventory.')->group(function () {
            Route::get('flights', [AdminInventoryController::class, 'flights'])->name('flights');
            Route::get('hotels', [AdminInventoryController::class, 'hotels'])->name('hotels');
            Route::get('transfers', [AdminInventoryController::class, 'transfers'])->name('transfers');
            Route::get('cars', [AdminInventoryController::class, 'cars'])->name('cars');
            Route::get('excursions', [AdminInventoryController::class, 'excursions'])->name('excursions');
        });
    });

    // Service Connections
    Route::get('connections', [ConnectionController::class, 'index']);
    Route::post('connections', [ConnectionController::class, 'store']);
    Route::get('connections/{connection}', [ConnectionController::class, 'show'])->whereNumber('connection');
    Route::patch('connections/{connection}/accept', [ConnectionController::class, 'accept'])->whereNumber('connection');
    Route::patch('connections/{connection}/reject', [ConnectionController::class, 'reject'])->whereNumber('connection');
    Route::patch('connections/{connection}/cancel', [ConnectionController::class, 'cancel'])->whereNumber('connection');

    Route::get('locations/countries', [AdminLocationController::class, 'countries']);
    Route::post('locations/countries', [AdminLocationController::class, 'countries']);
    Route::get('locations/countries/{id}/regions', [AdminLocationController::class, 'regions'])->whereNumber('id');
    Route::post('locations/regions', [AdminLocationController::class, 'regions']);
    Route::get('locations/regions/{id}/cities', [AdminLocationController::class, 'cities'])->whereNumber('id');
    Route::post('locations/cities', [AdminLocationController::class, 'cities']);
    Route::get('locations/tree/children', [AdminLocationController::class, 'treeChildren']);
    Route::get('locations/tree/node/{id}', [AdminLocationController::class, 'treeNode'])->whereNumber('id');

    // Admin support (Bearer): mirrors Blade admin/support* + SupportService (super admin or company admin with role membership).
    Route::get('support/tickets', [SupportAdminController::class, 'index']);
    Route::get('support/tickets/{id}', [SupportAdminController::class, 'show'])->whereNumber('id');
    Route::post('support/tickets/{id}/messages', [SupportAdminController::class, 'storeMessage'])->whereNumber('id');
    Route::patch('support/tickets/{id}/status', [SupportAdminController::class, 'updateStatus'])->whereNumber('id');

    // Customer support (Sprint 65, PART 24)
    Route::get('customer/support/tickets', [CustomerSupportController::class, 'index']);
    Route::post('customer/support/tickets', [CustomerSupportController::class, 'store']);
    Route::get('customer/support/tickets/{id}', [CustomerSupportController::class, 'show'])->whereNumber('id');
    Route::post('customer/support/tickets/{id}/messages', [CustomerSupportController::class, 'addMessage'])->whereNumber('id');
});
