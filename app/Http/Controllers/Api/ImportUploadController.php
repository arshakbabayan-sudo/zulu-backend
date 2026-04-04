<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportSession;
use App\Services\Admin\AdminAccessService;
use App\Services\Imports\ImportSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ImportUploadController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private ImportSessionService $importSessionService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'file' => ['required', 'file', 'mimes:csv,xlsx', 'max:51200'],
            'template_version' => ['required', 'string', 'max:255'],
            'dry_run' => ['sometimes', 'boolean'],
            'sync_mode' => ['sometimes', 'nullable', 'string', Rule::in(ImportSession::SYNC_MODES)],
        ]);

        $companyId = (int) $validated['company_id'];

        if (! $this->adminAccessService->allowsCommerceOperatorAccess(
            $request->user(),
            $companyId,
            'imports.upload'
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $options = $this->importSessionService->normalizeOptions([
            'template_version' => $validated['template_version'],
            'dry_run' => $validated['dry_run'] ?? false,
            'sync_mode' => $validated['sync_mode'] ?? ImportSession::SYNC_MODE_PARTIAL,
        ]);

        $session = $this->importSessionService->createFromAuthenticatedUpload(
            $request->user(),
            $companyId,
            $request->file('file'),
            $options['template_version'],
            $options['dry_run'],
            $options['sync_mode'],
        );

        return response()->json([
            'success' => true,
            'data' => [
                'session_id' => $session->id,
                'status' => $session->status,
                'template_version' => $session->template_version,
                'dry_run' => $session->dry_run,
                'sync_mode' => $session->sync_mode,
                'original_filename' => $session->original_filename,
            ],
        ], 201);
    }
}
