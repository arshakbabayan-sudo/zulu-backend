<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CustomFieldDefinition;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 7.4 — custom field definition CRUD scoped per operator company.
 */
class CustomFieldsController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $query = CustomFieldDefinition::query()
            ->with('company:id,name')
            ->orderBy('scope')
            ->orderBy('display_order')
            ->orderBy('id');

        if (! $this->adminAccessService->isSuperAdmin($user)) {
            $companyIds = $user->companies()->pluck('companies.id')->all();
            $query->whereIn('company_id', $companyIds);
        }

        if (in_array($scope = (string) $request->query('scope'), CustomFieldDefinition::SCOPES, true)) {
            $query->where('scope', $scope);
        }
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $rows = $query->get();

        return response()->json([
            'success' => true,
            'data' => [
                'available_field_types' => CustomFieldDefinition::FIELD_TYPES,
                'available_scopes' => CustomFieldDefinition::SCOPES,
                'fields' => $rows->map(fn ($r) => $this->serialize($r))->values()->all(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user === null) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $this->validateBody($request, false);

        $companyId = $user->companies()->pluck('companies.id')->first();
        if (! $companyId) {
            return response()->json(['success' => false, 'message' => 'No active company.'], 422);
        }

        $row = CustomFieldDefinition::query()->create([
            'company_id' => $companyId,
            'scope' => $validated['scope'],
            'key' => $validated['key'],
            'label' => $validated['label'],
            'field_type' => $validated['field_type'],
            'options' => $validated['options'] ?? null,
            'help_text' => $validated['help_text'] ?? null,
            'is_required' => $validated['is_required'] ?? false,
            'show_in_filter' => $validated['show_in_filter'] ?? false,
            'display_order' => $validated['display_order'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'created_by_user_id' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh('company')),
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwned($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        $validated = $this->validateBody($request, true);
        $row->fill($validated);
        $row->save();

        return response()->json([
            'success' => true,
            'data' => $this->serialize($row->fresh('company')),
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = $this->resolveOwned($request, $id);
        if ($row === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }
        $row->delete();

        return response()->json(['success' => true, 'data' => ['deleted_id' => $id]]);
    }

    /** @return array<string, mixed> */
    private function validateBody(Request $request, bool $partial): array
    {
        $rule = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'scope' => [$rule, Rule::in(CustomFieldDefinition::SCOPES)],
            'key' => [$rule, 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'label' => [$rule, 'string', 'max:255'],
            'field_type' => [$rule, Rule::in(CustomFieldDefinition::FIELD_TYPES)],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'help_text' => ['nullable', 'string', 'max:500'],
            'is_required' => ['sometimes', 'boolean'],
            'show_in_filter' => ['sometimes', 'boolean'],
            'display_order' => ['nullable', 'integer'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function resolveOwned(Request $request, int $id): ?CustomFieldDefinition
    {
        $user = $request->user();
        if ($user === null) {
            return null;
        }
        $row = CustomFieldDefinition::query()->find($id);
        if ($row === null) {
            return null;
        }
        if ($this->adminAccessService->isSuperAdmin($user)) {
            return $row;
        }
        $companyIds = $user->companies()->pluck('companies.id')->all();
        return in_array((int) $row->company_id, $companyIds, true) ? $row : null;
    }

    /** @return array<string, mixed> */
    private function serialize(CustomFieldDefinition $row): array
    {
        return [
            'id' => $row->id,
            'company_id' => $row->company_id,
            'company_name' => $row->company?->name,
            'scope' => $row->scope,
            'key' => $row->key,
            'label' => $row->label,
            'field_type' => $row->field_type,
            'options' => $row->options,
            'help_text' => $row->help_text,
            'is_required' => (bool) $row->is_required,
            'show_in_filter' => (bool) $row->show_in_filter,
            'display_order' => (int) $row->display_order,
            'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at?->toIso8601String(),
            'updated_at' => $row->updated_at?->toIso8601String(),
        ];
    }
}
