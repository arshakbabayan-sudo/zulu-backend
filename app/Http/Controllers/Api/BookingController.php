<?php

namespace App\Http\Controllers\Api;

use App\Events\BookingConfirmed;
use App\Http\Controllers\Api\Concerns\PaginatesCommerceResources;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\BookingResource;
use App\Models\Booking;
use App\Models\Offer;
use App\Services\Admin\AdminAccessService;
use App\Services\Bookings\BookingService;
use App\Services\Bookings\PassengerService;
use App\Services\Pdf\VoucherPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class BookingController extends Controller
{
    use PaginatesCommerceResources;

    public function __construct(
        private AdminAccessService $adminAccessService
    ) {}

    public function index(Request $request, BookingService $bookingService): JsonResponse
    {
        $companyIds = $this->adminAccessService->companyIdsForCommerceList($request->user(), 'bookings.view');

        if (! $request->filled('page')) {
            $bookings = $bookingService->listForCompanies($companyIds);

            return response()->json([
                'success' => true,
                'data' => BookingResource::collection($bookings)->resolve(),
            ]);
        }

        $paginator = $bookingService->paginateForCompanies($companyIds, $this->commerceListPerPage($request));

        return $this->paginatedCommerceResourceResponse($request, $paginator, BookingResource::class);
    }

    public function show(Request $request, Booking $booking): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $booking->company_id, 'bookings.view')) {
            return $response;
        }

        $booking->loadMissing('items');

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($booking)->toArray($request),
        ]);
    }

    public function store(Request $request, BookingService $bookingService): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.offer_id' => ['required', 'integer', 'exists:offers,id'],
            'items.*.price' => ['required', 'numeric'],
        ]);

        $companyId = (int) $validated['company_id'];
        if ($response = $this->ensureCommerceAccess($request, $companyId, 'bookings.create')) {
            return $response;
        }

        foreach ($validated['items'] as $item) {
            $offerId = (int) $item['offer_id'];
            $offer = Offer::query()->with('flight')->find($offerId);
            if (! $offer) {
                continue;
            }

            if ($offer->type === 'flight' && $offer->flight) {
                if ((int) ($offer->flight->seat_capacity_available ?? 0) <= 0) {
                    throw ValidationException::withMessages([
                        'items' => ['No seats available for flight: '.($offer->flight->flight_code_internal ?? 'unknown')],
                    ]);
                }
            }
        }

        $bookingData = [
            'user_id' => (int) $validated['user_id'],
            'company_id' => $companyId,
        ];

        $itemsData = [];
        foreach ($validated['items'] as $item) {
            $itemsData[] = [
                'offer_id' => (int) $item['offer_id'],
                'price' => (float) $item['price'],
            ];
        }

        $booking = $bookingService->create($bookingData, $itemsData);

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($booking)->toArray($request),
        ]);
    }

    public function confirm(Request $request, BookingService $bookingService, Booking $booking): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $booking->company_id, 'bookings.confirm')) {
            return $response;
        }

        $confirmed = $bookingService->confirm($booking);

        try {
            $confirmed->loadMissing('user');
            if ($confirmed->user) {
                event(new BookingConfirmed($confirmed, $confirmed->user));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to dispatch booking confirmed event', ['error' => $e->getMessage()]);
        }

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($confirmed)->toArray($request),
        ]);
    }

    public function cancel(Request $request, BookingService $bookingService, Booking $booking): JsonResponse
    {
        if ($response = $this->ensureCommerceAccess($request, (int) $booking->company_id, 'bookings.cancel')) {
            return $response;
        }

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($bookingService->cancel($booking))->toArray($request),
        ]);
    }

    public function addPassengers(Request $request, PassengerService $passengerService, Booking $booking): JsonResponse
    {
        if (! $this->canAddPassengers($request, $booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $validated = $request->validate([
            'passengers' => ['required', 'array', 'min:1'],
            'passengers.*.first_name' => ['required', 'string', 'max:100'],
            'passengers.*.last_name' => ['required', 'string', 'max:100'],
            'passengers.*.passenger_type' => ['required', 'string', 'in:adult,child,infant'],
        ]);

        $passengerService->createForBooking($booking, $validated['passengers']);

        $booking->load(['items', 'passengers']);

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($booking)->toArray($request),
        ]);
    }

    public function getPassengers(Request $request, Booking $booking): JsonResponse
    {
        if (! $this->canViewPassengers($request, $booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $booking->load('passengers');

        return response()->json([
            'success' => true,
            'data' => BookingResource::make($booking)->toArray($request)['passengers'] ?? [],
        ]);
    }

    public function downloadVoucher(Request $request, Booking $booking, VoucherPdfService $voucherService): Response
    {
        if (! $this->canDownloadVoucher($request, $booking)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        try {
            return $voucherService->generate($booking);
        } catch (\Throwable $e) {
            Log::warning('Voucher PDF generation failed', ['error' => $e->getMessage(), 'booking_id' => $booking->id]);

            return response()->json([
                'success' => false,
                'message' => 'PDF generation failed',
            ], 500);
        }
    }

    private function canDownloadVoucher(Request $request, Booking $booking): bool
    {
        if ((int) $booking->user_id === (int) $request->user()->id) {
            return true;
        }

        return $this->adminAccessService->allowsCommerceOperatorAccess(
            $request->user(),
            (int) $booking->company_id,
            'bookings.view'
        );
    }

    private function canAddPassengers(Request $request, Booking $booking): bool
    {
        if ((int) $booking->user_id === (int) $request->user()->id) {
            return true;
        }

        return $this->adminAccessService->allowsCommerceOperatorAccess(
            $request->user(),
            (int) $booking->company_id,
            'bookings.create'
        );
    }

    private function canViewPassengers(Request $request, Booking $booking): bool
    {
        if ((int) $booking->user_id === (int) $request->user()->id) {
            return true;
        }

        return $this->adminAccessService->allowsCommerceOperatorAccess(
            $request->user(),
            (int) $booking->company_id,
            'bookings.view'
        );
    }

    private function ensureCommerceAccess(Request $request, int $companyId, string $permission): ?JsonResponse
    {
        if (! $this->adminAccessService->allowsCommerceOperatorAccess($request->user(), $companyId, $permission)) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return null;
    }
}
