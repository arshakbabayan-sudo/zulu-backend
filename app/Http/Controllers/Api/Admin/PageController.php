<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Models\WidgetContent;
use App\Models\WidgetContentTranslation;
use App\Services\Admin\AdminAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $paginator = Page::query()
            ->orderByDesc('id')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $paginator->items(),
            'meta' => $this->meta($paginator),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'page_name' => ['required', 'string', 'max:255'],
            'page_slug' => ['required', 'string', 'max:255', 'alpha_dash', 'unique:pages,page_slug'],
        ]);

        $page = Page::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Page created successfully',
            'data' => $page,
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $page = Page::query()
            ->with([
                'translations',
                'widgetContents.widget',
                'widgetContents.translations',
            ])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $page = Page::query()->findOrFail($id);

        $validated = $request->validate([
            'page_name' => ['sometimes', 'string', 'max:255'],
            'page_slug' => ['sometimes', 'string', 'max:255', 'alpha_dash', Rule::unique('pages', 'page_slug')->ignore($page->id)],
            'meta_title' => ['nullable', 'string', 'max:255'],
            'meta_keywords' => ['nullable', 'array'],
            'meta_description' => ['nullable', 'string'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'enable_seo' => ['sometimes', 'boolean'],
            'is_bread_crumb' => ['sometimes', 'boolean'],
        ]);

        $page->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Page updated successfully',
            'data' => $page->fresh(),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $page = Page::query()->findOrFail($id);
        $page->delete();

        return response()->json([
            'success' => true,
            'message' => 'Page deleted successfully',
            'data' => ['id' => $id],
        ]);
    }

    public function changeStatus(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'page_id' => ['required', 'integer', 'exists:pages,id'],
            'status' => ['required', 'integer', 'in:0,1'],
        ]);

        $page = Page::query()->findOrFail((int) $validated['page_id']);
        $page->update(['status' => (int) $validated['status']]);

        return response()->json([
            'success' => true,
            'message' => 'Page status changed successfully',
            'data' => $page->fresh(),
        ]);
    }

    public function addWidget(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'page_id' => ['required', 'integer', 'exists:pages,id'],
            'widget_slug' => ['required', 'string', 'exists:widgets,widget_slug'],
            'widget_content' => ['nullable', 'array'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
        ]);

        $pageId = (int) $validated['page_id'];

        $nextPosition = ((int) WidgetContent::query()
            ->where('page_id', $pageId)
            ->max('position')) + 1;

        $widgetContent = WidgetContent::create([
            'page_id' => $pageId,
            'widget_slug' => $validated['widget_slug'],
            'ui_card_number' => $this->generateUniqueCardNumber(),
            'widget_content' => $validated['widget_content'] ?? [],
            'position' => $nextPosition,
            'status' => (int) ($validated['status'] ?? 1),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Widget added successfully',
            'data' => $widgetContent->load(['widget', 'translations']),
        ], 201);
    }

    public function updateWidget(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'widget_content_id' => ['required', 'integer', 'exists:widget_contents,id'],
            'widget_content' => ['nullable', 'array'],
            'status' => ['sometimes', 'integer', 'in:0,1'],
            'translations' => ['nullable', 'array'],
            'translations.*.lang' => ['required_with:translations', 'string', 'max:5'],
            'translations.*.widget_content' => ['nullable', 'array'],
        ]);

        /** @var WidgetContent $widgetContent */
        $widgetContent = WidgetContent::query()->findOrFail((int) $validated['widget_content_id']);

        DB::transaction(function () use ($validated, $widgetContent): void {
            $updatePayload = [];
            if (array_key_exists('widget_content', $validated)) {
                $updatePayload['widget_content'] = $validated['widget_content'] ?? [];
            }
            if (array_key_exists('status', $validated)) {
                $updatePayload['status'] = (int) $validated['status'];
            }
            if ($updatePayload !== []) {
                $widgetContent->update($updatePayload);
            }

            $translations = $validated['translations'] ?? [];
            foreach ($translations as $translation) {
                WidgetContentTranslation::query()->updateOrCreate(
                    [
                        'widget_content_id' => $widgetContent->id,
                        'lang' => (string) $translation['lang'],
                    ],
                    [
                        'page_id' => $widgetContent->page_id,
                        'widget_content' => $translation['widget_content'] ?? [],
                    ]
                );
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Widget updated successfully',
            'data' => $widgetContent->fresh()->load(['widget', 'translations']),
        ]);
    }

    public function sortWidgets(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'positions' => ['required', 'array', 'min:1'],
            'positions.*.id' => ['required', 'integer', 'exists:widget_contents,id'],
            'positions.*.position' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($validated): void {
            foreach ($validated['positions'] as $item) {
                WidgetContent::query()
                    ->where('id', (int) $item['id'])
                    ->update(['position' => (int) $item['position']]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Widgets sorted successfully',
        ]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg,webp', 'max:10240'],
        ]);

        $path = $validated['image']->store('widgets', 'public');

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully',
            'data' => [
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
            ],
        ]);
    }

    public function deleteWidget(Request $request, int $id): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $widgetContent = WidgetContent::query()->findOrFail($id);
        $widgetContent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Widget deleted successfully',
            'data' => ['id' => $id],
        ]);
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if (
            $user === null
            || (! $this->adminAccessService->isSuperAdmin($user) && ! $this->adminAccessService->isPlatformAdmin($user))
        ) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function generateUniqueCardNumber(): string
    {
        do {
            $cardNumber = 'UICARD-'.Str::upper(Str::random(10));
        } while (WidgetContent::query()->where('ui_card_number', $cardNumber)->exists());

        return $cardNumber;
    }

    /**
     * @return array<string, int>
     */
    private function meta(LengthAwarePaginator $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }
}
