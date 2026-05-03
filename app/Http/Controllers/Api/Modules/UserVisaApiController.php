<?php

namespace App\Http\Controllers\Api\Modules;

use App\Http\Controllers\Controller;
use App\Models\VisaApplication;
use App\Services\Modules\VisaApplicationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Customer-facing visa application controller (PART 16, Sprint 63).
 *
 * Customer flow:
 *   POST /api/visa-applications        — submit a new application
 *   GET  /api/visa-applications        — list my applications
 *   GET  /api/visa-applications/{id}   — show single application (must be mine)
 */
class UserVisaApiController extends Controller
{
    public function __construct(
        private readonly VisaApplicationService $visaService
    ) {}

    /** POST /api/visa-applications — apply for a visa */
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
        ], 201);
    }

    /** GET /api/visa-applications — list my own applications */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = is_object($user) && isset($user->id) ? (int) $user->id : 0;

        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $applications = VisaApplication::query()
            ->with(['visa:id,country_id,country,name,visa_type,price'])
            ->where('user_id', $userId)
            ->latest('id')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $applications,
        ]);
    }

    /** GET /api/visa-applications/{id} — show one of my applications */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $userId = is_object($user) && isset($user->id) ? (int) $user->id : 0;

        if ($userId <= 0) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $application = VisaApplication::query()
            ->with(['visa:id,country_id,country,name,visa_type,price,description'])
            ->where('id', $id)
            ->where('user_id', $userId)
            ->first();

        if ($application === null) {
            return response()->json(['success' => false, 'message' => 'Not found'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $application,
        ]);
    }
}
