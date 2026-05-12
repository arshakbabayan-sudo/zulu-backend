<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Infrastructure\PlatformSettingsService;
use App\Services\Localization\LocalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HeroTabsController extends Controller
{
    private const KEY = 'hero_tabs_config';

    private const ALLOWED_SLUGS_DEFAULT = [
        'flights', 'stays', 'packages', 'transfer', 'cars', 'excursions',
        'charter_jets', 'cruises', 'visas',
    ];

    public function show(Request $request, PlatformSettingsService $settings, LocalizationService $localization): JsonResponse
    {
        $raw = $settings->get(self::KEY);
        $tabs = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);

        $langRaw = (string) ($request->query('lang') ?? '');
        $lang = $langRaw !== '' ? $localization->resolveLanguage($langRaw) : 'en';

        $active = array_values(array_filter($tabs, fn ($t) => (bool) ($t['is_active'] ?? true)));
        usort($active, fn ($a, $b) => ($a['position'] ?? 0) <=> ($b['position'] ?? 0));

        return response()->json([
            'success' => true,
            'data' => [
                'tabs' => array_map(function (array $tab) use ($lang, $localization): array {
                    $labelKey = (string) ($tab['label_key'] ?? '');
                    $label = $labelKey !== ''
                        ? ($localization->getUiTranslations($lang)[$labelKey] ?? $labelKey)
                        : ((string) ($tab['slug'] ?? ''));

                    return [
                        'slug' => (string) ($tab['slug'] ?? ''),
                        'label_key' => $labelKey,
                        'label' => $label,
                        'position' => (int) ($tab['position'] ?? 0),
                    ];
                }, $active),
                'lang' => $lang,
            ],
        ]);
    }

    public function update(Request $request, PlatformSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'tabs' => ['required', 'array', 'min:1'],
            'tabs.*.slug' => ['required', 'string', 'max:32'],
            'tabs.*.label_key' => ['required', 'string', 'max:100'],
            'tabs.*.position' => ['required', 'integer', 'min:0'],
            'tabs.*.is_active' => ['required', 'boolean'],
        ]);

        $slugs = array_map(fn ($t) => $t['slug'], $data['tabs']);
        if (count($slugs) !== count(array_unique($slugs))) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate tab slugs',
            ], 422);
        }

        $settings->set(self::KEY, json_encode($data['tabs']));

        return response()->json([
            'success' => true,
            'data' => ['tabs' => $data['tabs']],
        ]);
    }
}
