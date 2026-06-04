<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TravelDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * B2C customer's own travel-document wallet (account "Travel documents").
 * Customer-scoped; each row belongs to the authenticated user.
 */
class TravelDocumentController extends Controller
{
    private const TYPES = ['passport', 'id_card', 'drivers_license', 'loyalty', 'frequent_flyer', 'visa', 'other'];

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'type' => ['required', Rule::in(self::TYPES)],
            'label' => ['nullable', 'string', 'max:120'],
            'number' => ['nullable', 'string', 'max:120'],
            'issuing_country' => ['nullable', 'string', 'max:120'],
            'holder_name' => ['nullable', 'string', 'max:190'],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'file_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $rows = TravelDocument::query()
            ->where('user_id', $request->user()->id)
            ->orderBy('type')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['user_id'] = $request->user()->id;

        return response()->json(['success' => true, 'data' => TravelDocument::query()->create($data)], 201);
    }

    public function update(Request $request, TravelDocument $document): JsonResponse
    {
        if ($document->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $document->update($request->validate($this->rules()));

        return response()->json(['success' => true, 'data' => $document]);
    }

    public function destroy(Request $request, TravelDocument $document): JsonResponse
    {
        if ($document->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $document->delete();

        return response()->json(['success' => true]);
    }
}
