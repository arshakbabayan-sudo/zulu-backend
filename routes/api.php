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
 * Unrouted legacy API code: `App\Http\Controllers\Api\Modules\UserVisaApiController` (isolated until explicitly registered).
 */

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminLocationController;
use App\Http\Controllers\Api\AdminRolloutTelemetryController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\CarController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\CommissionController;
use App\Http\Controllers\Api\CompanyApplicationController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ConnectionController;
use App\Http\Controllers\Api\DiscoveryController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\ExcursionController;
use App\Http\Controllers\Api\FinanceController;
use App\Http\Controllers\Api\FlightController;
use App\Http\Controllers\Api\HotelController;
use App\Http\Controllers\Api\ImportSessionController;
use App\Http\Controllers\Api\ImportUploadController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LocalizationController;
use App\Http\Controllers\Api\MarketplaceController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OfferController;
use App\Http\Controllers\Api\OperatorStatisticsController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\PackageOrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\PlatformAdminBannerController;
use App\Http\Controllers\Api\PlatformAdminController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\StorefrontPackageController;
use App\Http\Controllers\Api\SupportAdminController;
use App\Http\Controllers\Api\TransferController;
use App\Http\Controllers\Api\VisaController;
use App\Http\Middleware\DeprecateLegacyDiscoveryApi;
use App\Services\Infrastructure\PlatformReadinessService;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('register', [AuthController::class, 'register'])->middleware('throttle:register');
Route::post('companies/apply', [CompanyApplicationController::class, 'store'])->middleware('throttle:file-upload');
Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:password-reset');
Route::post('reset-password', [AuthController::class, 'resetPassword']);
Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');

$registerPublicDiscoveryRoutes = static function (): void {
    Route::get('search', [DiscoveryController::class, 'search'])->name('search');
    Route::get('offers/{id}', [DiscoveryController::class, 'show'])->whereNumber('id')->name('offer');
};

Route::prefix('v1')->name('v1.')->group(function () use ($registerPublicDiscoveryRoutes) {
    Route::get('/health', function (PlatformReadinessService $readinessService) {
        return response()->json($readinessService->getHealthPayload());
    });

    // Canonical public discovery contract (pairs with config/zulu_platform.php api.version).
    Route::prefix('discovery')->middleware('throttle:api_public')->name('discovery.')->group($registerPublicDiscoveryRoutes);
});

