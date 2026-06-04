<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SavedTraveler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B2C customer's saved travelers / companions (account "Travelers" section).
 * Each row belongs to the authenticated customer; reused to pre-fill bookings.
 */
class SavedTravelerController extends Controller
{
    /** @var array<string, array<int, string>> */
    private const RULES = [
        'first_name' => ['required', 'string', 'max:120'],
        'last_name' => ['required', 'string', 'max:120'],
        'date_of_birth' => ['nullable', 'date'],
        'gender' => ['nullable', 'string', 'max:16'],
        'nationality' => ['nullable', 'string', 'max:120'],
        'relationship' => ['nullable', 'string', 'max:32'],
        'is_self' => ['sometimes', 'boolean'],
        'passport_number' => ['nullable', 'string', 'max:64'],
        'passport_country' => ['nullable', 'string', 'max:120'],
        'passport_expiry' => ['nullable', 'date'],
        'email' => ['nullable', 'email', 'max:190'],
        'phone' => ['nullable', 'string', 'max:40'],
    ];

    public function index(Request $request): JsonResponse
    {
        $rows = SavedTraveler::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('is_self')
            ->orderBy('last_name')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(self::RULES);
        $data['user_id'] = $request->user()->id;

        return response()->json(['success' => true, 'data' => SavedTraveler::query()->create($data)], 201);
    }

    public function update(Request $request, SavedTraveler $traveler): JsonResponse
    {
        if ($traveler->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $traveler->update($request->validate(self::RULES));

        return response()->json(['success' => true, 'data' => $traveler]);
    }

    public function destroy(Request $request, SavedTraveler $traveler): JsonResponse
    {
        if ($traveler->user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $traveler->delete();

        return response()->json(['success' => true]);
    }
}
