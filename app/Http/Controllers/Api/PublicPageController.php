<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicPageController extends Controller
{
    public function show(Request $request, string $slug): JsonResponse
    {
        $lang = $this->resolveLang($request);

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

        return response()->json([
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
            ],
        ]);
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
