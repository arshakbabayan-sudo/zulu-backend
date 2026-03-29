<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use App\Services\Admin\AdminAccessService;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class ReviewController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function getReviews(Request $request, ReviewService $service): JsonResponse
    {
        $validated = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(Review::TARGET_ENTITY_TYPES)],
            'entity_id' => ['required', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $paginator = $service->listPublishedForEntity(
            $validated['entity_type'],
            (int) $validated['entity_id'],
            $perPage
        );

        return $this->reviewPaginatorResponse($request, $paginator);
    }

    public function createReview(Request $request, ReviewService $service): JsonResponse
    {
        $review = $service->createReview($request->user(), $request->all());

        return response()->json([
            'success' => true,
            'message' => 'Review submitted.',
            'data' => $this->reviewToArray($review->load('user')),
        ], 201);
    }

    public function myReviews(Request $request, ReviewService $service): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $perPage = max(1, min($perPage, 100));

        $paginator = $service->listForUser($request->user(), $perPage);

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn (Review $r) => $this->reviewToArray($r))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    public function moderateReview(Request $request, Review $review, ReviewService $service): JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isSuperAdmin($user)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['published', 'hidden', 'rejected'])],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $service->moderateReview(
            $review,
            $user,
            $validated['status'],
            $validated['notes'] ?? null
        );

        $updated->load('user');

        $data = [
            'id' => $updated->id,
            'rating' => $updated->rating,
            'review_text' => $updated->review_text,
            'status' => $updated->status,
            'target_entity_type' => $updated->target_entity_type,
            'target_entity_id' => $updated->target_entity_id,
            'moderation_notes' => $updated->moderation_notes,
            'created_at' => $updated->created_at?->toIso8601String(),
        ];
        if ($updated->user !== null) {
            $data['user'] = [
                'id' => $updated->user->id,
                'name' => $updated->user->name,
            ];
        }

        return response()->json([
            'success' => true,
            'message' => 'Review updated.',
            'data' => $data,
        ]);
    }

    private function reviewPaginatorResponse(Request $request, LengthAwarePaginator $paginator): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn (Review $r) => $this->reviewToArray($r))->values()->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewToArray(Review $review): array
    {
        $row = [
            'id' => $review->id,
            'rating' => $review->rating,
            'review_text' => $review->review_text,
            'status' => $review->status,
            'created_at' => $review->created_at?->toIso8601String(),
        ];

        if ($review->relationLoaded('user') && $review->user !== null) {
            $row['user'] = [
                'id' => $review->user->id,
                'name' => $review->user->name,
            ];
        }

        return $row;
    }
}
