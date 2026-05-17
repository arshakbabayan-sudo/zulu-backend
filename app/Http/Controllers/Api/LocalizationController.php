<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\TranslateContentJob;
use App\Models\Car;
use App\Models\Company;
use App\Models\ContentTranslation;
use App\Models\Excursion;
use App\Models\Flight;
use App\Models\Hotel;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Offer;
use App\Models\Package;
use App\Models\SupportedLanguage;
use App\Models\Transfer;
use App\Models\Visa;
use App\Services\Admin\AdminAccessService;
use App\Services\Localization\LocalizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LocalizationController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function languages(Request $request, LocalizationService $service): JsonResponse
    {
        $langs = $service->getSupportedLanguages(true)->map(fn ($row) => [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'name_en' => $row->name_en,
            'is_default' => (bool) $row->is_default,
            'sort_order' => (int) $row->sort_order,
        ])->values();

        return response()
            ->json([
                'success' => true,
                'data' => $langs,
            ])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }

    public function translations(Request $request, LocalizationService $service): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(ContentTranslation::ENTITY_TYPES)],
            'entity_id' => ['required', 'integer', 'min:1'],
            'lang' => ['sometimes', 'string', 'max:8'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', Rule::in(ContentTranslation::TRANSLATABLE_FIELDS)],
        ]);

        $langRaw = $validated['lang'] ?? $request->query('lang');
        if ($langRaw === null || $langRaw === '') {
            $langRaw = $request->attributes->get('lang', 'en');
        }
        $lang = is_string($langRaw) ? $langRaw : 'en';
        $lang = $service->resolveLanguage($lang);
        $fields = $validated['fields'] ?? [];

        $translations = $service->getTranslations(
            $validated['entity_type'],
            (int) $validated['entity_id'],
            $lang,
            $fields
        );

        return response()->json([
            'success' => true,
            'data' => [
                'entity_type' => $validated['entity_type'],
                'entity_id' => (int) $validated['entity_id'],
                'language_code' => $lang,
                'translations' => $translations,
                'available_fields' => ContentTranslation::TRANSLATABLE_FIELDS,
            ],
        ]);
    }

    public function getTemplate(Request $request, string $event, LocalizationService $service): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['sometimes', 'string', Rule::in(NotificationTemplate::CHANNELS)],
        ]);

        $langRaw = $request->query('lang') ?? $request->attributes->get('lang', 'en');
        $lang = is_string($langRaw) ? $langRaw : 'en';
        $lang = $service->resolveLanguage($lang);
        $channelRaw = $validated['channel'] ?? $request->query('channel', 'in_app');
        $channel = is_string($channelRaw) ? $channelRaw : 'in_app';

        $template = $service->getNotificationTemplate($event, $lang, $channel);
        if ($template === null) {
            return response()->json([
                'success' => false,
                'message' => 'Template not found.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'event_type' => $template->event_type,
                'language_code' => $template->language_code,
                'channel' => $template->channel,
                'title_template' => $template->title_template,
                'body_template' => $template->body_template,
                'is_active' => (bool) $template->is_active,
            ],
        ]);
    }

    public function setTranslations(Request $request, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(ContentTranslation::ENTITY_TYPES)],
            'entity_id' => ['required', 'integer', 'min:1'],
            'language_code' => ['required', 'string', 'max:8'],
            'translations' => ['required', 'array', 'min:1'],
        ]);

        foreach ($validated['translations'] as $fieldName => $value) {
            if (! is_string($fieldName) || ! in_array($fieldName, ContentTranslation::TRANSLATABLE_FIELDS, true)) {
                throw ValidationException::withMessages([
                    'translations' => ['Invalid translatable field: '.$fieldName],
                ]);
            }
            if (! is_string($value)) {
                throw ValidationException::withMessages([
                    'translations' => ['Each translation value must be a string.'],
                ]);
            }
        }

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyId = $this->resolveOwningCompanyId($validated['entity_type'], (int) $validated['entity_id']);
            if ($companyId === null || ! $user->belongsToCompany($companyId)) {
                throw ValidationException::withMessages([
                    'entity_id' => ['You may only manage translations for entities in your company.'],
                ]);
            }
        }

        try {
            $languageCode = $service->resolveWritableLanguage($validated['language_code']);
            $service->setTranslations(
                $validated['entity_type'],
                (int) $validated['entity_id'],
                $languageCode,
                $validated['translations'],
                isManualEdit: true,
                translationStatus: 'manual',
                respectManualLock: false,
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'language_code' => [$e->getMessage()],
            ]);
        }

        // If the operator just edited the source-language version, dispatch
        // the AI translator to refresh every OTHER language. Other-language
        // edits don't trigger re-translation — we trust the operator that
        // their HY/RU/etc. edit is intentional and complete on its own.
        $sourceLang = $this->resolveEntitySourceLang($validated['entity_type'], (int) $validated['entity_id']);
        $aiDispatched = false;
        if ($sourceLang !== null && $sourceLang === $languageCode && (bool) env('AI_TRANSLATE_AUTO', true)) {
            $sourceValues = [];
            foreach ($validated['translations'] as $field => $value) {
                if (is_string($value) && trim($value) !== '') {
                    $sourceValues[(string) $field] = (string) $value;
                }
            }
            if ($sourceValues !== []) {
                TranslateContentJob::dispatch(
                    $validated['entity_type'],
                    (int) $validated['entity_id'],
                    $sourceValues,
                    $sourceLang,
                );
                $aiDispatched = true;
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'entity_type' => $validated['entity_type'],
                'entity_id' => (int) $validated['entity_id'],
                'language_code' => $languageCode,
                'fields_saved' => count($validated['translations']),
                'ai_translation_dispatched' => $aiDispatched,
                'source_language' => $sourceLang,
            ],
        ]);
    }

    /**
     * Return every translation row for every enabled language, grouped by
     * language code and then by field name. Includes the entity's
     * `source_lang` so the admin form can mark the source tab.
     *
     * Response shape:
     * {
     *   "source_lang": "hy",
     *   "languages": {
     *     "en": {"hotel_name": {"value": "...", "is_manually_edited": false, "translation_status": "ai_completed"}, ...},
     *     "hy": {...},
     *     "ru": {...}
     *   }
     * }
     */
    public function allLanguagesForEntity(
        Request $request,
        string $entityType,
        int $entityId,
        LocalizationService $service
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($entityType, ContentTranslation::ENTITY_TYPES, true)) {
            return response()->json(['success' => false, 'message' => 'Invalid entity_type.'], 422);
        }

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyId = $this->resolveOwningCompanyId($entityType, $entityId);
            if ($companyId === null || ! $user->belongsToCompany($companyId)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $sourceLang = $this->resolveEntitySourceLang($entityType, $entityId);

        $rows = ContentTranslation::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get(['language_code', 'field_name', 'translated_value', 'is_manually_edited', 'translation_status']);

        $enabledLangs = $service->getSupportedLanguages(true)->pluck('code')->all();

        $byLanguage = [];
        foreach ($enabledLangs as $lang) {
            $byLanguage[(string) $lang] = [];
        }
        foreach ($rows as $row) {
            $lang = (string) $row->language_code;
            if (! array_key_exists($lang, $byLanguage)) {
                $byLanguage[$lang] = [];
            }
            $byLanguage[$lang][(string) $row->field_name] = [
                'value' => (string) $row->translated_value,
                'is_manually_edited' => (bool) ($row->is_manually_edited ?? false),
                'translation_status' => (string) ($row->translation_status ?? 'manual'),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'source_lang' => $sourceLang,
                'languages' => $byLanguage,
                'available_fields' => ContentTranslation::TRANSLATABLE_FIELDS,
            ],
        ]);
    }

    /**
     * Force the AI translator to refresh translations for specific target
     * languages — used by the admin "Re-translate" button. Manual locks on
     * those target rows are ignored (cleared and overwritten by the job).
     *
     * Body:
     *   { "target_locales": ["ru","en"]  // optional; default = all except source
     *     "fields": ["hotel_name"]       // optional; default = all translatable }
     */
    public function retranslateEntity(
        Request $request,
        string $entityType,
        int $entityId,
        LocalizationService $service
    ): JsonResponse {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($entityType, ContentTranslation::ENTITY_TYPES, true)) {
            return response()->json(['success' => false, 'message' => 'Invalid entity_type.'], 422);
        }

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyId = $this->resolveOwningCompanyId($entityType, $entityId);
            if ($companyId === null || ! $user->belongsToCompany($companyId)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
        }

        $validated = $request->validate([
            'target_locales' => ['sometimes', 'array'],
            'target_locales.*' => ['string', 'max:8'],
            'fields' => ['sometimes', 'array'],
            'fields.*' => ['string', Rule::in(ContentTranslation::TRANSLATABLE_FIELDS)],
        ]);

        $sourceLang = $this->resolveEntitySourceLang($entityType, $entityId);
        if ($sourceLang === null) {
            return response()->json([
                'success' => false,
                'message' => 'This entity has no source_lang set. Cannot retranslate.',
            ], 422);
        }

        $sourceRows = ContentTranslation::query()
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->where('language_code', $sourceLang)
            ->when(! empty($validated['fields']), fn ($q) => $q->whereIn('field_name', $validated['fields']))
            ->get(['field_name', 'translated_value']);

        $sourceValues = [];
        foreach ($sourceRows as $row) {
            $value = (string) $row->translated_value;
            if (trim($value) === '') {
                continue;
            }
            $sourceValues[(string) $row->field_name] = $value;
        }

        if ($sourceValues === []) {
            return response()->json([
                'success' => false,
                'message' => 'No source-language content found to translate from. Fill the source language first.',
            ], 422);
        }

        $targetLocales = null;
        if (! empty($validated['target_locales'])) {
            $enabled = $service->getSupportedLanguages(true)->pluck('code')->all();
            $targetLocales = array_values(array_filter(
                $validated['target_locales'],
                fn ($l) => in_array(strtolower((string) $l), array_map('strtolower', $enabled), true)
                    && strtolower((string) $l) !== strtolower($sourceLang)
            ));
        }

        TranslateContentJob::dispatch(
            $entityType,
            $entityId,
            $sourceValues,
            $sourceLang,
            forceOverwrite: true,
            onlyLocales: $targetLocales,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'source_lang' => $sourceLang,
                'target_locales' => $targetLocales,
                'fields_count' => count($sourceValues),
                'queued' => true,
            ],
        ]);
    }

    /**
     * Read the entity's `source_lang` column without loading the full model.
     * Returns null if the column is missing (pre-migration deploy) so callers
     * can degrade gracefully.
     */
    private function resolveEntitySourceLang(string $entityType, int $entityId): ?string
    {
        $table = match ($entityType) {
            'hotel' => 'hotels',
            'excursion' => 'excursions',
            'transfer' => 'transfers',
            'car' => 'cars',
            'visa' => 'visas',
            'package' => 'packages',
            'flight' => 'flights',
            'offer' => 'offers',
            'company' => 'companies',
            default => null,
        };
        if ($table === null) {
            return null;
        }
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'source_lang')) {
            return null;
        }

        $value = DB::table($table)->where('id', $entityId)->value('source_lang');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function deleteTranslations(Request $request, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(ContentTranslation::ENTITY_TYPES)],
            'entity_id' => ['required', 'integer', 'min:1'],
            'language_code' => ['nullable', 'string', 'max:8'],
        ]);

        try {
            $languageCode = null;
            if (is_string($validated['language_code'] ?? null) && $validated['language_code'] !== '') {
                $languageCode = $service->resolveWritableLanguage($validated['language_code']);
            }
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'language_code' => [$e->getMessage()],
            ]);
        }

        $deleted = $service->deleteTranslations(
            $validated['entity_type'],
            (int) $validated['entity_id'],
            $languageCode
        );

        return response()->json([
            'success' => true,
            'data' => [
                'deleted_count' => $deleted,
            ],
        ]);
    }

    public function adminLanguages(Request $request, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $langs = $service->getAllLanguages()->map(fn ($row) => [
            'id' => (int) $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'name_en' => $row->name_en,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'rtl' => (bool) ($row->rtl ?? false),
            'sort_order' => (int) $row->sort_order,
        ])->values();

        return response()->json(['success' => true, 'data' => $langs]);
    }

    public function setDefaultLanguage(Request $request, SupportedLanguage $language, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $updated = $service->setDefaultLanguage($language);

        return response()->json(['success' => true, 'data' => [
            'id' => $updated->id,
            'code' => $updated->code,
            'is_default' => (bool) $updated->is_default,
        ]]);
    }

    public function editLanguage(Request $request, SupportedLanguage $language, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:64'],
            'rtl' => ['sometimes', 'boolean'],
        ]);

        $updated = $service->updateLanguage(
            $language,
            $validated['name'],
            $validated['name_en'],
            (bool) ($validated['rtl'] ?? false)
        );

        return response()->json(['success' => true, 'data' => [
            'id' => $updated->id,
            'code' => $updated->code,
            'name' => $updated->name,
            'name_en' => $updated->name_en,
            'rtl' => (bool) $updated->rtl,
            'is_default' => (bool) $updated->is_default,
            'is_enabled' => (bool) $updated->is_enabled,
            'sort_order' => (int) $updated->sort_order,
        ]]);
    }

    public function toggleLanguage(Request $request, SupportedLanguage $language, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $updated = $service->toggleLanguageEnabled($language);

        return response()->json([
            'success' => true,
            'data' => [
                'code' => $updated->code,
                'name' => $updated->name,
                'name_en' => $updated->name_en,
                'is_default' => (bool) $updated->is_default,
                'is_enabled' => (bool) $updated->is_enabled,
                'sort_order' => (int) $updated->sort_order,
            ],
        ]);
    }

    public function updateTemplate(Request $request, string $event, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        if (! in_array($event, Notification::EVENT_TYPES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid event type.',
            ], 404);
        }

        $validated = $request->validate([
            'lang' => ['required', 'string', 'max:8'],
            'channel' => ['required', 'string', Rule::in(NotificationTemplate::CHANNELS)],
            'title_template' => ['required', 'string', 'max:512'],
            'body_template' => ['required', 'string', 'max:65000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $languageCode = $service->resolveWritableLanguage($validated['lang']);
            $template = $service->upsertNotificationTemplate(
                $event,
                $languageCode,
                $validated['channel'],
                $validated['title_template'],
                $validated['body_template'],
                array_key_exists('is_active', $validated) ? (bool) $validated['is_active'] : null
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'lang' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $template->id,
                'event_type' => $template->event_type,
                'language_code' => $template->language_code,
                'channel' => $template->channel,
                'title_template' => $template->title_template,
                'body_template' => $template->body_template,
                'is_active' => (bool) $template->is_active,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function uiTranslations(Request $request, LocalizationService $service): JsonResponse
    {
        $langRaw = $request->query('lang');
        if ($langRaw === null || $langRaw === '') {
            $langRaw = $request->attributes->get('lang', 'en');
        }
        $lang = is_string($langRaw) ? $langRaw : 'en';

        // `?lang=all` returns all supported languages in one payload so the SPA
        // can preload everything at SSR and switch languages later without any
        // network roundtrip. Iterates supported_languages dynamically so new
        // languages added by admins flow through without a code change.
        if (strtolower($lang) === 'all') {
            $codes = SupportedLanguage::query()
                ->where('is_enabled', true)
                ->orderBy('sort_order')
                ->pluck('code')
                ->all();

            $bundle = [];
            foreach ($codes as $code) {
                $bundle[$code] = $service->getUiTranslations((string) $code);
            }

            return response()
                ->json([
                    'success' => true,
                    'data' => [
                        'language_code' => 'all',
                        'translations' => $bundle,
                    ],
                ])
                ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
        }

        $lang = $service->resolveLanguage($lang);
        $translations = $service->getUiTranslations($lang);

        return response()
            ->json([
                'success' => true,
                'data' => [
                    'language_code' => $lang,
                    'translations' => $translations,
                ],
            ])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }

    public function uiTranslationsPaginated(Request $request, LocalizationService $service): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['required', 'string', 'max:8'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'search' => ['sometimes', 'string', 'max:100'],
        ]);

        $result = $service->getUiTranslationsPaginated(
            $service->resolveLanguage($validated['lang']),
            (int) ($validated['page'] ?? 1),
            (int) ($validated['per_page'] ?? 50),
            (string) ($validated['search'] ?? '')
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function setUiTranslations(Request $request, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'language_code' => ['required', 'string', 'max:8'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*' => ['string'],
        ]);

        try {
            $languageCode = $service->resolveWritableLanguage($validated['language_code']);
            $count = $service->setUiTranslations($languageCode, $validated['translations']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['saved' => $count, 'language_code' => $languageCode],
        ]);
    }

    /**
     * Trigger the AI translator scan. Runs the artisan command
     * synchronously (which only enqueues jobs — actual translation
     * work happens in the queue worker), captures its console output,
     * and returns it to the admin UI so the user can see what was
     * dispatched.
     *
     * The scan stays opt-in: nothing here runs on cron.
     */
    public function scanTranslationGaps(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'scope' => ['sometimes', 'string', Rule::in(['ui', 'content', 'both'])],
            'dry_run' => ['sometimes', 'boolean'],
            'overwrite' => ['sometimes', 'boolean'],
            'source' => ['sometimes', 'string', 'max:8'],
            'limit' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ]);

        $scope = $validated['scope'] ?? 'both';
        $options = [];
        if ($scope === 'ui') {
            $options['--ui'] = true;
        } elseif ($scope === 'content') {
            $options['--content'] = true;
        }
        if (! empty($validated['dry_run'])) {
            $options['--dry-run'] = true;
        }
        if (! empty($validated['overwrite'])) {
            $options['--overwrite'] = true;
        }
        if (! empty($validated['source'])) {
            $options['--source'] = (string) $validated['source'];
        }
        if (isset($validated['limit'])) {
            $options['--limit'] = (string) (int) $validated['limit'];
        }

        $exitCode = Artisan::call('translations:scan', $options);
        $output = Artisan::output();

        return response()->json([
            'success' => $exitCode === 0,
            'data' => [
                'scope' => $scope,
                'dry_run' => (bool) ($validated['dry_run'] ?? false),
                'overwrite' => (bool) ($validated['overwrite'] ?? false),
                'output' => $output,
            ],
        ]);
    }

    /**
     * Quick health check: how many translator jobs are queued / have
     * failed since the last clear. Drives the admin UI's progress
     * indicator after the user clicks "Scan + translate gaps".
     */
    public function scanStatus(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $jobClasses = [
            'App\\Jobs\\TranslateUiStringJob',
            'App\\Jobs\\TranslateContentJob',
        ];

        $pending = $this->countJobsByPayload('jobs', $jobClasses);
        $failed = $this->countJobsByPayload('failed_jobs', $jobClasses);

        return response()->json([
            'success' => true,
            'data' => [
                'pending' => $pending,
                'failed' => $failed,
            ],
        ]);
    }

    /**
     * @param  list<string>  $classes
     * @return array<string, int>
     */
    private function countJobsByPayload(string $table, array $classes): array
    {
        $result = [];
        foreach ($classes as $class) {
            $short = (string) class_basename($class);
            try {
                $result[$short] = (int) DB::table($table)
                    ->where('payload', 'like', '%'.$class.'%')
                    ->count();
            } catch (\Throwable) {
                $result[$short] = 0;
            }
        }
        $result['total'] = array_sum($result);

        return $result;
    }

    public function createLanguage(Request $request, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:8'],
            'name' => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:64'],
        ]);

        try {
            $lang = $service->createLanguage(
                $validated['code'],
                $validated['name'],
                $validated['name_en']
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $lang->id,
                'code' => $lang->code,
                'name' => $lang->name,
                'name_en' => $lang->name_en,
                'is_default' => $lang->is_default,
                'is_enabled' => $lang->is_enabled,
                'sort_order' => $lang->sort_order,
            ],
        ], 201);
    }

    public function deleteLanguage(Request $request, SupportedLanguage $language, LocalizationService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }
        if (! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        try {
            $service->deleteLanguage($language);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    private function resolveOwningCompanyId(string $entityType, int $entityId): ?int
    {
        return match ($entityType) {
            'offer' => Offer::query()->whereKey($entityId)->value('company_id'),
            'package' => Package::query()->whereKey($entityId)->value('company_id'),
            'hotel' => Hotel::query()->whereKey($entityId)->value('company_id'),
            'flight' => Flight::query()->whereKey($entityId)->value('company_id'),
            'transfer' => Transfer::query()->whereKey($entityId)->value('company_id'),
            'car' => Car::query()->with('offer')->find($entityId)?->offer?->company_id,
            'excursion' => Excursion::query()->with('offer')->find($entityId)?->offer?->company_id,
            'visa' => Visa::query()->with('offer')->find($entityId)?->offer?->company_id,
            'company' => Company::query()->whereKey($entityId)->exists() ? $entityId : null,
            default => null,
        };
    }
}
