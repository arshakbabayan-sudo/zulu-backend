<?php

namespace App\Services\Infrastructure;

use App\Models\ServiceHold;
use Illuminate\Database\Eloquent\Model;

class HoldService
{
    public const HOLD_MINUTES = 15;

    /**
     * Create a hold for a user on a holdable model (Flight, Hotel, etc.)
     * Releases any existing expired holds for this model first.
     */
    public function createHold(
        Model $holdable,
        int $userId,
        int $quantity = 1,
        ?int $bookingId = null
    ): ServiceHold {
        // Release expired holds first
        $this->releaseExpired($holdable);

        // Remove any existing active hold by this user on this item
        ServiceHold::query()
            ->where('holdable_type', get_class($holdable))
            ->where('holdable_id', $holdable->id)
            ->where('user_id', $userId)
            ->where('released', false)
            ->update(['released' => true]);

        return ServiceHold::query()->create([
            'holdable_type' => get_class($holdable),
            'holdable_id' => $holdable->id,
            'user_id' => $userId,
            'booking_id' => $bookingId,
            'quantity' => $quantity,
            'expires_at' => now()->addMinutes(self::HOLD_MINUTES),
            'released' => false,
        ]);
    }

    /**
     * Release a specific hold.
     */
    public function releaseHold(ServiceHold $hold): void
    {
        $hold->update(['released' => true]);
    }

    /**
     * Release all expired holds for a holdable model.
     */
    public function releaseExpired(Model $holdable): int
    {
        return ServiceHold::query()
            ->where('holdable_type', get_class($holdable))
            ->where('holdable_id', $holdable->id)
            ->where('released', false)
            ->where('expires_at', '<', now())
            ->update(['released' => true]);
    }

    /**
     * Check if a model is available (no active holds by OTHER users).
     * Returns true if available, false if held by someone else.
     */
    public function isAvailable(Model $holdable, int $forUserId): bool
    {
        return ! ServiceHold::query()
            ->where('holdable_type', get_class($holdable))
            ->where('holdable_id', $holdable->id)
            ->where('user_id', '!=', $forUserId)
            ->where('released', false)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * Get active hold count for a model (all users).
     */
    public function activeHoldCount(Model $holdable): int
    {
        return ServiceHold::query()
            ->where('holdable_type', get_class($holdable))
            ->where('holdable_id', $holdable->id)
            ->where('released', false)
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * Release all holds for a booking (e.g. when booking is confirmed or cancelled).
     */
    public function releaseForBooking(int $bookingId): void
    {
        ServiceHold::query()
            ->where('booking_id', $bookingId)
            ->where('released', false)
            ->update(['released' => true]);
    }
}

