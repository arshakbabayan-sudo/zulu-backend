<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CrmActivity;
use App\Models\CrmCompanySetting;
use App\Models\CrmDeal;
use App\Models\CrmEmployeeCompensation;
use App\Models\CrmLead;
use App\Models\CrmSegment;
use App\Models\Order;
use App\Models\User;
use App\Models\UserCompany;
use App\Services\Admin\AdminAccessService;
use App\Services\Admin\CompanyAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CRM — deals (sales pipeline) + activities (interaction log).
 *
 * Tenancy: super-admins see every company's records; everyone else is scoped
 * to the companies they belong to (user_company pivot). Super-admin detection
 * is wrapped in a defensive helper so a model-shape mismatch can never 500 the
 * endpoint — worst case it falls back to the (safer) company-scoped view.
 *
 * Phase 3 Layer-B: within those companies, a plain employee sees only the
 * deals/activities they own (owner_user_id stamped on create); the company
 * owner or a holder of crm.view_all sees the whole company.
 *
 * Response envelope matches the rest of platform-admin: {success,data,meta}.
 */
class CrmController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private CompanyAccessService $companyAccessService,
    ) {}

    /**
     * Within-company row scope (Layer B): the user id to filter owner_user_id
     * by, or null for "whole company" (owner / crm.view_all / super-admin).
     * Defensive like scopeCompanyIds — any resolver hiccup falls back to the
     * company-scoped (broader but still tenant-safe) view, never a 500.
     */
    private function rowScopeUserId(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        try {
            if ($this->isSuperAdmin($user)) {
                return null;
            }

            return $this->adminAccessService->employeeRowScopeUserId($user, 'crm.view_all');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Company ids the caller may see, or null for "all" (super-admin). */
    private function scopeCompanyIds(Request $request): ?array
    {
        $user = $request->user();
        if ($user === null) {
            return [0]; // unauthenticated guard — match nothing
        }
        if ($this->isSuperAdmin($user)) {
            return null; // no scope = all companies
        }
        try {
            $ids = $user->companies()->pluck('companies.id')->all();
        } catch (\Throwable $e) {
            $ids = [];
        }

        return $ids ?: [0];
    }

    private function isSuperAdmin($user): bool
    {
        // Delegate to the canonical resolver so CRM scoping matches the rest
        // of the admin panel (recognises the super_admin role + super perms);
        // keep the defensive try/catch so a model hiccup never 500s a CRM read.
        try {
            return $user instanceof User && $this->adminAccessService->isSuperAdmin($user);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function ownerCompanyId(Request $request): ?int
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        try {
            return $user->companies()->value('companies.id');
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ─── Deals ────────────────────────────────────────────────────────────

    public function listDeals(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);
        $perPage = (int) ($request->query('per_page', 50));
        $perPage = max(1, min($perPage, 200));

        $query = CrmDeal::query()->with(['customer:id,name,email', 'owner:id,name']);
        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }
        // Phase 3 Layer-B: plain employee → own deals only.
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null) {
            $query->where('owner_user_id', $rowScopeUserId);
        }
        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }
        if ($ownerId = $request->query('owner_user_id')) {
            $query->where('owner_user_id', (int) $ownerId);
        }
        if ($search = $request->query('search')) {
            $query->where('title', 'like', "%{$search}%");
        }

        $deals = $query->orderByDesc('updated_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => array_map([$this, 'dealArray'], $deals->items()),
            'meta' => [
                'current_page' => $deals->currentPage(),
                'last_page' => $deals->lastPage(),
                'total' => $deals->total(),
                'per_page' => $deals->perPage(),
            ],
        ]);
    }

    public function storeDeal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'value_amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:3'],
            'service_type' => ['nullable', 'string', 'max:50'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', CrmDeal::STAGES)],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:50'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $rawCompanyId = $request->input('company_id', $this->ownerCompanyId($request));
        $companyId = is_numeric($rawCompanyId) ? (int) $rawCompanyId : null;
        if (! $this->canWriteCompanyRows($request, $companyId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $data['company_id'] = $companyId;
        $data['stage'] = $data['stage'] ?? 'new';
        // A row-scoped employee's new deals are their own; owners/managers
        // (and staff) may assign anyone ON the team (same rules as leads).
        if ($this->rowScopeUserId($request) !== null) {
            $data['owner_user_id'] = optional($request->user())->id;
        } else {
            $data['owner_user_id'] = $data['owner_user_id'] ?? optional($request->user())->id;
            if ($data['owner_user_id'] !== null
                && (int) $data['owner_user_id'] !== (int) optional($request->user())->id
                && $this->ownerNotInCompany((int) $data['owner_user_id'], $companyId)) {
                return response()->json(['success' => false, 'message' => 'Owner is not a member of this company'], 422);
            }
        }

        $deal = CrmDeal::create($data);

        return response()->json(['success' => true, 'data' => $this->dealArray($deal->fresh(['customer:id,name,email', 'owner:id,name']))], 201);
    }

    public function updateDeal(Request $request, CrmDeal $deal): JsonResponse
    {
        if ($deny = $this->denyUnlessDealWritable($request, $deal)) {
            return $deny;
        }

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'value_amount' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:3'],
            'service_type' => ['nullable', 'string', 'max:50'],
            'stage' => ['nullable', 'string', 'in:'.implode(',', CrmDeal::STAGES)],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'source' => ['nullable', 'string', 'max:50'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        // Reassigning the owner needs whole-company visibility; a row-scoped
        // employee keeps their own deals. The assignee must be on the team.
        if ($this->rowScopeUserId($request) !== null) {
            unset($data['owner_user_id']);
        }
        if (($data['owner_user_id'] ?? null) !== null
            && (int) $data['owner_user_id'] !== (int) optional($request->user())->id
            && $this->ownerNotInCompany((int) $data['owner_user_id'], $deal->company_id)) {
            return response()->json(['success' => false, 'message' => 'Owner is not a member of this company'], 422);
        }

        $deal->update($data);

        return response()->json(['success' => true, 'data' => $this->dealArray($deal->fresh(['customer:id,name,email', 'owner:id,name']))]);
    }

    public function destroyDeal(Request $request, CrmDeal $deal): JsonResponse
    {
        if ($deny = $this->denyUnlessDealWritable($request, $deal)) {
            return $deny;
        }

        $deal->delete();

        return response()->json(['success' => true]);
    }

    // ─── Activities ─────────────────────────────────────────────────────────

    public function listActivities(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);
        $perPage = (int) ($request->query('per_page', 50));
        $perPage = max(1, min($perPage, 200));

        $query = CrmActivity::query()->with(['owner:id,name']);
        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }
        // Phase 3 Layer-B: plain employee → own activities only.
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null) {
            $query->where('owner_user_id', $rowScopeUserId);
        }
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        // Filter to one subject (e.g. a customer card's Communication tab).
        if (($subjectType = $request->query('subject_type')) && ($subjectId = $request->query('subject_id'))) {
            $query->where('subject_type', $subjectType)->where('subject_id', (int) $subjectId);
        }

        $rows = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => array_map([$this, 'activityArray'], $rows->items()),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    public function storeActivity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', CrmActivity::TYPES)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'subject_type' => ['nullable', 'string', 'in:'.implode(',', CrmActivity::SUBJECT_TYPES)],
            'subject_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'in:'.implode(',', CrmActivity::STATUSES)],
            'due_at' => ['nullable', 'date'],
        ]);

        $data['company_id'] = $request->input('company_id', $this->ownerCompanyId($request));
        $data['owner_user_id'] = optional($request->user())->id;
        $data['status'] = $data['status'] ?? 'open';

        $activity = CrmActivity::create($data);

        return response()->json(['success' => true, 'data' => $this->activityArray($activity->fresh(['owner:id,name']))], 201);
    }

    public function updateActivity(Request $request, CrmActivity $activity): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['sometimes', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:'.implode(',', CrmActivity::STATUSES)],
            'due_at' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
        ]);

        if (($data['status'] ?? null) === 'done' && empty($activity->completed_at) && empty($data['completed_at'])) {
            $data['completed_at'] = now();
        }

        $activity->update($data);

        return response()->json(['success' => true, 'data' => $this->activityArray($activity->fresh(['owner:id,name']))]);
    }

    // ─── Leads (inbound enquiries, pre-deal) ────────────────────────────────

    public function listLeads(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);
        $perPage = max(1, min((int) $request->query('per_page', 50), 200));

        $query = CrmLead::query()->with(['owner:id,name']);
        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }
        // Phase 3 Layer-B: plain employee → own leads only.
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null) {
            $query->where('owner_user_id', $rowScopeUserId);
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($source = $request->query('source')) {
            $query->where('source', $source);
        }
        if ($interest = $request->query('interest')) {
            $query->where('interest', $interest);
        }
        if ($ownerId = $request->query('owner_user_id')) {
            $query->where('owner_user_id', (int) $ownerId);
        }
        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $rows = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => array_map([$this, 'leadArray'], $rows->items()),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'total' => $rows->total(),
                'per_page' => $rows->perPage(),
            ],
        ]);
    }

    /**
     * KPI tiles for CRM → Leads, over the WHOLE scoped set (not one page):
     * new leads last 7 days, qualified, unassigned, and the conversion rate
     * (% of all leads that have been converted into deals).
     */
    public function leadsStats(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);
        $rowScopeUserId = $this->rowScopeUserId($request);

        $base = function () use ($companyIds, $rowScopeUserId) {
            $q = CrmLead::query();
            if ($companyIds !== null) {
                $q->whereIn('company_id', $companyIds);
            }
            if ($rowScopeUserId !== null) {
                $q->where('owner_user_id', $rowScopeUserId);
            }

            return $q;
        };

        $total = $base()->count();
        $converted = $base()->where('status', 'converted')->count();

        return response()->json([
            'success' => true,
            'data' => [
                'new_7d' => $base()->where('created_at', '>=', Carbon::now()->subDays(7))->count(),
                'qualified' => $base()->where('status', 'qualified')->count(),
                'unassigned' => $base()->whereNull('owner_user_id')->count(),
                'conversion_rate' => $total > 0 ? (int) round($converted * 100 / $total) : 0,
            ],
        ]);
    }

    public function storeLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'b2b_company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'in:'.implode(',', CrmLead::SOURCES)],
            'interest' => ['nullable', 'string', 'in:'.implode(',', CrmLead::INTERESTS)],
            'status' => ['nullable', 'string', 'in:'.implode(',', CrmLead::SETTABLE_STATUSES)],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyId = $request->input('company_id', $this->ownerCompanyId($request));
        $companyId = $companyId !== null ? (int) $companyId : null;
        if (! $this->canWriteCompanyRows($request, $companyId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $data['company_id'] = $companyId;
        $data['status'] = $data['status'] ?? 'new';
        // A row-scoped employee's new leads are their own — no assigning to
        // colleagues. Owners/managers (and staff) may assign anyone ON the team.
        if ($this->rowScopeUserId($request) !== null) {
            $data['owner_user_id'] = optional($request->user())->id;
        } else {
            $data['owner_user_id'] = $data['owner_user_id'] ?? optional($request->user())->id;
            if ($data['owner_user_id'] !== null
                && (int) $data['owner_user_id'] !== (int) optional($request->user())->id
                && $this->ownerNotInCompany((int) $data['owner_user_id'], $companyId)) {
                return response()->json(['success' => false, 'message' => 'Owner is not a member of this company'], 422);
            }
        }

        $lead = CrmLead::create($data);

        return response()->json(['success' => true, 'data' => $this->leadArray($lead->fresh(['owner:id,name']))], 201);
    }

    public function updateLead(Request $request, CrmLead $lead): JsonResponse
    {
        if ($deny = $this->denyUnlessLeadWritable($request, $lead)) {
            return $deny;
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'b2b_company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'in:'.implode(',', CrmLead::SOURCES)],
            'interest' => ['nullable', 'string', 'in:'.implode(',', CrmLead::INTERESTS)],
            'status' => ['nullable', 'string', 'in:'.implode(',', CrmLead::SETTABLE_STATUSES)],
            'owner_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'notes' => ['nullable', 'string'],
        ]);

        // Reassigning the owner needs whole-company visibility (owner/manager/
        // crm.view_all); a row-scoped employee keeps their own leads.
        if ($this->rowScopeUserId($request) !== null) {
            unset($data['owner_user_id']);
        }
        // An explicit JSON null would hit the NOT NULL status column — treat
        // it as "no change" (the column has no null meaning to express).
        if (array_key_exists('status', $data) && $data['status'] === null) {
            unset($data['status']);
        }
        // The assignee must actually work at the lead's company — otherwise
        // the lead vanishes from every row-scoped view (Layer-B semantics).
        if (($data['owner_user_id'] ?? null) !== null
            && (int) $data['owner_user_id'] !== (int) optional($request->user())->id
            && $this->ownerNotInCompany((int) $data['owner_user_id'], $lead->company_id)) {
            return response()->json(['success' => false, 'message' => 'Owner is not a member of this company'], 422);
        }
        // A converted lead is frozen except its notes — its history must keep
        // matching the deal it became.
        if ($lead->status === 'converted') {
            $data = array_intersect_key($data, ['notes' => true]);
        }

        $lead->update($data);

        return response()->json(['success' => true, 'data' => $this->leadArray($lead->fresh(['owner:id,name']))]);
    }

    public function destroyLead(Request $request, CrmLead $lead): JsonResponse
    {
        if ($deny = $this->denyUnlessLeadWritable($request, $lead)) {
            return $deny;
        }

        $lead->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Convert a lead into a deal (design contract: POST crm/leads/{id}/convert).
     * Creates a crm_deals row carrying over interest→service_type, source and
     * notes, then freezes the lead as status=converted with a link to the deal.
     */
    public function convertLead(Request $request, CrmLead $lead): JsonResponse
    {
        if ($deny = $this->denyUnlessLeadWritable($request, $lead)) {
            return $deny;
        }
        if ($lead->status === 'converted' || $lead->converted_deal_id !== null) {
            return response()->json(['success' => false, 'message' => 'Lead is already converted'], 422);
        }

        $data = $request->validate([
            'value_amount' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
        ]);

        try {
            $deal = DB::transaction(function () use ($lead, $data, $request): CrmDeal {
                // Re-read under a row lock — the early guard above runs on a
                // stale model, so two simultaneous converts could both pass it
                // and each create a deal (review finding 2026-06-12).
                $fresh = CrmLead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();
                if ($fresh->status === 'converted' || $fresh->converted_deal_id !== null) {
                    throw new \DomainException('already-converted');
                }

                $deal = CrmDeal::create([
                    'company_id' => $fresh->company_id,
                    'owner_user_id' => $fresh->owner_user_id ?? optional($request->user())->id,
                    'title' => $fresh->name.($fresh->b2b_company ? ' · '.$fresh->b2b_company : ''),
                    'value_amount' => $data['value_amount'] ?? 0,
                    'currency' => $data['currency'] ?? 'USD',
                    'service_type' => $fresh->interest,
                    'stage' => 'new',
                    'source' => $fresh->source,
                    'notes' => $fresh->notes,
                ]);
                $fresh->update([
                    'status' => 'converted',
                    'converted_deal_id' => $deal->id,
                    'converted_at' => now(),
                ]);

                return $deal;
            });
        } catch (\DomainException) {
            return response()->json(['success' => false, 'message' => 'Lead is already converted'], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'lead' => $this->leadArray($lead->fresh(['owner:id,name'])),
                'deal_id' => $deal->id,
            ],
        ], 201);
    }

    /**
     * Phase-7 doctrine: the platform-admin middleware only ADMITS tenant
     * members to the CRM lead/segment writes — the controller is the real
     * gate. True when the caller is platform staff, or the target company is
     * one of their own.
     */
    private function canWriteCompanyRows(Request $request, ?int $companyId): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }
        try {
            if ($this->adminAccessService->isPlatformAdmin($user)) {
                return true;
            }
        } catch (\Throwable $e) {
            // fall through to the membership check
        }
        if ($companyId === null) {
            return false; // tenant-created rows must carry a company
        }
        $ids = $this->scopeCompanyIds($request);

        return $ids === null || in_array($companyId, $ids, true);
    }

    /** Row-level write gate for one lead: company scope + Layer-B ownership. */
    private function denyUnlessLeadWritable(Request $request, CrmLead $lead): ?JsonResponse
    {
        if (! $this->canWriteCompanyRows($request, $lead->company_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null && $lead->owner_user_id !== $rowScopeUserId) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /** Row-level write gate for one deal — same semantics as leads. */
    private function denyUnlessDealWritable(Request $request, CrmDeal $deal): ?JsonResponse
    {
        if (! $this->canWriteCompanyRows($request, $deal->company_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        $rowScopeUserId = $this->rowScopeUserId($request);
        if ($rowScopeUserId !== null && $deal->owner_user_id !== $rowScopeUserId) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }

    /** True when the proposed assignee has NO membership in the company. */
    private function ownerNotInCompany(int $ownerUserId, ?int $companyId): bool
    {
        if ($companyId === null) {
            return false; // platform-level rows have no team to validate against
        }

        return ! UserCompany::query()
            ->where('company_id', $companyId)
            ->where('user_id', $ownerUserId)
            ->exists();
    }

    private function leadArray(CrmLead $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'email' => $l->email,
            'phone' => $l->phone,
            'b2b_company' => $l->b2b_company,
            'source' => $l->source,
            'interest' => $l->interest,
            'status' => $l->status,
            'notes' => $l->notes,
            'company_id' => $l->company_id,
            'owner' => $l->owner ? ['id' => $l->owner->id, 'name' => $l->owner->name] : null,
            'converted_deal_id' => $l->converted_deal_id,
            'converted_at' => optional($l->converted_at)->toIso8601String(),
            'created_at' => optional($l->created_at)->toIso8601String(),
            'updated_at' => optional($l->updated_at)->toIso8601String(),
        ];
    }

    // ─── Segments (saved customer groups) ───────────────────────────────────

    public function listSegments(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        $query = CrmSegment::query()->withCount('members');
        if ($companyIds !== null) {
            $query->whereIn('company_id', $companyIds);
        }
        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $term = trim($search);
            $query->where('name', 'like', "%{$term}%");
        }

        // Segment lists are small (a handful of cards per company) — no
        // pagination, but hard-capped: every dynamic card costs a live
        // aggregate count, so an unbounded list would be a self-inflicted
        // performance cliff (review finding 2026-06-12).
        $segments = $query->orderBy('name')->limit(100)->get();

        $data = $segments->map(function (CrmSegment $s): array {
            $count = $s->type === 'static'
                ? (int) ($s->members_count ?? 0)
                : $this->segmentContactsQuery($s)->count();

            return $this->segmentArray($s, $count);
        })->values();

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function storeSegment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:'.implode(',', CrmSegment::TYPES)],
            'icon' => ['nullable', 'string', 'max:50'],
            'rules' => ['nullable', 'array'],
            'rules.min_bookings' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // max also rejects non-finite numerics like 1e309 → INF, which
            // would otherwise blow up json_encode on save with a 500.
            'rules.min_total_spent' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'rules.inactive_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'rules.active_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ]);

        $companyId = $request->input('company_id', $this->ownerCompanyId($request));
        $companyId = $companyId !== null ? (int) $companyId : null;
        if (! $this->canManageSegmentsOf($request, $companyId)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $segment = CrmSegment::create([
            'company_id' => $companyId,
            'created_by_user_id' => optional($request->user())->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'icon' => $data['icon'] ?? null,
            'rules' => $this->cleanSegmentRules($data['rules'] ?? null),
        ]);

        $count = $segment->type === 'static' ? 0 : $this->segmentContactsQuery($segment)->count();

        return response()->json(['success' => true, 'data' => $this->segmentArray($segment, $count)], 201);
    }

    public function updateSegment(Request $request, CrmSegment $segment): JsonResponse
    {
        if (! $this->canManageSegmentsOf($request, $segment->company_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:'.implode(',', CrmSegment::TYPES)],
            'icon' => ['nullable', 'string', 'max:50'],
            'rules' => ['nullable', 'array'],
            'rules.min_bookings' => ['nullable', 'integer', 'min:1', 'max:100000'],
            // max also rejects non-finite numerics like 1e309 → INF, which
            // would otherwise blow up json_encode on save with a 500.
            'rules.min_total_spent' => ['nullable', 'numeric', 'min:0', 'max:100000000'],
            'rules.inactive_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'rules.active_months' => ['nullable', 'integer', 'min:1', 'max:120'],
        ]);

        if (array_key_exists('rules', $data)) {
            $data['rules'] = $this->cleanSegmentRules($data['rules']);
        }

        $segment->update($data);
        $segment = $segment->fresh();

        $count = $segment->type === 'static'
            ? $segment->members()->count()
            : $this->segmentContactsQuery($segment)->count();

        return response()->json(['success' => true, 'data' => $this->segmentArray($segment, $count)]);
    }

    public function destroySegment(Request $request, CrmSegment $segment): JsonResponse
    {
        if (! $this->canManageSegmentsOf($request, $segment->company_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $segment->delete();

        return response()->json(['success' => true]);
    }

    /** The segment's current contacts (paginated) — works for both types. */
    public function segmentContacts(Request $request, CrmSegment $segment): JsonResponse
    {
        // Read access: the segment must be in the caller's company scope.
        $companyIds = $this->scopeCompanyIds($request);
        if ($companyIds !== null && ! in_array($segment->company_id, $companyIds, true)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $perPage = max(1, min((int) $request->query('per_page', 25), 200));

        $page = $this->segmentContactsQuery($segment)
            ->withCount(['bookings as bookings_count' => $this->segmentBuyerScope($segment->company_id)])
            ->orderByDesc('id')
            ->paginate($perPage);

        $data = collect($page->items())->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status,
            'bookings_count' => (int) ($u->bookings_count ?? 0),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    public function addSegmentMember(Request $request, CrmSegment $segment): JsonResponse
    {
        if (! $this->canManageSegmentsOf($request, $segment->company_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }
        if ($segment->type !== 'static') {
            return response()->json(['success' => false, 'message' => 'Members can be added only to a static segment'], 422);
        }

        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);

        // Tenant safety: only the segment company's OWN buyers may enter a
        // static list. Without this, user_id + the contacts read become a
        // platform-wide name/email oracle for any tenant owner (critical
        // review finding 2026-06-12). Same population as CRM → Customers.
        $isCompanyBuyer = User::query()
            ->whereKey((int) $data['user_id'])
            ->whereHas('bookings', $this->segmentBuyerScope($segment->company_id))
            ->exists();
        if (! $isCompanyBuyer) {
            return response()->json(['success' => false, 'message' => 'User is not a customer of this company'], 422);
        }

        $segment->members()->syncWithoutDetaching([(int) $data['user_id']]);

        return response()->json(['success' => true, 'data' => ['contacts_count' => $segment->members()->count()]]);
    }

    public function removeSegmentMember(Request $request, CrmSegment $segment, int $userId): JsonResponse
    {
        if (! $this->canManageSegmentsOf($request, $segment->company_id)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $segment->members()->detach($userId);

        return response()->json(['success' => true, 'data' => ['contacts_count' => $segment->members()->count()]]);
    }

    /**
     * Segment writes are for company OWNERS/managers (and platform staff) —
     * per the design contract agents/employees get a view-only Segments page.
     */
    private function canManageSegmentsOf(Request $request, ?int $companyId): bool
    {
        $user = $request->user();
        if ($user === null) {
            return false;
        }
        try {
            if ($this->adminAccessService->isPlatformAdmin($user)) {
                return true;
            }
        } catch (\Throwable $e) {
            return false;
        }
        if ($companyId === null) {
            return false;
        }
        $company = Company::query()->find($companyId);

        return $company !== null && $this->companyAccessService->canManageCompany($user, $company);
    }

    /** Keep only recognised rule keys with non-empty values. */
    private function cleanSegmentRules(?array $rules): ?array
    {
        if ($rules === null) {
            return null;
        }
        $clean = [];
        foreach (CrmSegment::RULE_KEYS as $key) {
            if (isset($rules[$key]) && $rules[$key] !== '') {
                $clean[$key] = is_numeric($rules[$key]) ? $rules[$key] + 0 : $rules[$key];
            }
        }

        return $clean ?: null;
    }

    /**
     * Buyer scope: ANY order with this company (as seller or referring
     * agent), no status filter — the SAME population as CRM → Customers, so
     * segment counts and the Customers tab never disagree about who "is" a
     * customer (review finding 2026-06-12).
     */
    private function segmentBuyerScope(?int $companyId): \Closure
    {
        return function ($q) use ($companyId): void {
            if ($companyId !== null) {
                $q->where(function ($w) use ($companyId): void {
                    $w->where('company_id', $companyId)
                        ->orWhere('agent_company_id', $companyId);
                });
            }
        };
    }

    /**
     * Counted-purchase scope: buyer scope + the statuses this company counts
     * as a real sale (the CRM → Options sales_count_statuses setting, same
     * source the Team leaderboard uses). Purchase-quality rules (min
     * bookings/spend/recency) evaluate against THIS scope.
     */
    private function segmentCountedScope(?int $companyId): \Closure
    {
        $statuses = $companyId !== null
            ? $this->salesCountStatusesFor($companyId)
            : CrmCompanySetting::DEFAULT_SALES_STATUSES;
        $buyerScope = $this->segmentBuyerScope($companyId);

        return function ($q) use ($statuses, $buyerScope): void {
            $q->whereIn('status', $statuses);
            $buyerScope($q);
        };
    }

    /**
     * Resolve a segment to a User query of its current contacts.
     * static → the explicit member list; dynamic → the rules json evaluated
     * live against the segment company's buyers. Rules are AND-combined; an
     * empty rule set = every buyer of the company (= CRM → Customers).
     */
    private function segmentContactsQuery(CrmSegment $segment)
    {
        if ($segment->type === 'static') {
            return User::query()->whereIn(
                'id',
                DB::table('crm_segment_members')->where('segment_id', $segment->id)->select('user_id'),
            );
        }

        $buyerScope = $this->segmentBuyerScope($segment->company_id);
        $countedScope = $this->segmentCountedScope($segment->company_id);
        $rules = is_array($segment->rules) ? $segment->rules : [];

        $query = User::query()->whereHas('bookings', $buyerScope);

        if (($min = (int) ($rules['min_bookings'] ?? 0)) > 0) {
            $query->whereHas('bookings', $countedScope, '>=', $min);
        }
        if (isset($rules['min_total_spent']) && is_numeric($rules['min_total_spent'])) {
            // Inline the threshold instead of binding it: PDO binds floats as
            // TEXT, and sqlite's affinity rules make numeric >= 'text' false
            // for every row (PG auto-casts, sqlite does not). The value is
            // validated numeric + bounded + (float)-cast, so inlining is safe.
            $sub = Order::query()->select('user_id')->groupBy('user_id')
                ->havingRaw('coalesce(sum(total),0) >= '.((float) $rules['min_total_spent']));
            $countedScope($sub);
            $query->whereIn('id', $sub);
        }
        if (($months = (int) ($rules['inactive_months'] ?? 0)) > 0) {
            $cutoff = Carbon::now()->subMonthsNoOverflow($months)->startOfDay();
            $query->whereDoesntHave('bookings', function ($q) use ($countedScope, $cutoff): void {
                $countedScope($q);
                $q->where('created_at', '>=', $cutoff);
            });
        }
        if (($months = (int) ($rules['active_months'] ?? 0)) > 0) {
            $cutoff = Carbon::now()->subMonthsNoOverflow($months)->startOfDay();
            $query->whereHas('bookings', function ($q) use ($countedScope, $cutoff): void {
                $countedScope($q);
                $q->where('created_at', '>=', $cutoff);
            });
        }

        return $query;
    }

    private function segmentArray(CrmSegment $s, int $contactsCount): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
            'type' => $s->type,
            'icon' => $s->icon,
            'rules' => $s->rules,
            'company_id' => $s->company_id,
            'contacts_count' => $contactsCount,
            'created_at' => optional($s->created_at)->toIso8601String(),
            'updated_at' => optional($s->updated_at)->toIso8601String(),
        ];
    }

    // ─── Customers (the company's own buyers) ───────────────────────────────

    /**
     * CRM Customers = the people who bought from THIS company (order.user_id on
     * orders whose seller/agent company is in the caller's scope). NOT the
     * platform B2C registry — an operator sees only their own buyers; super
     * sees every buyer. Shape matches the frontend CustomerRow
     * {id,name,email,status,bookings_count} where bookings_count is the
     * customer's order count WITH this company.
     */
    public function customers(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        // Constrains "bookings" (orders placed by the user) to the caller's
        // companies — as seller (company_id) or referring agent
        // (agent_company_id). null scope = super = no company filter.
        $orderScope = function ($q) use ($companyIds): void {
            if ($companyIds !== null) {
                $q->where(function ($w) use ($companyIds): void {
                    $w->whereIn('company_id', $companyIds)
                        ->orWhereIn('agent_company_id', $companyIds);
                });
            }
        };

        $perPage = max(1, min((int) $request->query('per_page', 25), 200));

        $query = User::query()
            ->whereHas('bookings', $orderScope)
            ->withCount(['bookings as bookings_count' => $orderScope]);

        if (is_string($search = $request->query('search')) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term): void {
                $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            });
        }
        if (is_string($status = $request->query('status')) && trim($status) !== '') {
            $query->where('status', $status);
        }

        $page = $query->orderByDesc('id')->paginate($perPage);

        $data = collect($page->items())->map(fn (User $u): array => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'status' => $u->status,
            'bookings_count' => (int) ($u->bookings_count ?? 0),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
        ]);
    }

    /**
     * Full-dataset stat cards for CRM → Customers, scoped IDENTICALLY to
     * customers(). The customer set = the caller's OWN buyers (users with >=1
     * order where the caller's company is the seller or referring agent). super
     * = no company filter. Returned over the WHOLE scoped set, not one page:
     *   active           = scoped buyers whose account status is 'active'
     *   with_bookings    = scoped buyers with >=1 order (the whole set, since a
     *                      buyer is DEFINED by having a scoped order)
     *   new_this_month   = scoped buyers created in the current calendar month
     */
    public function customersStats(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        $orderScope = function ($q) use ($companyIds): void {
            if ($companyIds !== null) {
                $q->where(function ($w) use ($companyIds): void {
                    $w->whereIn('company_id', $companyIds)
                        ->orWhereIn('agent_company_id', $companyIds);
                });
            }
        };

        $base = fn () => User::query()->whereHas('bookings', $orderScope);

        $monthStart = Carbon::now()->startOfMonth();

        return response()->json([
            'success' => true,
            'data' => [
                'active' => (int) $base()->where('status', User::STATUS_ACTIVE)->count(),
                'with_bookings' => (int) $base()->count(),
                'new_this_month' => (int) $base()->where('created_at', '>=', $monthStart)->count(),
            ],
        ]);
    }

    // ─── My agents (per-agent sales aggregation, roadmap §4) ────────────────

    /**
     * Where each order item's destination lives, per service type. item_id
     * references the concrete service row (flights.id, hotels.id, …) and the
     * canonical location field is a locations-table FK (ADR004 — the legacy
     * string city columns were dropped 2026-04-16); rows whose FK is null
     * simply don't contribute.
     *
     * @var array<string, array{0: string, 1: string}> item_type => [table, locations FK column]
     */
    private const AGENT_DESTINATION_SOURCES = [
        'hotel' => ['hotels', 'location_id'],
        'flight' => ['flights', 'arrival_location_id'],
        'transfer' => ['transfers', 'destination_location_id'],
        'car' => ['cars', 'location_id'],
        'excursion' => ['excursions', 'location_id'],
        'package' => ['packages', 'destination_location_id'],
        'visa' => ['visas', 'location_id'],
    ];

    /**
     * Operator-side scope for the My-agents aggregation:
     * [operator company ids (null = every operator, super only), counted-sale
     * statuses]. Non-super callers are ALWAYS pinned to their own companies —
     * an explicit ?company_id is honoured only for super-admins, so the
     * endpoint can never be used to read another operator's sales.
     */
    private function myAgentsScope(Request $request): array
    {
        $operatorIds = $this->scopeCompanyIds($request);
        if ($operatorIds === null) {
            $explicit = $request->query('company_id');
            if (is_numeric($explicit) && (int) $explicit > 0) {
                $operatorIds = [(int) $explicit];
            }
        }

        // "What counts as a sale" follows the company's CRM → Options setting
        // (same source as the Team leaderboard); multi-company scope falls
        // back to the platform default.
        $statuses = ($operatorIds !== null && count($operatorIds) === 1)
            ? $this->salesCountStatusesFor((int) $operatorIds[0])
            : CrmCompanySetting::DEFAULT_SALES_STATUSES;

        return [$operatorIds, $statuses];
    }

    /** Counted-sale orders sold by the scoped operator(s) THROUGH an agent. */
    private function agentOrdersQuery(?array $operatorIds, array $statuses)
    {
        return Order::query()
            ->whereNotNull('agent_company_id')
            ->whereIn('status', $statuses)
            ->when($operatorIds !== null, fn ($q) => $q->whereIn('company_id', $operatorIds));
    }

    /** Same population at the order-item grain (joined for type/destination breakdowns). */
    private function agentOrderItemsQuery(?array $operatorIds, array $statuses)
    {
        return DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->whereNotNull('orders.agent_company_id')
            ->whereIn('orders.status', $statuses)
            ->when($operatorIds !== null, fn ($q) => $q->whereIn('orders.company_id', $operatorIds));
    }

    /**
     * By-service + top-destination breakdowns for ANY order_items scope
     * (used by both the per-agent and per-employee stat endpoints).
     *
     * @param  callable(): \Illuminate\Database\Query\Builder  $itemScope
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function itemBreakdowns(callable $itemScope): array
    {
        $services = $itemScope()
            ->selectRaw('order_items.item_type, count(*) as bookings')
            ->groupBy('order_items.item_type')
            ->get()
            ->map(fn ($r) => ['type' => $r->item_type, 'bookings' => (int) $r->bookings])
            ->sortByDesc('bookings')
            ->values();

        $destinationCounts = [];
        foreach (self::AGENT_DESTINATION_SOURCES as $type => [$table, $fkColumn]) {
            $rows = $itemScope()
                ->join($table, "{$table}.id", '=', 'order_items.item_id')
                ->join('locations', 'locations.id', '=', "{$table}.{$fkColumn}")
                ->where('order_items.item_type', $type)
                ->selectRaw('locations.name as destination, count(*) as bookings')
                ->groupBy('locations.name')
                ->get();
            foreach ($rows as $r) {
                $name = trim((string) $r->destination);
                if ($name === '') {
                    continue;
                }
                $destinationCounts[$name] = ($destinationCounts[$name] ?? 0) + (int) $r->bookings;
            }
        }
        arsort($destinationCounts);
        $destinations = collect(array_slice($destinationCounts, 0, 6, true))
            ->map(fn ($bookings, $name) => ['name' => $name, 'bookings' => $bookings])
            ->values();

        return [$services, $destinations];
    }

    /**
     * The agent's earned-share rows in the entitlements ledger. Tagged
     * 'agent_share_of_order_id:<id>;order_item_id:<id>;operator_company_id:<id>'
     * by FinanceService::createEntitlementsForOrder. operator_company_id is the
     * LAST note segment, so an exact-suffix LIKE pins the operator without
     * matching longer ids (':5' cannot suffix-match ':55').
     */
    private function agentCommissionQuery(?array $operatorIds)
    {
        return DB::table('supplier_entitlements')
            ->where('notes', 'like', 'agent_share_of_order_id:%')
            ->where('status', '!=', 'cancelled')
            ->when($operatorIds !== null, function ($q) use ($operatorIds): void {
                $q->where(function ($w) use ($operatorIds): void {
                    foreach ($operatorIds as $opId) {
                        $w->orWhere('notes', 'like', '%;operator_company_id:'.(int) $opId);
                    }
                });
            });
    }

    /**
     * GET crm/my-agents/stats — per-agent sales/revenue/commission aggregates
     * over the caller's own orders (operator view of the agents who sell their
     * inventory). Revenue/commission are grouped by currency — amounts in
     * different currencies are never summed together.
     */
    public function myAgentsStats(Request $request): JsonResponse
    {
        [$operatorIds, $statuses] = $this->myAgentsScope($request);

        $orderRows = $this->agentOrdersQuery($operatorIds, $statuses)
            ->selectRaw('agent_company_id, currency, count(*) as sales, coalesce(sum(total),0) as revenue')
            ->groupBy('agent_company_id', 'currency')
            ->get();

        $bookingCounts = $this->agentOrderItemsQuery($operatorIds, $statuses)
            ->selectRaw('orders.agent_company_id as agent_company_id, count(*) as bookings')
            ->groupBy('orders.agent_company_id')
            ->pluck('bookings', 'agent_company_id');

        $commissionRows = $this->agentCommissionQuery($operatorIds)
            ->selectRaw('company_id as agent_company_id, currency, coalesce(sum(net_amount),0) as commission')
            ->groupBy('company_id', 'currency')
            ->get();

        $blank = fn (int $id): array => [
            'agent_company_id' => $id,
            'agent_name' => null,
            'sales' => 0,
            'bookings' => (int) ($bookingCounts[$id] ?? 0),
            'revenue' => [],
            'commission' => [],
        ];

        $agents = [];
        foreach ($orderRows as $r) {
            $id = (int) $r->agent_company_id;
            $agents[$id] ??= $blank($id);
            $agents[$id]['sales'] += (int) $r->sales;
            $agents[$id]['revenue'][] = ['currency' => $r->currency, 'total' => (float) $r->revenue];
        }
        foreach ($commissionRows as $r) {
            $id = (int) $r->agent_company_id;
            $agents[$id] ??= $blank($id);
            $agents[$id]['commission'][] = ['currency' => $r->currency, 'total' => (float) $r->commission];
        }

        $names = Company::query()->whereIn('id', array_keys($agents))->pluck('name', 'id');
        $summaryRevenue = [];
        $salesTotal = 0;
        $bookingsTotal = 0;
        foreach ($agents as $id => &$a) {
            $a['agent_name'] = $names[$id] ?? null;
            $salesTotal += $a['sales'];
            $bookingsTotal += $a['bookings'];
            foreach ($a['revenue'] as $rv) {
                $summaryRevenue[$rv['currency']] = ($summaryRevenue[$rv['currency']] ?? 0.0) + $rv['total'];
            }
        }
        unset($a);

        return response()->json([
            'success' => true,
            'data' => [
                'agents' => array_values($agents),
                'summary' => [
                    'agents_with_sales' => count($agents),
                    'sales' => $salesTotal,
                    'bookings' => $bookingsTotal,
                    'revenue' => collect($summaryRevenue)
                        ->map(fn ($total, $currency) => ['currency' => $currency, 'total' => (float) $total])
                        ->values(),
                ],
            ],
        ]);
    }

    /**
     * GET crm/my-agents/{company}/stats — one agent's full breakdown: stat
     * cards, top destinations, by-service split and recent order history.
     * Everything is computed ONLY over the caller's scoped orders, so passing
     * an arbitrary company id yields zeros — never another tenant's data (the
     * company is also never looked up, so the id can't be used as an
     * existence/name oracle).
     */
    public function myAgentStatsDetail(Request $request, int $company): JsonResponse
    {
        [$operatorIds, $statuses] = $this->myAgentsScope($request);

        $revenueRows = $this->agentOrdersQuery($operatorIds, $statuses)
            ->where('agent_company_id', $company)
            ->selectRaw('currency, count(*) as sales, coalesce(sum(total),0) as revenue')
            ->groupBy('currency')
            ->get();

        $itemScope = fn () => $this->agentOrderItemsQuery($operatorIds, $statuses)
            ->where('orders.agent_company_id', $company);

        [$services, $destinations] = $this->itemBreakdowns($itemScope);

        $commission = $this->agentCommissionQuery($operatorIds)
            ->where('company_id', $company)
            ->selectRaw('currency, coalesce(sum(net_amount),0) as commission')
            ->groupBy('currency')
            ->get()
            ->map(fn ($r) => ['currency' => $r->currency, 'total' => (float) $r->commission])
            ->values();

        // Order history shows EVERY non-cart order via this agent (incl.
        // pending/cancelled, each with its status badge); the stat cards above
        // stay on the company's counted-sale statuses.
        $recent = Order::query()
            ->with('user:id,name,email')
            ->where('agent_company_id', $company)
            ->when($operatorIds !== null, fn ($q) => $q->whereIn('company_id', $operatorIds))
            ->where('status', '!=', 'cart')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $itemTypesByOrder = DB::table('order_items')
            ->whereIn('order_id', $recent->pluck('id'))
            ->whereNull('deleted_at')
            ->selectRaw('order_id, item_type, count(*) as cnt')
            ->groupBy('order_id', 'item_type')
            ->get()
            ->groupBy('order_id');

        $orders = $recent->map(fn (Order $o): array => [
            'id' => $o->id,
            'order_number' => $o->order_number,
            'date' => optional($o->created_at)->toIso8601String(),
            'customer' => $o->user ? ['id' => $o->user->id, 'name' => $o->user->name, 'email' => $o->user->email] : null,
            'services' => collect($itemTypesByOrder->get($o->id) ?? [])
                ->map(fn ($r) => ['type' => $r->item_type, 'count' => (int) $r->cnt])
                ->values(),
            'total' => (float) $o->total,
            'currency' => $o->currency,
            'status' => $o->status,
        ])->values();

        return response()->json([
            'success' => true,
            'data' => [
                'sales' => (int) $revenueRows->sum('sales'),
                'bookings' => (int) $services->sum('bookings'),
                'revenue' => $revenueRows
                    ->map(fn ($r) => ['currency' => $r->currency, 'total' => (float) $r->revenue])
                    ->values(),
                'commission' => $commission,
                'destinations' => $destinations,
                'services' => $services,
                'orders' => $orders,
            ],
        ]);
    }

    // ─── Stats (Pipeline + Team feed) ───────────────────────────────────────

    public function stats(Request $request): JsonResponse
    {
        $companyIds = $this->scopeCompanyIds($request);

        $dealQuery = CrmDeal::query();
        if ($companyIds !== null) {
            $dealQuery->whereIn('company_id', $companyIds);
        }
        $open = (clone $dealQuery)->whereNotIn('stage', ['won', 'lost']);

        return response()->json([
            'success' => true,
            'data' => [
                'open_deals' => (clone $open)->count(),
                'pipeline_value' => (float) (clone $open)->sum('value_amount'),
                'won_deals' => (clone $dealQuery)->where('stage', 'won')->count(),
                'by_stage' => (clone $dealQuery)
                    ->selectRaw('stage, count(*) as count, coalesce(sum(value_amount),0) as value')
                    ->groupBy('stage')
                    ->get()
                    ->keyBy('stage'),
            ],
        ]);
    }

    // ─── Serialisers ────────────────────────────────────────────────────────

    private function dealArray(CrmDeal $d): array
    {
        return [
            'id' => $d->id,
            'title' => $d->title,
            'value_amount' => (float) $d->value_amount,
            'currency' => $d->currency,
            'service_type' => $d->service_type,
            'stage' => $d->stage,
            'probability' => $d->probability,
            'source' => $d->source,
            'expected_close_date' => optional($d->expected_close_date)->toDateString(),
            'company_id' => $d->company_id,
            'customer' => $d->customer ? ['id' => $d->customer->id, 'name' => $d->customer->name, 'email' => $d->customer->email] : null,
            'owner' => $d->owner ? ['id' => $d->owner->id, 'name' => $d->owner->name] : null,
            'created_at' => optional($d->created_at)->toIso8601String(),
            'updated_at' => optional($d->updated_at)->toIso8601String(),
        ];
    }

    private function activityArray(CrmActivity $a): array
    {
        return [
            'id' => $a->id,
            'type' => $a->type,
            'subject' => $a->subject,
            'body' => $a->body,
            'subject_type' => $a->subject_type,
            'subject_id' => $a->subject_id,
            'status' => $a->status,
            'due_at' => optional($a->due_at)->toIso8601String(),
            'completed_at' => optional($a->completed_at)->toIso8601String(),
            'company_id' => $a->company_id,
            'owner' => $a->owner ? ['id' => $a->owner->id, 'name' => $a->owner->name] : null,
            'created_at' => optional($a->created_at)->toIso8601String(),
        ];
    }

    // ─── Team (per-employee sales + payroll) ─────────────────────────────

    /** Resolve which company's team to show: explicit ?company_id, else caller's first. */
    private function teamCompanyId(Request $request): ?int
    {
        $explicit = $request->query('company_id');
        $explicitId = is_numeric($explicit) && (int) $explicit > 0 ? (int) $explicit : null;

        // Platform staff may inspect any company's team.
        $user = $request->user();
        $isStaff = false;
        try {
            $isStaff = $user !== null && $this->adminAccessService->isPlatformAdmin($user);
        } catch (\Throwable $e) {
            $isStaff = false;
        }
        if ($isStaff) {
            return $explicitId ?? $this->ownerCompanyId($request);
        }

        // Tenants: an explicit company_id is honoured ONLY for their own
        // companies — without this check the read allowlist would let any
        // operator pull another team's emails, roles and pay via ?company_id=.
        if ($explicitId !== null) {
            $ids = $this->scopeCompanyIds($request);
            if ($ids === null || in_array($explicitId, $ids, true)) {
                return $explicitId;
            }
        }

        return $this->ownerCompanyId($request);
    }

    /**
     * Team sales leaderboard + computed pay for one company + month.
     * Per employee: attributed order revenue (sold_by_user_id) grouped by
     * currency for the period, won-deal count, and pay computed from their
     * compensation config.
     */
    public function team(Request $request): JsonResponse
    {
        $companyId = $this->teamCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => true, 'data' => [], 'meta' => ['company_id' => null]]);
        }

        // Period: default to the current calendar month (YYYY-MM via ?month).
        $month = (string) $request->query('month', now()->format('Y-m'));
        try {
            $start = Carbon::createFromFormat('Y-m-d H:i:s', $month.'-01 00:00:00')->startOfMonth();
        } catch (\Throwable $e) {
            $start = now()->startOfMonth();
        }
        $end = (clone $start)->endOfMonth();

        $company = Company::query()
            ->with(['users:id,name,email,status,phone,created_at,last_login_at'])
            ->find($companyId);
        if ($company === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }

        // §7 — role per member (drives the Team pane's action-button gating).
        $roleNames = UserCompany::query()
            ->where('company_id', $companyId)
            ->join('roles', 'user_company.role_id', '=', 'roles.id')
            ->pluck('roles.name', 'user_company.user_id');

        $comp = CrmEmployeeCompensation::query()
            ->where('company_id', $companyId)
            ->get()
            ->keyBy('user_id');

        // Which order statuses count as a sale is a per-company setting (the
        // CRM Options page). Defaults to paid+confirmed when not customised.
        $countStatuses = $this->salesCountStatusesFor($companyId);

        $rows = [];
        foreach ($company->users as $emp) {
            // Attribution model (Arshak's decision): a sale counts for an
            // employee when their DEAL is marked "won". So revenue = the value
            // of the deals this employee won in the period, grouped by currency.
            // (The order-based path + the Options sales_count_statuses gate
            // still exist for direct bookings; see $countStatuses below.)
            $revenueByCurrency = CrmDeal::query()
                ->where('owner_user_id', $emp->id)
                ->where('stage', 'won')
                ->whereBetween('updated_at', [$start, $end])
                ->selectRaw('currency, count(*) as orders_count, coalesce(sum(value_amount),0) as revenue')
                ->groupBy('currency')
                ->get();

            $wonDeals = (int) $revenueByCurrency->sum('orders_count');
            $ordersCount = $wonDeals;
            // Informational: direct bookings attributed to this employee that
            // also reached a counted status (the order path; usually 0 until a
            // booking flow stamps sold_by_user_id).
            $directOrders = Order::query()
                ->where('sold_by_user_id', $emp->id)
                ->whereIn('status', $countStatuses)
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $cfg = $comp->get($emp->id);
            $payCurrency = $cfg?->currency ?? 'USD';
            $revenueInPayCurrency = (float) ($revenueByCurrency->firstWhere('currency', $payCurrency)->revenue ?? 0);
            $computedPay = $cfg ? $cfg->computePay($revenueInPayCurrency) : null;

            $rows[] = [
                'user' => [
                    'id' => $emp->id,
                    'name' => $emp->name,
                    'email' => $emp->email,
                    'status' => $emp->status,
                    'role_name' => $roleNames[$emp->id] ?? null,
                    'phone' => $emp->phone,
                    'joined_at' => optional($emp->created_at)->toIso8601String(),
                    'last_login_at' => optional($emp->last_login_at)->toIso8601String(),
                ],
                'orders_count' => $ordersCount,
                'won_deals' => $wonDeals,
                'direct_orders' => $directOrders,
                'revenue_by_currency' => $revenueByCurrency->map(fn ($r) => [
                    'currency' => $r->currency,
                    'orders_count' => (int) $r->orders_count,
                    'revenue' => (float) $r->revenue,
                ])->values(),
                'compensation' => $cfg ? $this->compensationArray($cfg) : null,
                'computed_pay' => $computedPay,
                'pay_currency' => $payCurrency,
            ];
        }

        // Highest revenue (in their pay currency) first — the leaderboard order.
        usort($rows, fn ($a, $b) => ($b['computed_pay'] ?? 0) <=> ($a['computed_pay'] ?? 0));

        return response()->json([
            'success' => true,
            'data' => $rows,
            'meta' => [
                'company_id' => $companyId,
                'month' => $start->format('Y-m'),
                'sales_count_statuses' => $countStatuses,
            ],
        ]);
    }

    /**
     * GET crm/team/{user}/stats — one employee's performance breakdowns for
     * the team-detail Performance tab: last-6-months won-deal revenue series
     * + by-service / top-destination splits of their direct bookings
     * (orders.sold_by_user_id, counted statuses only). Company scope comes
     * from teamCompanyId (tenant-validated), and the target must actually be
     * a member of that company.
     */
    public function teamMemberStats(Request $request, int $user): JsonResponse
    {
        $companyId = $this->teamCompanyId($request);
        if ($companyId === null) {
            return response()->json(['success' => false, 'message' => 'Company not found'], 404);
        }
        $isMember = UserCompany::query()
            ->where('company_id', $companyId)
            ->where('user_id', $user)
            ->exists();
        if (! $isMember) {
            return response()->json(['success' => false, 'message' => 'User not found in company'], 404);
        }

        // Last 6 calendar months of won-deal revenue, grouped in PHP — month
        // extraction SQL differs between the sqlite test DB and PG prod.
        $from = now()->startOfMonth()->subMonthsNoOverflow(5);
        $deals = CrmDeal::query()
            ->where('owner_user_id', $user)
            ->where('company_id', $companyId)
            ->where('stage', 'won')
            ->where('updated_at', '>=', $from)
            ->get(['currency', 'value_amount', 'updated_at']);

        $monthly = [];
        for ($i = 0; $i < 6; $i++) {
            $m = (clone $from)->addMonthsNoOverflow($i)->format('Y-m');
            $monthly[$m] = ['month' => $m, 'won' => 0, 'revenue' => []];
        }
        foreach ($deals as $d) {
            $key = optional($d->updated_at)->format('Y-m');
            if ($key === null || ! isset($monthly[$key])) {
                continue;
            }
            $monthly[$key]['won']++;
            $cur = $d->currency ?? 'USD';
            $monthly[$key]['revenue'][$cur] = ($monthly[$key]['revenue'][$cur] ?? 0.0) + (float) $d->value_amount;
        }
        $monthlyOut = array_values(array_map(fn (array $m): array => [
            'month' => $m['month'],
            'won' => $m['won'],
            'revenue' => collect($m['revenue'])
                ->map(fn ($total, $currency) => ['currency' => $currency, 'total' => (float) $total])
                ->values(),
        ], $monthly));

        $countStatuses = $this->salesCountStatusesFor($companyId);
        $itemScope = fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereNull('order_items.deleted_at')
            ->whereNull('orders.deleted_at')
            ->where('orders.company_id', $companyId)
            ->where('orders.sold_by_user_id', $user)
            ->whereIn('orders.status', $countStatuses);

        [$services, $destinations] = $this->itemBreakdowns($itemScope);

        return response()->json([
            'success' => true,
            'data' => [
                'monthly' => $monthlyOut,
                'services' => $services,
                'destinations' => $destinations,
            ],
            'meta' => ['company_id' => $companyId],
        ]);
    }

    // ─── CRM Options (per-company settings) ──────────────────────────────

    /** Resolve the counted-sale statuses for a company (with default). */
    private function salesCountStatusesFor(int $companyId): array
    {
        $row = CrmCompanySetting::query()->where('company_id', $companyId)->first();

        return $row
            ? $row->salesCountStatuses()
            : CrmCompanySetting::DEFAULT_SALES_STATUSES;
    }

    public function getSettings(Request $request): JsonResponse
    {
        $companyId = $this->teamCompanyId($request);
        if ($companyId === null) {
            return response()->json([
                'success' => true,
                'data' => [
                    'company_id' => null,
                    'sales_count_statuses' => CrmCompanySetting::DEFAULT_SALES_STATUSES,
                    'sales_status_options' => CrmCompanySetting::SALES_STATUS_OPTIONS,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $companyId,
                'sales_count_statuses' => $this->salesCountStatusesFor($companyId),
                'sales_status_options' => CrmCompanySetting::SALES_STATUS_OPTIONS,
            ],
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'sales_count_statuses' => ['required', 'array', 'min:1'],
            'sales_count_statuses.*' => ['string', 'in:'.implode(',', CrmCompanySetting::SALES_STATUS_OPTIONS)],
        ]);

        $companyId = (int) $data['company_id'];
        $row = CrmCompanySetting::query()->firstOrNew(['company_id' => $companyId]);
        $settings = $row->settings ?? [];
        $settings['sales_count_statuses'] = array_values(array_unique($data['sales_count_statuses']));
        $row->settings = $settings;
        $row->updated_by_user_id = optional($request->user())->id;
        $row->save();

        return response()->json([
            'success' => true,
            'data' => [
                'company_id' => $companyId,
                'sales_count_statuses' => $row->salesCountStatuses(),
                'sales_status_options' => CrmCompanySetting::SALES_STATUS_OPTIONS,
            ],
        ]);
    }

    public function setCompensation(Request $request, int $userId): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'model' => ['required', 'string', 'in:'.implode(',', CrmEmployeeCompensation::MODELS)],
            'base_amount' => ['nullable', 'numeric', 'min:0'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['nullable', 'string', 'max:3'],
            'notes' => ['nullable', 'string'],
        ]);

        $companyId = (int) $data['company_id'];
        $user = $request->user();

        // Authorisation gate. The platform-admin middleware now ADMITS a company
        // owner to this write, so the real ownership check lives here. Super /
        // platform staff keep their existing governance access; a tenant owner
        // (operator/agent) may set pay ONLY for an employee of a company they
        // manage. A plain employee, or a cross-company attempt, is refused.
        if (! $this->adminAccessService->isPlatformAdmin($user)) {
            $company = Company::query()->find($companyId);
            if ($company === null
                || ! $this->companyAccessService->canManageCompany($user, $company)) {
                return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
            }
            if (! $company->users()->whereKey($userId)->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee is not a member of this company',
                ], 422);
            }
        }

        $cfg = CrmEmployeeCompensation::updateOrCreate(
            ['user_id' => $userId, 'company_id' => $companyId],
            [
                'model' => $data['model'],
                'base_amount' => $data['base_amount'] ?? 0,
                'commission_percent' => $data['commission_percent'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'notes' => $data['notes'] ?? null,
                'updated_by_user_id' => optional($request->user())->id,
            ],
        );

        return response()->json(['success' => true, 'data' => $this->compensationArray($cfg->fresh())]);
    }

    private function compensationArray(CrmEmployeeCompensation $c): array
    {
        return [
            'id' => $c->id,
            'user_id' => $c->user_id,
            'company_id' => $c->company_id,
            'model' => $c->model,
            'base_amount' => (float) $c->base_amount,
            'commission_percent' => (float) $c->commission_percent,
            'currency' => $c->currency,
            'notes' => $c->notes,
        ];
    }
}
