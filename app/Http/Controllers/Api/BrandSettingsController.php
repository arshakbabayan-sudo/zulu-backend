<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAccessService;
use App\Services\Infrastructure\PlatformSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Brand settings — global site-wide config the public frontend
 * (logo, favicon, contact, social links, plus admin-defined custom
 * fields) reads on every page render. Sprint 1 Step 1.2 of the
 * ZULU CMS roadmap.
 *
 * Storage: single platform_settings row keyed by 'brand_settings'
 * with a JSON value. See migration 2026_05_13_001000_seed_brand_settings.
 */
class BrandSettingsController extends Controller
{
    private const KEY = 'brand_settings';

    private const ALLOWED_CUSTOM_TYPES = ['text', 'url', 'email', 'phone', 'image', 'tel'];

    public function __construct(private AdminAccessService $adminAccessService) {}

    /**
     * Public read — used by the public site (Header / Footer / Contact
     * page) and by the admin to render initial state. Returns the brand
     * JSON exactly as stored.
     */
    public function show(PlatformSettingsService $settings): JsonResponse
    {
        $brand = $this->loadBrand($settings);

        return response()->json([
            'success' => true,
            'data' => $brand,
        ]);
    }

    /**
     * Admin update. Body may contain any subset of:
     *   - logo_url, emblem_url, favicon_url  (string|null, <=2048)
     *   - phone, email, address, address_city, address_country (string|null)
     *   - social_links: { facebook, instagram, linkedin, tiktok, youtube, telegram, whatsapp }
     *       (each string|null; admin may also send unknown platform keys —
     *       they're merged through but flagged as "unknown" for future support)
     *   - custom_fields: [ { key, label, type, value } ]
     *       (type in ALLOWED_CUSTOM_TYPES, key is non-empty alphanum/underscore)
     *
     * Unprovided keys are preserved as-is (partial update).
     */
    public function update(Request $request, PlatformSettingsService $settings): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'logo_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'emblem_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'favicon_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'email', 'max:191'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'address_city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'address_country' => ['sometimes', 'nullable', 'string', 'max:120'],

            'social_links' => ['sometimes', 'array'],
            'social_links.*' => ['nullable', 'string', 'max:2048'],

            'custom_fields' => ['sometimes', 'array'],
            'custom_fields.*.key' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/i'],
            'custom_fields.*.label' => ['required', 'string', 'max:120'],
            'custom_fields.*.type' => ['required', 'string', Rule::in(self::ALLOWED_CUSTOM_TYPES)],
            'custom_fields.*.value' => ['nullable', 'string', 'max:2048'],
        ]);

        $current = $this->loadBrand($settings);

        // Merge reserved keys at top level
        foreach ([
            'logo_url', 'emblem_url', 'favicon_url',
            'phone', 'email', 'address', 'address_city', 'address_country',
        ] as $reserved) {
            if (array_key_exists($reserved, $validated)) {
                $current[$reserved] = $validated[$reserved];
            }
        }

        // Merge social_links (preserve existing platform values not in payload)
        if (array_key_exists('social_links', $validated)) {
            $current['social_links'] = array_merge(
                $current['social_links'] ?? [],
                $validated['social_links']
            );
        }

        // Replace custom_fields wholesale when present (admin edits the full list)
        if (array_key_exists('custom_fields', $validated)) {
            // Reject duplicate keys
            $keys = array_map(fn ($f) => $f['key'], $validated['custom_fields']);
            if (count($keys) !== count(array_unique($keys))) {
                return response()->json([
                    'success' => false,
                    'message' => 'Duplicate custom_fields.key values',
                ], 422);
            }
            $current['custom_fields'] = array_map(static fn (array $f) => [
                'key' => (string) $f['key'],
                'label' => (string) $f['label'],
                'type' => (string) $f['type'],
                'value' => isset($f['value']) ? (string) $f['value'] : null,
            ], $validated['custom_fields']);
        }

        $settings->set(self::KEY, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return response()->json([
            'success' => true,
            'data' => $current,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadBrand(PlatformSettingsService $settings): array
    {
        $raw = $settings->get(self::KEY);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return is_array($raw) ? $raw : [];
    }
}
