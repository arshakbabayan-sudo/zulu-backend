<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportSession;
use App\Services\Admin\AdminAccessService;
use App\Services\Imports\Exceptions\ImportSessionNotStageableException;
use App\Services\Imports\ImportStageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportSessionController extends Controller
{
    public function __construct(
        private AdminAccessService $adminAccessService,
        private ImportStageService $importStageService,
    ) {}

    public function show(Request $request, ImportSession $import_session): JsonResponse
    {
        if (! $this->adminAccessService->allowsCommerceOperatorAccess(
            $request->user(),
            (int) $import_session->company_id,
            'imports.upload'
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sessionPayload($import_session),
        ]);
    }

    public function stage(Request $request, ImportSession $import_session): JsonResponse
    {
        if (! $this->adminAccessService->allowsCommerceOperatorAccess(
            $request->user(),
            (int) $import_session->company_id,
            'imports.upload'
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        try {
            $result = $this->importStageService->stage($import_session);
        } catch (ImportSessionNotStageableException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $response = [
            'success' => $result['status'] !== ImportSession::STATUS_VALIDATION_FAILED,
            'data' => [
                'session_id' => $result['session_id'],
                'status' => $result['status'],
                'rows_total' => $result['rows_total'],
                'rows_valid' => $result['rows_valid'],
                'rows_invalid' => $result['rows_invalid'],
            ],
        ];

        if (isset($result['structural_errors'])) {
            $response['data']['structural_errors'] = $result['structural_errors'];
        }

        $statusCode = $result['status'] === ImportSession::STATUS_VALIDATION_FAILED ? 422 : 200;

        return response()->json($response, $statusCode);
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionPayload(ImportSession $session): array
    {
        return [
            'session_id' => $session->id,
            'company_id' => $session->company_id,
            'user_id' => $session->user_id,
            'template_version' => $session->template_version,
            'status' => $session->status,
            'dry_run' => $session->dry_run,
            'sync_mode' => $session->sync_mode,
            'original_filename' => $session->original_filename,
            'mime_type' => $session->mime_type,
            'rows_total' => $session->rows_total,
            'rows_valid' => $session->rows_valid,
            'rows_invalid' => $session->rows_invalid,
            'validation_errors_count' => $session->validation_errors_count,
            'started_at' => $session->started_at?->toIso8601String(),
            'finished_at' => $session->finished_at?->toIso8601String(),
            'created_at' => $session->created_at?->toIso8601String(),
            'updated_at' => $session->updated_at?->toIso8601String(),
        ];
    }
}
