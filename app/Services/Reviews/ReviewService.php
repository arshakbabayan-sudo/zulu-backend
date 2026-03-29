<?php

namespace App\Services\Reviews;

use App\Models\Booking;
use App\Models\PackageOrder;
use App\Models\Review;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createReview(User $user, array $data): Review
    {
        $validated = Validator::make($data, [
            'target_entity_type' => ['required', 'string', 'in:'.implode(',', Review::TARGET_ENTITY_TYPES)],
            'target_entity_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'min:1', 'max:10'],
            'review_text' => ['nullable', 'string', 'max:2000'],
            'package_order_id' => ['nullable', 'integer', 'exists:package_orders,id'],
            'booking_id' => ['nullable', 'integer', 'exists:bookings,id'],
        ])->validate();

        if (! empty($validated['package_order_id'])) {
            $order = PackageOrder::query()->find((int) $validated['package_order_id']);
            if ($order === null || (int) $order->user_id !== (int) $user->id || $order->status !== 'completed') {
                throw ValidationException::withMessages([
                    'package_order_id' => ['Invalid package order for review.'],
                ]);
            }
        }

        if (! empty($validated['booking_id'])) {
            $booking = Booking::query()->find((int) $validated['booking_id']);
            if ($booking === null || (int) $booking->user_id !== (int) $user->id || $booking->status !== Booking::STATUS_CONFIRMED) {
                throw ValidationException::withMessages([
                    'booking_id' => ['Invalid booking for review.'],
                ]);
            }
        }

        $exists = Review::query()
            ->where('user_id', $user->id)
            ->where('target_entity_type', $validated['target_entity_type'])
            ->where('target_entity_id', $validated['target_entity_id'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'target_entity_id' => ['You have already reviewed this item.'],
            ]);
        }

        return Review::query()->create([
            'user_id' => $user->id,
            'package_order_id' => $validated['package_order_id'] ?? null,
            'booking_id' => $validated['booking_id'] ?? null,
            'target_entity_type' => $validated['target_entity_type'],
            'target_entity_id' => (int) $validated['target_entity_id'],
            'rating' => (int) $validated['rating'],
            'review_text' => $validated['review_text'] ?? null,
            'status' => 'pending',
        ]);
    }

    public function moderateReview(Review $review, User $moderator, string $newStatus, ?string $notes = null): Review
    {
        if (! in_array($newStatus, ['published', 'hidden', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Invalid moderation status.'],
            ]);
        }

        $review->status = $newStatus;
        $review->moderation_notes = $notes;
        $review->save();

        return $review->fresh();
    }

    public function listPublishedForEntity(string $entityType, int $entityId, int $perPage = 10): LengthAwarePaginator
    {
        return Review::query()
            ->where('target_entity_type', $entityType)
            ->where('target_entity_id', $entityId)
            ->where('status', 'published')
            ->with('user')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function listForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return Review::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * @param  array{status?:string,entity_type?:string,user_id?:int}  $filters
     */
    public function listAllForAdmin(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        $query = Review::query()
            ->with('user')
            ->orderByDesc('id');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['entity_type'])) {
            $query->where('target_entity_type', $filters['entity_type']);
        }

        if (array_key_exists('user_id', $filters) && $filters['user_id'] !== null) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        return $query->paginate($perPage);
    }
}
