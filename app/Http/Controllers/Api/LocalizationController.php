<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

        return response()->json([
            'success' => true,
            'data' => $langs,
        ]);
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
            $service->setTranslations(
                $validated['entity_type'],
                (int) $validated['entity_id'],
                $validated['language_code'],
                $validated['translations']
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'language_code' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'entity_type' => $validated['entity_type'],
                'entity_id' => (int) $validated['entity_id'],
                'language_code' => $validated['language_code'],
                'fields_saved' => count($validated['translations']),
            ],
        ]);
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

        $deleted = $service->deleteTranslations(
            $validated['entity_type'],
            (int) $validated['entity_id'],
            $validated['language_code'] ?? null
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
            'id'         => (int) $row->id,
            'code'       => $row->code,
            'name'       => $row->name,
            'name_en'    => $row->name_en,
            'is_default' => (bool) $row->is_default,
            'is_enabled' => (bool) $row->is_enabled,
            'rtl'        => (bool) ($row->rtl ?? false),
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
            'id'         => $updated->id,
            'code'       => $updated->code,
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
            'name'    => ['required', 'string', 'max:64'],
            'name_en' => ['required', 'string', 'max:64'],
            'rtl'     => ['sometimes', 'boolean'],
        ]);

        $updated = $service->updateLanguage(
            $language,
            $validated['name'],
            $validated['name_en'],
            (bool) ($validated['rtl'] ?? false)
        );

        return response()->json(['success' => true, 'data' => [
            'id'         => $updated->id,
            'code'       => $updated->code,
            'name'       => $updated->name,
            'name_en'    => $updated->name_en,
            'rtl'        => (bool) $updated->rtl,
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
            $template = $service->upsertNotificationTemplate(
                $event,
                $validated['lang'],
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
        $lang = $service->resolveLanguage($lang);

        $translations = $service->getUiTranslations($lang);

        return response()->json([
            'success' => true,
            'data' => [
                'language_code' => $lang,
                'translations' => $translations,
            ],
        ]);
    }

    public function uiTranslationsPaginated(Request $request, LocalizationService $service): JsonResponse
    {
        $validated = $request->validate([
            'lang'     => ['required', 'string', 'max:8'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:5', 'max:100'],
            'search'   => ['sometimes', 'string', 'max:100'],
        ]);

        $result = $service->getUiTranslationsPaginated(
            $validated['lang'],
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
            'language_code'  => ['required', 'string', 'max:8'],
            'translations'   => ['required', 'array', 'min:1'],
            'translations.*' => ['string'],
        ]);

        try {
            $count = $service->setUiTranslations($validated['language_code'], $validated['translations']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['saved' => $count],
        ]);
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
            'code'    => ['required', 'string', 'max:8'],
            'name'    => ['required', 'string', 'max:64'],
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
                'id'         => $lang->id,
                'code'       => $lang->code,
                'name'       => $lang->name,
                'name_en'    => $lang->name_en,
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