// Public read endpoints used by admin Blade (API-driven UI) and storefronts.
Route::prefix('localization')->group(function () {
    Route::get('languages', [LocalizationController::class, 'languages']);
    Route::get('translations', [LocalizationController::class, 'translations']);
    Route::get('templates/{event}', [LocalizationController::class, 'getTemplate']);
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

// Backward-compatible alias: same handlers as v1/discovery/*; DeprecateLegacyDiscoveryApi adds Link / optional Sunset.
Route::prefix('discovery')->middleware(['throttle:api_public', DeprecateLegacyDiscoveryApi::class])->name('discovery.legacy.')->group($registerPublicDiscoveryRoutes);

Route::post('payments/webhook', [PaymentWebhookController::class, 'handle'])
    ->withoutMiddleware([VerifyCsrfToken::class]);

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->withoutMiddleware(['auth:sanctum', 'throttle:api'])
        ->name('verification.verify');

    Route::post('email/resend', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    // Admin translations UI (ZuluApi): mutates translations; web POST admin/localization/translations/update is legacy/alternate.
    Route::post('localization/translations', [LocalizationController::class, 'setTranslations']);
    Route::delete('localization/translations', [LocalizationController::class, 'deleteTranslations']);
    Route::patch('localization/languages/{language}/toggle', [LocalizationController::class, 'toggleLanguage']);
    Route::patch('localization/templates/{event}', [LocalizationController::class, 'updateTemplate'])
        ->where('event', '[A-Za-z0-9._-]+');

    Route::get('account/me', [AccountController::class, 'me']);
    Route::post('rollout/admin-next/screen-view', [AdminRolloutTelemetryController::class, 'screenView']);
    Route::patch('account/profile', [AccountController::class, 'updateProfile']);
    Route::get('account/trips', [AccountController::class, 'tripHistory']);
    Route::get('account/saved-items', [AccountController::class, 'savedItems']);
    Route::post('account/saved-items', [AccountController::class, 'saveItem']);
    Route::delete('account/saved-items/{item}', [AccountController::class, 'removeSavedItem'])->whereNumber('item');
    Route::get('account/reviews', [ReviewController::class, 'myReviews']);

    Route::post('reviews', [ReviewController::class, 'createReview']);

    Route::prefix('marketplace')->group(function () {
        Route::post('bookings', [MarketplaceController::class, 'store']);
        Route::get('bookings/{booking}', [MarketplaceController::class, 'show'])->whereNumber('booking');
        Route::post('bookings/{booking}/checkout', [MarketplaceController::class, 'checkout'])->whereNumber('booking');
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

    Route::get('bookings', [BookingController::class, 'index']);
    Route::get('bookings/{booking}', [BookingController::class, 'show']);
    Route::post('bookings', [BookingController::class, 'store']);
    Route::post('bookings/{booking}/confirm', [BookingController::class, 'confirm']);
    Route::post('bookings/{booking}/cancel', [BookingController::class, 'cancel']);
    Route::post('bookings/{booking}/passengers', [BookingController::class, 'addPassengers'])->whereNumber('booking');
    Route::get('bookings/{booking}/passengers', [BookingController::class, 'getPassengers'])->whereNumber('booking');
    Route::get('bookings/{booking}/voucher', [BookingController::class, 'downloadVoucher'])->whereNumber('booking');

    Route::get('invoices', [InvoiceController::class, 'index']);
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
    Route::get('commissions/{commission}', [CommissionController::class, 'show'])->whereNumber('commission');
    Route::patch('commissions/{commission}', [CommissionController::class, 'updatePolicy'])->whereNumber('commission');
    Route::post('commissions/{commission}/deactivate', [CommissionController::class, 'deactivatePolicy'])->whereNumber('commission');
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

    Route::prefix('platform-admin')->group(function () {
        Route::get('applications', [CompanyApplicationController::class, 'index']);
        Route::get('applications/{id}', [CompanyApplicationController::class, 'show'])->whereNumber('id');
        Route::post('applications/{id}/approve', [PlatformAdminController::class, 'approveApplication'])->whereNumber('id');
        Route::post('applications/{id}/reject', [PlatformAdminController::class, 'rejectApplication'])->whereNumber('id');
        Route::get('stats', [PlatformAdminController::class, 'stats']);
        Route::get('companies', [PlatformAdminController::class, 'companies']);
        Route::patch('companies/{company}/governance', [PlatformAdminController::class, 'changeGovernance'])->whereNumber('company');
        Route::patch('companies/{company}/permissions', [PlatformAdminController::class, 'updateCompanyPermissions'])->whereNumber('company');
        Route::patch('companies/{company}/toggle-seller', [PlatformAdminController::class, 'toggleCompanySellerStatus'])->whereNumber('company');
        Route::get('seller-applications', [PlatformAdminController::class, 'listSellerApplications']);
        Route::post('seller-applications/{id}/approve', [PlatformAdminController::class, 'approveSellerApplication'])->whereNumber('id');
        Route::post('seller-applications/{id}/reject', [PlatformAdminController::class, 'rejectSellerApplication'])->whereNumber('id');
        Route::get('approvals', [PlatformAdminController::class, 'approvals']);
        Route::post('approvals/{approval}/approve', [PlatformAdminController::class, 'approveApproval'])->whereNumber('approval');
        Route::post('approvals/{approval}/reject', [PlatformAdminController::class, 'rejectApproval'])->whereNumber('approval');
        Route::get('package-orders', [PlatformAdminController::class, 'packageOrders']);
        Route::get('payments', [PlatformAdminController::class, 'payments']);
        Route::get('finance-summary', [PlatformAdminController::class, 'financeSummary']);
        Route::get('packages', [PlatformAdminController::class, 'packages']);
        Route::post('packages/{package}/deactivate', [PlatformAdminController::class, 'deactivatePackage'])->whereNumber('package');
        Route::get('reviews', [PlatformAdminController::class, 'listAllReviews']);
        Route::get('settings', [PlatformAdminController::class, 'getSettings']);
        Route::patch('settings/{key}', [PlatformAdminController::class, 'updateSetting']);
        Route::post('reviews/{review}/moderate', [ReviewController::class, 'moderateReview'])->whereNumber('review');
        Route::get('users', [PlatformAdminController::class, 'listUsers']);
        Route::patch('users/{id}/deactivate', [PlatformAdminController::class, 'deactivateUser'])->whereNumber('id');

        Route::get('banners', [PlatformAdminBannerController::class, 'index']);
        Route::post('banners', [PlatformAdminBannerController::class, 'store']);
        Route::patch('banners/{banner}', [PlatformAdminBannerController::class, 'update'])->whereNumber('banner');
        Route::delete('banners/{banner}', [PlatformAdminBannerController::class, 'destroy'])->whereNumber('banner');
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

    // Admin support (Bearer): mirrors Blade admin/support* + SupportService (super admin or company admin with role membership).
    Route::get('support/tickets', [SupportAdminController::class, 'index']);
    Route::get('support/tickets/{id}', [SupportAdminController::class, 'show'])->whereNumber('id');
    Route::post('support/tickets/{id}/messages', [SupportAdminController::class, 'storeMessage'])->whereNumber('id');
});
