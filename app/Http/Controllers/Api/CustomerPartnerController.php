<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerPartner;
use App\Models\Offer;
use App\Services\Selling\CustomerSellerOptionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * B2C customer's "My partners" — the ordered list of preferred sellers (agents
 * or operators) per country. rank 1 = the default whose price the customer sees
 * first. See docs/blueprints/seller-attribution-architecture-2026-06-04.md.
 *
 * Per-product price resolution (which seller's price to show, the picker) is a
 * separate step; this controller only manages the list itself.
 */
class CustomerPartnerController extends Controller
{
    /** GET /api/customer/partners[?country=Armenia] */
    public function index(Request $request): JsonResponse
    {
        $country = $request->query('country');

        $partners = CustomerPartner::query()
            ->where('customer_user_id', $request->user()->id)
            ->when(is_string($country) && $country !== '', fn ($q) => $q->where('country', $country))
            ->with('partner:id,name,type,country,city')
            ->orderBy('country')
            ->orderBy('rank')
            ->get()
            ->map(fn (CustomerPartner $p): array => [
                'id' => $p->id,
                'country' => $p->country,
                'rank' => $p->rank,
                'partner' => [
                    'id' => $p->partner?->id,
                    'name' => $p->partner?->name,
                    'type' => $p->partner?->type,
                ],
            ]);

        return response()->json(['success' => true, 'data' => $partners]);
    }

    /**
     * GET /api/customer/partner-search?country=Armenia[&q=ara] — the Add-partner
     * picker: active seller companies (agencies/operators) in a country the
     * customer can add to their list. `already_added` flags companies that are
     * already in the caller's list for that country so the UI can render Added✓.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string'],
            'country' => ['required', 'string', 'max:120'],
        ]);

        $q = trim((string) ($validated['q'] ?? ''));
        $country = $validated['country'];

        // A single character matches too broadly — wait until 2+ chars are typed.
        if ($q !== '' && mb_strlen($q) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $alreadyAddedIds = CustomerPartner::query()
            ->where('customer_user_id', $request->user()->id)
            ->where('country', $country)
            ->pluck('partner_company_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // LOWER() LIKE instead of ILIKE so the query runs on both Postgres
        // (prod) and SQLite (test suite).
        $like = '%'.mb_strtolower(str_replace(['%', '_'], ['\\%', '\\_'], $q)).'%';

        $companies = Company::query()
            ->where('is_seller', true)
            ->where('governance_status', 'active')
            ->whereNull('archived_at')
            ->where('country', $country)
            ->whereIn('type', ['agency', 'operator'])
            ->when($q !== '', fn ($qq) => $qq->whereRaw('LOWER(name) LIKE ?', [$like]))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'type', 'country', 'logo'])
            ->map(fn (Company $c): array => [
                'id' => $c->id,
                'name' => $c->name,
                'type' => $c->type,
                'country' => $c->country,
                'logo' => $c->logo,
                'already_added' => in_array($c->id, $alreadyAddedIds, true),
            ]);

        return response()->json(['success' => true, 'data' => $companies]);
    }

    /** POST /api/customer/partners {partner_company_id, country} — appends at the end. */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'partner_company_id' => ['required', 'integer', 'exists:companies,id'],
            'country' => ['required', 'string', 'max:120'],
        ]);

        $userId = $request->user()->id;
        $companyId = (int) $validated['partner_company_id'];
        $country = $validated['country'];

        return DB::transaction(function () use ($userId, $companyId, $country): JsonResponse {
            $existing = CustomerPartner::query()
                ->where('customer_user_id', $userId)
                ->where('country', $country)
                ->lockForUpdate()
                ->get();

            if ($existing->firstWhere('partner_company_id', $companyId) !== null) {
                throw ValidationException::withMessages([
                    'partner_company_id' => ['This partner is already in your list for this country.'],
                ]);
            }

            if ($existing->count() >= CustomerPartner::MAX_PER_COUNTRY) {
                throw ValidationException::withMessages([
                    'country' => ['You can keep at most '.CustomerPartner::MAX_PER_COUNTRY.' partners per country.'],
                ]);
            }

            $partner = CustomerPartner::query()->create([
                'customer_user_id' => $userId,
                'partner_company_id' => $companyId,
                'country' => $country,
                'rank' => ((int) $existing->max('rank')) + 1,
            ]);

            return response()->json([
                'success' => true,
                'data' => $partner->load('partner:id,name,type'),
            ], 201);
        });
    }

    /** DELETE /api/customer/partners/{partner} — removes + re-sequences ranks. */
    public function destroy(Request $request, CustomerPartner $partner): JsonResponse
    {
        if ($partner->customer_user_id !== $request->user()->id) {
            return response()->json(['success' => false, 'message' => 'Not found.'], 404);
        }

        $userId = $partner->customer_user_id;
        $country = $partner->country;

        DB::transaction(function () use ($partner, $userId, $country): void {
            $partner->delete();

            $remaining = CustomerPartner::query()
                ->where('customer_user_id', $userId)
                ->where('country', $country)
                ->orderBy('rank')
                ->lockForUpdate()
                ->get();

            foreach ($remaining->values() as $i => $row) {
                $newRank = $i + 1;
                if ($row->rank !== $newRank) {
                    $row->update(['rank' => $newRank]);
                }
            }
        });

        return response()->json(['success' => true]);
    }

    /** PUT /api/customer/partners/reorder {country, ordered_ids:[...]} — sets ranks by order. */
    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'country' => ['required', 'string', 'max:120'],
            'ordered_ids' => ['required', 'array', 'min:1'],
            'ordered_ids.*' => ['integer'],
        ]);

        $userId = $request->user()->id;
        $country = $validated['country'];
        $orderedIds = array_values(array_unique(array_map('intval', $validated['ordered_ids'])));

        return DB::transaction(function () use ($userId, $country, $orderedIds): JsonResponse {
            $rows = CustomerPartner::query()
                ->where('customer_user_id', $userId)
                ->where('country', $country)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // The supplied ids must be EXACTLY this customer's partners for the
            // country (no missing / extra), so ranks stay clean 1..N.
            if ($rows->count() !== count($orderedIds) || $rows->keys()->map(fn ($k) => (int) $k)->diff($orderedIds)->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'ordered_ids' => ['The list must contain exactly your partner ids for this country.'],
                ]);
            }

            foreach ($orderedIds as $i => $id) {
                $rows[$id]->update(['rank' => $i + 1]);
            }

            return response()->json(['success' => true]);
        });
    }

    /** GET /api/offers/{offer}/seller-options[?quantity=1] — the sellers (+ prices) this customer can buy through. */
    public function offerOptions(Request $request, Offer $offer, CustomerSellerOptionsService $service): JsonResponse
    {
        $quantity = max(1, (int) $request->query('quantity', 1));

        return response()->json([
            'success' => true,
            'data' => $service->optionsFor($offer, $request->user(), $quantity),
        ]);
    }
}
