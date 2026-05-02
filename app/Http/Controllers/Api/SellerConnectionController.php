<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Connection;
use App\Services\Partnerships\ConnectionVisibilityResolver;
use App\Services\Partnerships\PartnerConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class SellerConnectionController extends Controller
{
    public function __construct(
        private PartnerConnectionService $service,
        private ConnectionVisibilityResolver $resolver,
    ) {}

    /**
     * GET /api/seller/connections
     * Lists all connections where the authenticated user's company is on either side.
     * Optional ?role=incoming|outgoing|all (default: all), ?status=...
     */
    public function index(Request $request): JsonResponse
    {
        $companyIds = $this->userCompanyIds($request);
        if ($companyIds === []) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $query = Connection::query()
            ->with(['sellerA:id,name', 'sellerB:id,name'])
            ->where(function ($q) use ($companyIds): void {
                $q->whereIn('seller_a_company_id', $companyIds)
                    ->orWhereIn('seller_b_company_id', $companyIds);
            });

        $role = $request->query('role');
        if ($role === 'incoming') {
            // I am the receiver of the proposal (i.e., the proposer is on the other side)
            $query->whereIn('seller_b_company_id', $companyIds)
                ->orWhereIn('seller_a_company_id', $companyIds);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $connections = $query->orderByDesc('proposed_at')->get();

        return response()->json(['success' => true, 'data' => $connections]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        $connection->load(['sellerA:id,name', 'sellerB:id,name', 'partnerAgreement', 'commissionOverrideRule']);

        return response()->json(['success' => true, 'data' => $connection]);
    }

    /**
     * POST /api/seller/connections
     * Propose a new connection. Authenticated user's company is the proposer (seller A).
     * Body: { counterparty_company_id, type, direction, share_scope, territorial_scope,
     *         display_options, effective_from, effective_to,
     *         partner_agreement_id?, commission_override_rule_id? }
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'counterparty_company_id' => ['required', 'integer'],
            'type' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'share_scope' => ['nullable', 'array'],
            'territorial_scope' => ['nullable', 'array'],
            'display_options' => ['nullable', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'partner_agreement_id' => ['nullable', 'string'],
            'commission_override_rule_id' => ['nullable', 'string'],
        ]);

        $myCompany = $this->primaryCompany($request);
        if ($myCompany === null) {
            return response()->json(['success' => false, 'message' => 'You are not associated with any company'], 403);
        }

        $counterparty = Company::query()->find((int) $validated['counterparty_company_id']);
        if ($counterparty === null) {
            return response()->json(['success' => false, 'message' => 'Counterparty company not found'], 404);
        }

        try {
            $connection = $this->service->propose($myCompany, $counterparty, $request->user(), $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, 'data' => $connection], 201);
    }

    public function accept(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        if (! $this->isCounterpartyOfProposer($request, $connection)) {
            return response()->json(['success' => false, 'message' => 'Only the counterparty can accept this proposal'], 403);
        }

        try {
            $accepted = $this->service->accept($connection, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $accepted]);
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        if (! $this->isCounterpartyOfProposer($request, $connection)) {
            return response()->json(['success' => false, 'message' => 'Only the counterparty can reject this proposal'], 403);
        }

        $reason = (string) $request->input('reason', '');

        try {
            $rejected = $this->service->reject($connection, $request->user(), $reason !== '' ? $reason : null);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $rejected]);
    }

    public function counter(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        if (! $this->isCounterpartyOfProposer($request, $connection)) {
            return response()->json(['success' => false, 'message' => 'Only the counterparty can counter this proposal'], 403);
        }

        $payload = $request->validate([
            'type' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'share_scope' => ['nullable', 'array'],
            'territorial_scope' => ['nullable', 'array'],
            'display_options' => ['nullable', 'array'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'partner_agreement_id' => ['nullable', 'string'],
            'commission_override_rule_id' => ['nullable', 'string'],
        ]);

        try {
            $countered = $this->service->counter($connection, $request->user(), $payload);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $countered]);
    }

    public function pause(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        try {
            $paused = $this->service->pause($connection, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $paused]);
    }

    public function resume(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        try {
            $resumed = $this->service->resume($connection, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $resumed]);
    }

    public function terminate(Request $request, string $id): JsonResponse
    {
        $connection = $this->resolveOwnedConnection($request, $id);
        if ($connection === null) {
            return response()->json(['success' => false, 'message' => 'Connection not found'], 404);
        }

        $reason = (string) $request->input('reason', '');
        if (trim($reason) === '') {
            return response()->json(['success' => false, 'message' => 'Termination reason is required'], 422);
        }

        try {
            $terminated = $this->service->terminate($connection, $request->user(), $reason);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $terminated]);
    }

    /**
     * GET /api/seller/visible-suppliers
     * Returns supplier company IDs visible to the authenticated user's company through active connections.
     */
    public function visibleSuppliers(Request $request): JsonResponse
    {
        $myCompany = $this->primaryCompany($request);
        if ($myCompany === null) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $supplierIds = $this->resolver->visibleSupplierIds($myCompany);
        $suppliers = Company::query()
            ->whereIn('id', $supplierIds)
            ->select(['id', 'name', 'type', 'status'])
            ->get();

        return response()->json(['success' => true, 'data' => $suppliers]);
    }

    /**
     * @return array<int, int>
     */
    private function userCompanyIds(Request $request): array
    {
        return $request->user()
            ->companies()
            ->pluck('companies.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function primaryCompany(Request $request): ?Company
    {
        $id = $this->userCompanyIds($request)[0] ?? null;

        return $id !== null ? Company::query()->find($id) : null;
    }

    private function resolveOwnedConnection(Request $request, string $id): ?Connection
    {
        $companyIds = $this->userCompanyIds($request);
        if ($companyIds === []) {
            return null;
        }

        return Connection::query()
            ->where(function ($q) use ($companyIds): void {
                $q->whereIn('seller_a_company_id', $companyIds)
                    ->orWhereIn('seller_b_company_id', $companyIds);
            })
            ->find($id);
    }

    private function isCounterpartyOfProposer(Request $request, Connection $connection): bool
    {
        $companyIds = $this->userCompanyIds($request);
        $proposerCompanyId = $connection->proposed_by_user_id === $request->user()->id
            ? null
            : $this->companyOfProposer($connection);

        // The actor's company must be the OTHER side from the original proposer's company
        $actorSideIsA = in_array((int) $connection->seller_a_company_id, $companyIds, true);
        $actorSideIsB = in_array((int) $connection->seller_b_company_id, $companyIds, true);

        if (! $actorSideIsA && ! $actorSideIsB) {
            return false;
        }

        // Determine which side made the most recent proposal
        $proposerSideIsA = $this->isProposerOnSideA($connection);

        return $proposerSideIsA ? $actorSideIsB : $actorSideIsA;
    }

    private function isProposerOnSideA(Connection $connection): bool
    {
        $proposer = $connection->proposedBy;
        if ($proposer === null) {
            return true; // fall-through; act as if A is the proposer
        }

        $proposerCompanyIds = $proposer->companies()->pluck('companies.id')->map(fn ($id) => (int) $id)->all();

        return in_array((int) $connection->seller_a_company_id, $proposerCompanyIds, true);
    }

    private function companyOfProposer(Connection $connection): ?int
    {
        $proposer = $connection->proposedBy;
        if ($proposer === null) {
            return null;
        }

        return (int) $proposer->companies()->value('companies.id');
    }
}
