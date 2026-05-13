<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public read + admin write for the static-page rich-text bodies
 * (about, contact, terms, privacy, cookies). Sprint 3 of ZULU CMS.
 */
class StaticPageController extends Controller
{
    private const ALLOWED_LANGS = ['en', 'ru', 'hy'];

    public function __construct(private AdminAccessService $adminAccessService) {}

    public function show(Request $request, string $slug): JsonResponse
    {
        $page = Page::query()->where('page_slug', $slug)->first();
        if (! $page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }
        $lang = $this->resolveLang($request);
        $field = 'body_html_'.$lang;
        $body = $page->{$field} ?? $page->body_html_en;

        return response()->json([
            'success' => true,
            'data' => [
                'slug' => $page->page_slug,
                'name' => $page->page_name,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'body_html' => $body,
                'lang' => $lang,
            ],
        ]);
    }

    public function adminShow(Request $request, string $slug): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $page = Page::query()->where('page_slug', $slug)->first();
        if (! $page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $page->id,
                'slug' => $page->page_slug,
                'name' => $page->page_name,
                'meta_title' => $page->meta_title,
                'meta_description' => $page->meta_description,
                'body_html_en' => $page->body_html_en,
                'body_html_ru' => $page->body_html_ru,
                'body_html_hy' => $page->body_html_hy,
            ],
        ]);
    }

    public function adminUpdate(Request $request, string $slug): JsonResponse
    {
        if ($deny = $this->denyUnlessAdmin($request)) {
            return $deny;
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'body_html_en' => ['sometimes', 'nullable', 'string'],
            'body_html_ru' => ['sometimes', 'nullable', 'string'],
            'body_html_hy' => ['sometimes', 'nullable', 'string'],
        ]);

        $page = Page::query()->where('page_slug', $slug)->first();
        if (! $page) {
            return response()->json(['success' => false, 'message' => 'Page not found'], 404);
        }

        $fillMap = [
            'name' => 'page_name',
            'meta_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'body_html_en' => 'body_html_en',
            'body_html_ru' => 'body_html_ru',
            'body_html_hy' => 'body_html_hy',
        ];
        foreach ($fillMap as $input => $column) {
            if (array_key_exists($input, $validated)) {
                $page->{$column} = $validated[$input];
            }
        }
        $page->save();

        return $this->adminShow($request, $slug);
    }

    private function resolveLang(Request $request): string
    {
        $raw = strtolower((string) $request->query('lang', 'en'));

        return in_array($raw, self::ALLOWED_LANGS, true) ? $raw : 'en';
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
