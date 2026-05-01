<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VoucherIssuedMail;
use App\Models\Voucher;
use App\Services\Vouchers\VoucherPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerVoucherController extends Controller
{
    /**
     * GET /api/customer/vouchers
     * Returns the authenticated user's vouchers, ordered newest-first.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $vouchers = Voucher::query()
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotIn('status', ['reissued']) // hide superseded ones
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Voucher $v): array => [
                'id' => $v->id,
                'voucher_number' => $v->voucher_number,
                'service_type' => $v->service_type,
                'status' => $v->status,
                'holder_name' => $v->holder_name,
                'valid_from' => $v->valid_from?->toIso8601String(),
                'valid_to' => $v->valid_to?->toIso8601String(),
                'language' => $v->language,
                'has_pdf' => $v->pdf_url !== null,
                'created_at' => $v->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'data' => $vouchers,
        ]);
    }

    /**
     * GET /api/customer/vouchers/{voucher}/download
     * Streams the voucher PDF file for download. 404 if PDF missing or not owner.
     */
    public function download(Request $request, string $voucher): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        $model = Voucher::query()
            ->whereKey($voucher)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Voucher not found'], 404);
        }

        $disk = Storage::disk('local');

        // Lazy regenerate if PDF is missing on disk (e.g. file lost or never rendered).
        if ($model->pdf_url === null || ! $disk->exists($model->pdf_url)) {
            try {
                app(VoucherPdfService::class)->render($model);
                $model->refresh();
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => 'PDF unavailable'], 503);
            }
        }

        return $disk->download($model->pdf_url, $model->voucher_number.'.pdf', [
            'Content-Type' => 'application/pdf',
        ]);
    }

    /**
     * POST /api/customer/vouchers/{voucher}/resend
     * Re-sends the voucher email to the authenticated user's address.
     */
    public function resend(Request $request, string $voucher): JsonResponse
    {
        $user = $request->user();

        $model = Voucher::query()
            ->whereKey($voucher)
            ->whereHas('order', fn ($q) => $q->where('user_id', $user->id))
            ->first();

        if ($model === null) {
            return response()->json(['success' => false, 'message' => 'Voucher not found'], 404);
        }

        if (! is_string($user->email) || $user->email === '') {
            return response()->json(['success' => false, 'message' => 'No email on file'], 422);
        }

        Mail::to($user->email)->send(new VoucherIssuedMail($model));

        return response()->json(['success' => true, 'message' => 'Voucher re-sent']);
    }
}
