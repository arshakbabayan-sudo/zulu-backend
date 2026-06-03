<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractTemplate;
use App\Services\Admin\AdminAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminContractTemplateController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
    ) {}

    /**
     * GET /api/platform-admin/contract-templates
     */
    public function index(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $query = ContractTemplate::query()->orderBy('type')->orderBy('language')->orderByDesc('version');

        if (is_string($type = $request->query('type')) && in_array($type, ContractTemplate::TYPES, true)) {
            $query->where('type', $type);
        }

        if (is_string($language = $request->query('language')) && in_array($language, ContractTemplate::LANGUAGES, true)) {
            $query->where('language', $language);
        }

        if ($request->boolean('active_only', false)) {
            $query->where('active', true);
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * GET /api/platform-admin/contract-templates/{template}
     */
    public function show(Request $request, string $template): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = ContractTemplate::query()->find($template);

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }

        return response()->json(['success' => true, 'data' => $model]);
    }

    /**
     * POST /api/platform-admin/contract-templates
     */
    public function store(Request $request): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'type' => ['required', 'string', Rule::in(ContractTemplate::TYPES)],
            'language' => ['required', 'string', Rule::in(ContractTemplate::LANGUAGES)],
            'body' => 'required|string',
            'variables' => 'nullable|array',
            'active' => 'sometimes|boolean',
        ]);

        $template = ContractTemplate::query()->create([
            'name' => $data['name'],
            'type' => $data['type'],
            'language' => $data['language'],
            'version' => 1,
            'body' => $data['body'],
            'variables' => $data['variables'] ?? [],
            'active' => $data['active'] ?? true,
        ]);

        return response()->json(['success' => true, 'data' => $template], 201);
    }

    /**
     * PATCH /api/platform-admin/contract-templates/{template}
     * Bumps version on body change.
     */
    public function update(Request $request, string $template): JsonResponse
    {
        if ($deny = $this->denyUnlessPlatformAdmin($request)) {
            return $deny;
        }

        $model = ContractTemplate::query()->find($template);
        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Template not found'], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'type' => ['sometimes', 'string', Rule::in(ContractTemplate::TYPES)],
            'language' => ['sometimes', 'string', Rule::in(ContractTemplate::LANGUAGES)],
            'body' => 'sometimes|string',
            'variables' => 'sometimes|array',
            'active' => 'sometimes|boolean',
        ]);

        if (isset($data['body']) && $data['body'] !== $model->body) {
            $model->version = (int) $model->version + 1;
        }

        $model->fill($data);
        $model->save();

        return response()->json(['success' => true, 'data' => $model->fresh()]);
    }

    private function denyUnlessPlatformAdmin(Request $request): ?JsonResponse
    {
        $user = $request->user();
        if ($user === null || ! $this->adminAccessService->isPlatformAdmin($user)) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        return null;
    }
}
