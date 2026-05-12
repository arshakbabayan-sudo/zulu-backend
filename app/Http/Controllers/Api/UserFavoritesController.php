<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserFavoritesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $query = UserFavorite::query()->where('user_id', $user->id)->orderByDesc('created_at');
        if ($type = $request->query('item_type')) {
            $query->where('item_type', $type);
        }

        $items = $query->get(['id', 'item_type', 'item_id', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => $items,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        $data = $request->validate([
            'item_type' => ['required', 'string', Rule::in(UserFavorite::ITEM_TYPES)],
            'item_id' => ['required', 'integer', 'min:1'],
        ]);

        $fav = UserFavorite::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'item_type' => $data['item_type'],
                'item_id' => $data['item_id'],
            ]
        );

        return response()->json([
            'success' => true,
            'data' => $fav->only(['id', 'item_type', 'item_id', 'created_at']),
        ], $fav->wasRecentlyCreated ? 201 : 200);
    }

    public function destroy(Request $request, string $itemType, int $itemId): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated.'], 401);
        }

        if (! in_array($itemType, UserFavorite::ITEM_TYPES, true)) {
            return response()->json(['success' => false, 'message' => 'Invalid item_type'], 422);
        }

        $deleted = UserFavorite::query()
            ->where('user_id', $user->id)
            ->where('item_type', $itemType)
            ->where('item_id', $itemId)
            ->delete();

        return response()->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
        ]);
    }
}
