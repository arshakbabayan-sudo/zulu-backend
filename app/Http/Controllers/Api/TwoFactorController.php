<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Security\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class TwoFactorController extends Controller
{
    public function __construct(
        private TwoFactorService $service,
    ) {}

    /** GET /api/account/2fa/status */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['enabled' => $this->service->isEnabled($request->user())],
        ]);
    }

    /** POST /api/account/2fa/setup */
    public function setup(Request $request): JsonResponse
    {
        try {
            $payload = $this->service->setup($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $payload], 201);
    }

    /** POST /api/account/2fa/confirm */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        try {
            $ok = $this->service->confirm($request->user(), $validated['code']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if (! $ok) {
            return response()->json(['success' => false, 'message' => 'Invalid code'], 422);
        }

        return response()->json(['success' => true, 'data' => ['enabled' => true]]);
    }

    /** POST /api/account/2fa/verify (used during login challenge) */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $ok = $this->service->verify($request->user(), $validated['code']);

        return response()->json([
            'success' => $ok,
            'data' => ['verified' => $ok],
        ], $ok ? 200 : 422);
    }

    /** POST /api/account/2fa/disable */
    public function disable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $ok = $this->service->disable($request->user(), $validated['password']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => $ok, 'data' => ['disabled' => $ok]]);
    }

    /** POST /api/account/2fa/recovery-codes/regenerate */
    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        try {
            $codes = $this->service->regenerateRecoveryCodes($request->user());
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['recovery_codes' => $codes],
        ]);
    }
}
