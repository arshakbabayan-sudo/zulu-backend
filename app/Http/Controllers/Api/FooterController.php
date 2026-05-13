<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FooterColumn;
use App\Models\FooterLink;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Footer columns + links CRUD / sync. Public read returns columns
 * with nested visible links localized to ?lang. Admin sync is a
 * full replace within a transaction.
 *
 * Sprint 2 Steps 2.1-2.2.
 */
class FooterController extends Controller
{
    public function __construct(private AdminAccessService $adminAccessService) {}

    public function show(Request $request): JsonResponse
    {
        $lang = $this->resolveLang($request);

        $columns = FooterColumn::query()
            ->where('is_visible', true)
            ->orderBy('position')
            ->with(['links' => fn ($q) => $q->where('is_visible', true)->orderBy('position')])
            ->get();

        $labelTitle = 'title_'.$lang;
        $labelLink = 'label_'.$lang;

        $data = $columns->map(function (FooterColumn $col) use ($labelTitle, $labelLink) {
            return [
                'id' => $col->id,
                'slug' => $col->slug,
                'title' => $col->{$labelTitle} ?: $col->title_en,
                'links' => $col->links->map(fn (FooterLink $l) => [
                    'id' => $l->id,
                    'label' => $l->{$labelLink} ?: $l->label_en,
                    'url' => $l->url,
                    'open_in_new_tab' => (bool) $l->open_in_new_tab,
                ])->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => ['columns' => $data, 'lang' => $lang],
        ]);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $columns = FooterColumn::query()
            ->orderBy('position')
            ->with(['links' => fn ($q) => $q->orderBy('position')])
            ->get();

        return response()->json([
            'success' => true,
            'data' => ['columns' => $columns],
        ]);
    }

    public function adminSync(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'columns' => ['present', 'array'],
            'columns.*.id' => ['nullable', 'integer'],
            'columns.*.slug' => ['nullable', 'string', 'max:64'],
            'columns.*.title_en' => ['required', 'string', 'max:255'],
            'columns.*.title_ru' => ['nullable', 'string', 'max:255'],
            'columns.*.title_hy' => ['nullable', 'string', 'max:255'],
            'columns.*.position' => ['required', 'integer', 'min:0'],
            'columns.*.is_visible' => ['required', 'boolean'],
            'columns.*.links' => ['present', 'array'],
            'columns.*.links.*.id' => ['nullable', 'integer'],
            'columns.*.links.*.label_en' => ['required', 'string', 'max:255'],
            'columns.*.links.*.label_ru' => ['nullable', 'string', 'max:255'],
            'columns.*.links.*.label_hy' => ['nullable', 'string', 'max:255'],
            'columns.*.links.*.url' => ['required', 'string', 'max:2048'],
            'columns.*.links.*.position' => ['required', 'integer', 'min:0'],
            'columns.*.links.*.is_visible' => ['required', 'boolean'],
            'columns.*.links.*.open_in_new_tab' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data) {
            $keepColIds = [];

            foreach ($data['columns'] as $col) {
                $slug = $col['slug'] ?? null;
                if (! $slug) {
                    $slug = Str::slug($col['title_en']).'-'.Str::random(4);
                }

                $colAttrs = [
                    'slug' => $slug,
                    'title_en' => $col['title_en'],
                    'title_ru' => $col['title_ru'] ?? null,
                    'title_hy' => $col['title_hy'] ?? null,
                    'position' => (int) $col['position'],
                    'is_visible' => (bool) $col['is_visible'],
                ];

                if (! empty($col['id'])) {
                    $colModel = FooterColumn::query()->find($col['id']);
                    if ($colModel) {
                        $colModel->update($colAttrs);
                    } else {
                        $colModel = FooterColumn::query()->create($colAttrs);
                    }
                } else {
                    $colModel = FooterColumn::query()->create($colAttrs);
                }

                $keepColIds[] = $colModel->id;

                $keepLinkIds = [];
                foreach ($col['links'] as $link) {
                    $linkAttrs = [
                        'column_id' => $colModel->id,
                        'label_en' => $link['label_en'],
                        'label_ru' => $link['label_ru'] ?? null,
                        'label_hy' => $link['label_hy'] ?? null,
                        'url' => $link['url'],
                        'position' => (int) $link['position'],
                        'is_visible' => (bool) $link['is_visible'],
                        'open_in_new_tab' => (bool) ($link['open_in_new_tab'] ?? false),
                    ];

                    if (! empty($link['id'])) {
                        $linkModel = FooterLink::query()->find($link['id']);
                        if ($linkModel) {
                            $linkModel->update($linkAttrs);
                        } else {
                            $linkModel = FooterLink::query()->create($linkAttrs);
                        }
                    } else {
                        $linkModel = FooterLink::query()->create($linkAttrs);
                    }
                    $keepLinkIds[] = $linkModel->id;
                }

                FooterLink::query()
                    ->where('column_id', $colModel->id)
                    ->whereNotIn('id', $keepLinkIds)
                    ->delete();
            }

            FooterColumn::query()
                ->whereNotIn('id', $keepColIds)
                ->delete();
        });

        return $this->adminIndex($request);
    }

    private function resolveLang(Request $request): string
    {
        $raw = strtolower((string) $request->query('lang', 'en'));

        return in_array($raw, ['en', 'ru', 'hy'], true) ? $raw : 'en';
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
