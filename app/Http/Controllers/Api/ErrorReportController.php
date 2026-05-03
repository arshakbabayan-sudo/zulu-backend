<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ErrorReporting\ErrorReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public error-report endpoint (Sprint 67, PART 31).
 *
 * Frontend ErrorBoundary POSTs here when it catches an uncaught error.
 * Throttled and validated; payload is persisted as audit_logs.category=error.
 */
class ErrorReportController extends Controller
{
    public function __construct(
        private ErrorReportService $service,
    ) {}

    /**
     * POST /api/errors/report
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'stack' => ['sometimes', 'nullable', 'string', 'max:8000'],
            'url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'route' => ['sometimes', 'nullable', 'string', 'max:200'],
            'component' => ['sometimes', 'nullable', 'string', 'max:200'],
            'severity' => ['sometimes', 'nullable', 'string', 'in:info,warning,error,critical'],
        ]);

        $this->service->captureFrontend($validated, $request);

        return response()->json(['success' => true, 'data' => ['captured' => true]]);
    }
}
