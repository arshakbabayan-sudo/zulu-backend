<?php

namespace App\Services\UserAccount;

use App\Models\Booking;
use App\Models\Offer;
use App\Models\PackageOrder;
use App\Models\SavedItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class UserAccountService
{
    public function getProfile(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'preferred_language' => $user->preferred_language,
            'avatar' => $user->avatar,
            'birth_date' => $user->birth_date?->format('Y-m-d'),
            'nationality' => $user->nationality,
            'status' => $user->status,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }

    public function updateProfile(User $user, array $data): User
    {
        $validated = Validator::make($data, [
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'preferred_language' => ['sometimes', 'nullable', 'string', 'max:8'],
            'avatar' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'nationality' => ['sometimes', 'nullable', 'string', 'max:64'],
        ])->validate();

        $user->fill($validated);
        $user->save();

        return $user->fresh();
    }

    /**
     * @return array{items: list<array<string, mixed>>, meta: array{current_page: int, last_page: int, total: int, per_page: int}}
     */
    public function getTripHistory(User $user, int $perPage = 15, int $page = 1): array
    {
        $perPage = max(1, $perPage);

        $packageOrders = PackageOrder::query()
            ->where('user_id', $user->id)
            ->with(['package'])
            ->orderByDesc('id')
            ->get();

        $bookings = Booking::query()
            ->where('user_id', $user->id)
            ->with(['items.offer'])
            ->orderByDesc('id')
            ->get();

        $rows = collect();

        foreach ($packageOrders as $order) {
            $package = $order->package;
            $rows->push([
                'type' => 'package',
                'id' => $order->id,
                'order_number' => $order->order_number,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'final_total_snapshot' => (float) $order->final_total_snapshot,
                'currency' => $order->currency,
                'destination' => $package?->destination_city ?? $package?->destination_country,
                'duration_days' => $package?->duration_days,
                'created_at' => $order->created_at?->toIso8601String(),
            ]);
        }

        foreach ($bookings as $booking) {
            $rows->push([
                'type' => 'booking',
                'id' => $booking->id,
                'status' => $booking->status,
                'total_price' => (float) $booking->total_price,
                'created_at' => $booking->created_at?->toIso8601String(),
            ]);
        }

        $sorted = $rows->sortByDesc('id')->values();
        $total = $sorted->count();
        $lastPage = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $currentPage = min(max(1, $page), $lastPage);
        $offset = ($currentPage - 1) * $perPage;
        $items = $sorted->slice($offset, $perPage)->values()->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'total' => $total,
                'per_page' => $perPage,
            ],
        ];
    }

    public function getSavedItems(User $user, ?string $moduleType = null): Collection
    {
        $query = SavedItem::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->with(['offer'])
            ->orderByDesc('id');

        if ($moduleType !== null && $moduleType !== '') {
            $query->where('module_type', $moduleType);
        }

        return $query->get();
    }

    public function saveItem(User $user, int $offerId, string $moduleType): SavedItem
    {
        if (! in_array($moduleType, SavedItem::MODULE_TYPES, true)) {
            throw ValidationException::withMessages([
                'module_type' => ['Invalid module type.'],
            ]);
        }

        $offer = Offer::query()->whereKey($offerId)->first();
        if ($offer === null) {
            throw ValidationException::withMessages([
                'offer_id' => ['The selected offer is invalid.'],
            ]);
        }

        if ($offer->type !== $moduleType) {
            throw ValidationException::withMessages([
                'module_type' => ['Offer type does not match module type.'],
            ]);
        }

        $existing = SavedItem::query()
            ->where('user_id', $user->id)
            ->where('offer_id', $offerId)
            ->first();

        if ($existing !== null) {
            if ($existing->status === 'active') {
                return $existing->load('offer');
            }

            $existing->status = 'active';
            $existing->module_type = $moduleType;
            $existing->save();

            return $existing->load('offer');
        }

        $created = SavedItem::query()->create([
            'user_id' => $user->id,
            'offer_id' => $offerId,
            'module_type' => $moduleType,
            'status' => 'active',
        ]);

        return $created->load('offer');
    }

    public function removeSavedItem(User $user, int $savedItemId): void
    {
        $item = SavedItem::query()
            ->where('user_id', $user->id)
            ->whereKey($savedItemId)
            ->first();

        if ($item === null) {
            throw ValidationException::withMessages([
                'item' => ['Saved item not found.'],
            ]);
        }

        $item->status = 'removed';
        $item->save();
    }
}
