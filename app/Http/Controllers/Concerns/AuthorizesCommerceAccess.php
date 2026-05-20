<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared commerce-scope authorisation helper.
 *
 * Phase 4 / I1 audit cleanup. Previously this method was copy-pasted into
 * 13 inventory/finance controllers (Car, Excursion, Flight, Hotel, Invoice,
 * Offer, Package, PackageOrder, Payment, Transfer, Visa, Commission,
 * AdminInventory). A single fix to the predicate had to be applied 13 times.
 *
 * Consumers must have an injected `AdminAccessService $adminAccessService`
 * property — every controller using this trait already does.
 *
 *   class HotelController extends Controller {
 *       use AuthorizesCommerceAccess;
 *
 *       public function __construct(private AdminAccessService $adminAccessService) {}
 *
 *       public function show(Request $request, int $id) {
 *           if ($err = $this->ensureCommerceAccess($request, $companyId, 'hotels.view')) {
 *               return $err;
 *           }
 *           // ...
 *       }
 *   }
 */
trait AuthorizesCommerceAccess
{
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
