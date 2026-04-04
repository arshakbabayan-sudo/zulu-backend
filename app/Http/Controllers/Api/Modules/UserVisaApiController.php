<?php

namespace App\Http\Controllers\Api\Modules;

use App\Http\Controllers\Controller;
use App\Services\Modules\VisaApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Legacy / unwired JSON handler (Phase 7 candidate).
 *
 * Not registered in `routes/api.php`. Tenant visa CRUD uses `Api\VisaController` under Sanctum; this class was intended
 * as an end-user “apply for visa” bridge — enable only after product sign-off and route registration.
 *
 * @internal
 */
class UserVisaApiController extends Controller
{
    public function __construct(
        private readonly VisaApplicationService $visaService
    ) {}

    /** Apply for a visa (user-side API bridge; requires future route registration). */
    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = is_object($user) && isset($user->id) ? (int) $user->id : 0;

        if ($userId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $validated = $request->validate([
            'visa_id' => ['required', 'integer'],
            'passport_number' => ['required', 'string', 'max:100'],
            'entry_date' => ['nullable', 'date'],
            'exit_date' => ['nullable', 'date', 'after_or_equal:entry_date'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            // `files` is handled below to support both single + multiple uploads.
        ]);

        $filesInput = $request->file('files', []);
        $files = [];

        if ($filesInput instanceof UploadedFile) {
            $files = [$filesInput];
        } elseif (is_array($filesInput)) {
            $files = $filesInput;
        }

        $application = $this->visaService->submitApplication(
            $userId,
            (int) $validated['visa_id'],
            [
                'passport_number' => (string) $validated['passport_number'],
                'entry_date' => $validated['entry_date'] ?? null,
                'exit_date' => $validated['exit_date'] ?? null,
                'admin_notes' => $validated['admin_notes'] ?? null,
            ],
            $files
        );

        return response()->json([
            'success' => true,
            'data' => $application,
        ]);
    }
}

