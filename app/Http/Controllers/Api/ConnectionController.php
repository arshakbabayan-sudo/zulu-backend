<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceConnection;
use App\Services\Connections\ConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ConnectionController extends Controller
{
    public function __construct(
        private ConnectionService $connectionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['source_type', 'target_type', 'status', 'company_id']);
        $connections = $this->connectionService->list($filters);

        $page = max(1, (int) $request->integer('page', 1));
        $perPage = max(1, (int) $request->integer('per_page', 15));
        $total = $connections->count();
        $lastPage = max(1, (int) ceil($total / $perPage));

        $data = $connections
            ->forPage($page, $perPage)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', 'string', Rule::in(ServiceConnection::SOURCE_TYPES)],
            'source_id' => ['required', 'integer'],
            'target_type' => [
                'required',
                'string',
                Rule::in(ServiceConnection::TARGET_TYPES),
                'different:source_type',
            ],
            'target_id' => ['required', 'integer'],
            'connection_type' => ['required', 'string', Rule::in(['only', 'both'])],
            'client_targeting' => ['sometimes', 'string', Rule::in(ServiceConnection::CLIENT_TARGETING)],
            'notes' => ['nullable', 'string'],
        ]);

        if (! array_key_exists('client_targeting', $validated)) {
            $validated['client_targeting'] = 'all';
        }

        $connection = $this->connectionService->create($validated, $request->user());

        return response()->json([
            'success' => true,
            'data' => $connection,
        ], 201);
    }

    public function show(Request $request, ServiceConnection $connection): JsonResponse
    {
        $connection->load('company');

        return response()->json([
            'success' => true,
            'data' => $connection,
        ]);
    }

    public function accept(Request $request, ServiceConnection $connection): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->connectionService->accept(
                $connection,
                $request->user(),
                $validated['notes'] ?? null
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    public function reject(Request $request, ServiceConnection $connection): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $updated = $this->connectionService->reject(
                $connection,
                $request->user(),
                $validated['notes'] ?? null
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }

    public function cancel(Request $request, ServiceConnection $connection): JsonResponse
    {
        try {
            $updated = $this->connectionService->cancel($connection, $request->user());
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $updated,
        ]);
    }
}
