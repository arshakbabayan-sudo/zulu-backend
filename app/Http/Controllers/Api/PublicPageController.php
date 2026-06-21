<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Package;
use App\Models\PackageHomepageFeature;
use App\Models\Page;
use App\Services\Pricing\DisplayCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PublicPageController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $lang = $this->resolveLang($request);
        // Part A — DISPLAY-currency for homepage featured-package prices.
        $displayCurrency = app(DisplayCurrencyService::class)->sanitize($request->query('display_currency'));

        $page = Page::query()
            ->where('page_slug', $slug)
            ->where('status', 1)
            ->with([
                'translations',
                'widgetContents' => function ($query): void {
                    $query->where('status', 1)->orderBy('position', 'asc');
                },
                'widgetContents.translations',
            ])
            ->first();

        if ($page === null) {
            return response()->json([
                'success' => false,
                'message' => 'Not found',
            ], 404);
        }

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'id' => $page->id,
                    'page_name' => $page->getTranslation('page_name', $lang),
                    'page_slug' => $page->getTranslation('page_slug', $lang),
                    'meta_title' => $page->getTranslation('meta_title', $lang),
                    'meta_keywords' => $page->getTranslation('meta_keywords', $lang),
                    'meta_description' => $page->getTranslation('meta_description', $lang),
                    'enable_seo' => (bool) $page->enable_seo,
                    'is_bread_crumb' => (bool) $page->is_bread_crumb,
                    'widgets' => $page->widgetContents->map(function ($widgetContent) use ($lang): array {
                        return [
                            'id' => $widgetContent->id,
                            'widget_slug' => $widgetContent->widget_slug,
                            'ui_card_number' => $widgetContent->ui_card_number,
                            'position' => (int) $widgetContent->position,
                            'widget_content' => $widgetContent->getTranslation($lang) ?? [],
                        ];
                    })->values()->all(),
                    'sections' => $slug === 'home-page' ? $this->buildHomeSections($lang, $displayCurrency) : (object) [],
                ],
            ])
            // Public CMS page payloads change only when an admin edits a page
            // or widget. A 60-second browser cache keeps the home-page snappy
            // for repeat hits while still letting edits propagate quickly.
            ->header('Cache-Control', 'public, max-age=60, stale-while-revalidate=300');
    }

    /**
     * Returns the dynamic, relationally-sourced sections for the homepage:
     *  - special_offers / popular_destinations: packages flagged in
     *    `package_homepage_features` (super-admin checkbox-driven)
     *  - partners: operator-type companies with is_partner_visible=true + logo
     *
     * @return array<string, mixed>
     */
    private function buildHomeSections(string $lang, ?string $displayCurrency = null): array
    {
        $hasFeaturesTable = Schema::hasTable('package_homepage_features');
        $hasPartnerColumn = Schema::hasColumn('companies', 'is_partner_visible');

        return [
            'special_offers' => $hasFeaturesTable
                ? $this->packagesForSection(PackageHomepageFeature::SECTION_SPECIAL_OFFERS, $lang, $displayCurrency)
                : [],
            'popular_destinations' => $hasFeaturesTable
                ? $this->packagesForSection(PackageHomepageFeature::SECTION_POPULAR_DESTINATIONS, $lang, $displayCurrency)
                : [],
            'partners' => $hasPartnerColumn
                ? $this->partnerOperators($lang)
                : [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function packagesForSection(string $sectionSlug, string $lang, ?string $displayCurrency = null): array
    {
        $display = app(DisplayCurrencyService::class);

        $rows = Package::query()
            ->join('package_homepage_features as f', 'f.package_id', '=', 'packages.id')
            ->where('f.section_slug', $sectionSlug)
            ->where('f.is_active', true)
            ->where('packages.status', 'active')
            ->where('packages.is_public', true)
            ->orderBy('f.position', 'asc')
            ->orderBy('packages.id', 'asc')
            ->select([
                'packages.id',
                'packages.package_title',
                'packages.package_subtitle',
                'packages.short_description',
                'packages.destination_country',
                'packages.destination_city',
                'packages.duration_days',
                'packages.base_price',
                'packages.display_price_mode',
                'packages.currency',
                'packages.main_image',
                'f.position',
            ])
            ->get();

        return $rows->map(function (Package $pkg) use ($lang, $display, $displayCurrency): array {
            return $display->attach([
                'id' => $pkg->id,
                'title' => $pkg->getTranslated('package_title', $lang),
                'subtitle' => $pkg->getTranslated('package_subtitle', $lang),
                'short_description' => $pkg->getTranslated('short_description', $lang),
                'destination_country' => $pkg->destination_country,
                'destination_city' => $pkg->destination_city,
                'duration_days' => (int) $pkg->duration_days,
                'base_price' => $pkg->base_price,
                'display_price_mode' => $pkg->display_price_mode,
                'currency' => $pkg->currency,
                'main_image' => $pkg->main_image,
                'link' => '/packages/'.$pkg->id,
            ], $pkg->base_price, $pkg->currency, $displayCurrency);
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function partnerOperators(string $lang): array
    {
        $rows = Company::query()
            ->where('is_partner_visible', true)
            ->where('type', 'operator')
            ->where('governance_status', 'active')
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->orderBy('name', 'asc')
            ->get(['id', 'name', 'slug', 'logo', 'website']);

        return $rows->map(function (Company $company) use ($lang): array {
            return [
                'id' => $company->id,
                'partner_name' => method_exists($company, 'getTranslated')
                    ? ($company->getTranslated('name', $lang) ?? $company->name)
                    : $company->name,
                'logo_image' => $company->logo,
                'link' => $company->website ?? '',
            ];
        })->values()->all();
    }

    private function resolveLang(Request $request): string
    {
        $lang = (string) ($request->query('lang') ?? '');
        if ($lang === '') {
            $lang = (string) ($request->header('X-Language') ?? '');
        }

        if ($lang === '') {
            $acceptLanguage = (string) ($request->header('Accept-Language') ?? '');
            $primary = explode(',', $acceptLanguage)[0] ?? '';
            $lang = trim(explode(';', $primary)[0] ?? '');
        }

        if ($lang === '') {
            return (string) config('app.locale', 'en');
        }

        return strtolower(substr($lang, 0, 5));
    }
}
