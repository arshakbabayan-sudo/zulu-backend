<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform-admin FAQ management (CRUD). Gated by the `platform-admin` middleware
 * on the route group. Returns/accepts all three languages so staff can author
 * hy/ru/en in one form.
 */
class AdminFaqController extends Controller
{
    public function index(): JsonResponse
    {
        $faqs = Faq::query()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return response()->json(['success' => true, 'data' => $faqs]);
    }

    public function store(Request $request): JsonResponse
    {
        $faq = Faq::query()->create($this->validated($request, true));

        return response()->json(['success' => true, 'data' => $faq], 201);
    }

    public function update(Request $request, Faq $faq): JsonResponse
    {
        $faq->update($this->validated($request, false));

        return response()->json(['success' => true, 'data' => $faq->fresh()]);
    }

    public function destroy(Faq $faq): JsonResponse
    {
        $faq->delete();

        return response()->json(['success' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'category' => [$req, 'string', 'max:64'],
            'question_hy' => [$req, 'string', 'max:255'],
            'question_ru' => [$req, 'string', 'max:255'],
            'question_en' => [$req, 'string', 'max:255'],
            'answer_hy' => [$req, 'string'],
            'answer_ru' => [$req, 'string'],
            'answer_en' => [$req, 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
