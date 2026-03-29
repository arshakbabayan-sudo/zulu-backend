<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookingResource;
use App\Http\Resources\Api\InvoiceResource;
use App\Http\Resources\Api\PaymentResource;
use App\Models\Booking;
use App\Models\Offer;
use App\Services\Bookings\PassengerService;
use App\Services\Marketplace\MarketplaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MarketplaceController extends Controller
{
    public function store(Request $request, MarketplaceService $marketplaceService): JsonResponse
    {
        $validated = $request->validate([
            'offer_id' => ['required', 'integer', 'exists:offers,id'],
        ]);

        $offer = Offer::query()->findOrFail((int) $validated['offer_id']);
        if ($offer->status !== Offer::STATUS_PUBLISHED) {
            return response()->json([
                'success' => false,
                'message' => 'Offer not available',
            ], 422);
        }

        $booking = $marketplaceService->createBooking($request->user(), $offer);

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($booking->load('items'))->toArray($request),
        ]);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $booking->load(['items', 'invoices.payments']);
        $invoice = $booking->invoices->first();
        $payment = $invoice?->payments->first();

        return response()->json([
            'success' => true,
            'data' => [
                'booking' => BookingResource::make($booking)->toArray($request),
                'invoice' => $invoice ? InvoiceResource::make($invoice)->toArray($request) : null,
                'payment' => $payment ? PaymentResource::make($payment)->toArray($request) : null,
            ],
        ]);
    }

    public function checkout(Request $request, Booking $booking, MarketplaceService $marketplaceService): JsonResponse
    {
        if ((int) $booking->user_id !== (int) $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'passengers' => ['nullable', 'array'],
            'passengers.*.first_name' => ['required_with:passengers', 'string', 'max:100'],
            'passengers.*.last_name' => ['required_with:passengers', 'string', 'max:100'],
            'passengers.*.passenger_type' => ['required_with:passengers', 'string', 'in:adult,child,infant'],
            'passengers.*.passport_number' => ['nullable', 'string', 'max:50'],
            'passengers.*.passport_expiry' => ['nullable', 'date'],
            'passengers.*.nationality' => ['nullable', 'string', 'size:2'],
            'passengers.*.date_of_birth' => ['nullable', 'date'],
            'passengers.*.gender' => ['nullable', 'string', 'in:male,female,other'],
            'passengers.*.booking_item_id' => ['nullable', 'integer'],
            'passengers.*.seat_number' => ['nullable', 'string', 'max:10'],
            'passengers.*.special_requests' => ['nullable', 'string', 'max:500'],
            'passengers.*.email' => ['nullable', 'string', 'max:255'],
            'passengers.*.phone' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $marketplaceService->checkoutPaidBooking($booking);

        $passengersPayload = $validated['passengers'] ?? [];
        if ($passengersPayload !== []) {
            try {
                app(PassengerService::class)->createForBooking($result['booking'], $passengersPayload);
            } catch (\Throwable $e) {
                Log::warning('Failed to save passengers after marketplace checkout', [
                    'booking_id' => $result['booking']->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $result['booking']->load(['items', 'passengers']);

        return response()->json([
            'success' => true,
            'data' => [
                'booking' => BookingResource::make($result['booking'])->toArray($request),
                'invoice' => InvoiceResource::make($result['invoice'])->toArray($request),
                'payment' => PaymentResource::make($result['payment'])->toArray($request),
            ],
        ]);
    }
}
