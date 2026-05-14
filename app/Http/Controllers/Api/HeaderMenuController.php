<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HeaderMenuItem;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Header menu CRUD + sync. Reads from header_menu_items table.
 * Public read returns the tree localized to ?lang. Admin write
 * is a single PUT (full replace) for atomic drag-to-reorder.
 *
 * Sprint 2 Steps 2.1-2.2.
 */
class HeaderMenuController extends Controller
{
    public function __construct(private AdminAccessService $adminAccessService) {}

    public function show(Request $request): JsonResponse
    {
        $lang = $this->resolveLang($request);

        $items = HeaderMenuItem::query()
            ->where('is_visible', true)
            ->orderBy('position')
            ->get();

        $byParent = $items->groupBy(fn ($i) => $i->parent_id);

        $tree = ($byParent[null] ?? collect())
            ->map(fn ($root) => $this->serializeWithChildren($root, $byParent, $lang))
            ->values();

        // Browser caches the 3-language payload for 5 minutes; admin edits
        // bust the corresponding header_menu rows and the next request after
        // expiry re-fetches. With a multi-language payload the frontend can
        // switch languages instantly client-side — no SSR round-trip.
        return response()
            ->json([
                'success' => true,
                'data' => ['items' => $tree, 'lang' => $lang],
            ])
            ->header('Cache-Control', 'public, max-age=300, stale-while-revalidate=600');
    }

    public function adminIndex(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $items = HeaderMenuItem::query()
            ->orderBy('position')
            ->get(['id', 'parent_id', 'label_en', 'label_ru', 'label_hy', 'url', 'position', 'is_visible', 'icon', 'open_in_new_tab']);

        return response()->json([
            'success' => true,
            'data' => ['items' => $items],
        ]);
    }

    /**
     * Full-replace sync. Body: { items: [{ id?, parent_id?, label_*, url, position, is_visible, icon?, open_in_new_tab? }] }
     * Items without `id` are inserted; those with `id` are updated; rows
     * not in the payload are deleted. Wrapped in a transaction.
     */
    public function adminSync(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.parent_id' => ['nullable', 'integer'],
            'items.*.label_en' => ['required', 'string', 'max:255'],
            'items.*.label_ru' => ['nullable', 'string', 'max:255'],
            'items.*.label_hy' => ['nullable', 'string', 'max:255'],
            'items.*.url' => ['required', 'string', 'max:2048'],
            'items.*.position' => ['required', 'integer', 'min:0'],
            'items.*.is_visible' => ['required', 'boolean'],
            'items.*.icon' => ['nullable', 'string', 'max:64'],
            'items.*.open_in_new_tab' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data) {
            $existingIds = HeaderMenuItem::query()->pluck('id')->all();
            $keepIds = [];

            // Two-pass to support new parent rows: first insert/update
            // without parent_id, second pass sets parent_id.
            $idMap = [];
            foreach ($data['items'] as $row) {
                $attrs = [
                    'label_en' => $row['label_en'],
                    'label_ru' => $row['label_ru'] ?? null,
                    'label_hy' => $row['label_hy'] ?? null,
                    'url' => $row['url'],
                    'position' => (int) $row['position'],
                    'is_visible' => (bool) $row['is_visible'],
                    'icon' => $row['icon'] ?? null,
                    'open_in_new_tab' => (bool) ($row['open_in_new_tab'] ?? false),
                ];
                // Positive ids reference existing rows. Negative / zero / null
                // ids are temp identifiers the caller uses to wire parent_id
                // refs between freshly-created rows in the same payload.
                $rowId = $row['id'] ?? null;
                $isExisting = is_numeric($rowId) && (int) $rowId > 0;

                if ($isExisting) {
                    $item = HeaderMenuItem::query()->find((int) $rowId);
                    if ($item) {
                        $item->update($attrs);
                        $idMap[(int) $rowId] = $item->id;
                        $keepIds[] = $item->id;
                    }
                } else {
                    $item = HeaderMenuItem::query()->create($attrs);
                    if ($rowId !== null) {
                        $idMap[$rowId] = $item->id;
                    }
                    $keepIds[] = $item->id;
                }
            }

            // Second pass: parent_id
            foreach ($data['items'] as $row) {
                $tempId = $row['id'] ?? null;
                if ($tempId === null) {
                    continue;
                }
                $realId = $idMap[$tempId] ?? null;
                if (! $realId) {
                    continue;
                }
                $parentId = null;
                if (! empty($row['parent_id'])) {
                    $parentId = $idMap[$row['parent_id']] ?? null;
                }
                HeaderMenuItem::query()->where('id', $realId)->update(['parent_id' => $parentId]);
            }

            // Delete rows not in payload
            $toDelete = array_diff($existingIds, $keepIds);
            if (! empty($toDelete)) {
                HeaderMenuItem::query()->whereIn('id', $toDelete)->delete();
            }
        });

        return $this->adminIndex($request);
    }

    private function resolveLang(Request $request): string
    {
        $raw = strtolower((string) $request->query('lang', 'en'));

        return in_array($raw, ['en', 'ru', 'hy'], true) ? $raw : 'en';
    }

    private function serializeWithChildren(HeaderMenuItem $item, $byParent, string $lang): array
    {
        $labelField = 'label_'.$lang;
        $children = ($byParent[$item->id] ?? collect())
            ->map(fn ($c) => $this->serializeWithChildren($c, $byParent, $lang))
            ->values();

        return [
            'id' => $item->id,
            // `label` stays for back-compat / SSR pre-paint with the cookie's
            // language; client picks from `labels` once the user toggles.
            'label' => $item->{$labelField} ?: $item->label_en,
            'labels' => [
                'en' => $item->label_en,
                'hy' => $item->label_hy ?: $item->label_en,
                'ru' => $item->label_ru ?: $item->label_en,
            ],
            'url' => $item->url,
            'icon' => $item->icon,
            'open_in_new_tab' => (bool) $item->open_in_new_tab,
            'children' => $children,
        ];
    }

    private function denyUnlessAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}
