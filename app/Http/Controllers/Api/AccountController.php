<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\UserResource;
use App\Models\SavedItem;
use App\Services\UserAccount\UserAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => UserResource::make($request->user())->toArray($request),
        ]);
    }

    public function updateProfile(Request $request, UserAccountService $service): JsonResponse
    {
        $user = $service->updateProfile($request->user(), $request->all());

        return response()->json([
            'success' => true,
            'data' => UserResource::make($user)->toArray($request),
        ]);
    }

    public function tripHistory(Request $request, UserAccountService $service): JsonResponse
    {
        $perPage = max(1, min((int) $request->query('per_page', 15), 100));
        $page = max(1, (int) $request->query('page', 1));
        $result = $service->getTripHistory($request->user(), $perPage, $page);

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function savedItems(Request $request, UserAccountService $service): JsonResponse
    {
        $moduleType = $request->query('module_type') ?: null;
        $items = $service->getSavedItems($request->user(), is_string($moduleType) ? $moduleType : null);

        $data = $items->map(fn ($row) => $this->savedItemPayload($row))->values()->all();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function saveItem(Request $request, UserAccountService $service): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
            'module_type' => ['required', 'string', Rule::in(SavedItem::MODULE_TYPES)],
        ]);

        $row = $service->saveItem($request->user(), (int) $validated['offer_id'], $validated['module_type']);

        return response()->json([
            'success' => true,
            'data' => $this->savedItemPayload($row),
        ], 201);
    }

    public function removeSavedItem(Request $request, int $item, UserAccountService $service): JsonResponse
    {
        $service->removeSavedItem($request->user(), $item);

        return response()->json([
            'success' => true,
            'data' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function savedItemPayload(SavedItem $row): array
    {
        $offer = $row->offer;

        return [
            'id' => $row->id,
            'offer_id' => $row->offer_id,
            'module_type' => $row->module_type,
            'status' => $row->status,
            'saved_at' => $row->created_at?->toIso8601String(),
            'offer' => $offer === null ? null : [
                'id' => $offer->id,
                'title' => $offer->title,
                'type' => $offer->type,
                'price' => $offer->price !== null ? (float) $offer->price : null,
                'currency' => $offer->currency,
                'status' => $offer->status,
            ],
        ];
    }
}
