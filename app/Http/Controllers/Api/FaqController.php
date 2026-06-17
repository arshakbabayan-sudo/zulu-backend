<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public FAQ / help endpoint (no auth). Returns active entries in the requested
 * language, ordered by category then sort order. The frontend groups by category.
 */
class FaqController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $lang = (string) $request->query('lang', 'en');

        $faqs = Faq::query()
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (Faq $faq): array => $faq->localized($lang))
            ->values()
            ->all();

        return response()->json(['success' => true, 'data' => $faqs]);
    }
}
