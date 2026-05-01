<?php

namespace App\Http\Controllers;

use App\Models\Voucher;
use App\Models\VoucherVerificationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\View\View;

class VoucherVerifyController extends Controller
{
    /**
     * Public voucher verification endpoint hit by suppliers scanning a QR code.
     *
     * Renders an HTML status page (in voucher.language) and logs every scan
     * to voucher_verification_logs for audit + fraud detection.
     */
    public function show(Request $request, string $token): View
    {
        $voucher = Voucher::query()
            ->where('qr_token', $token)
            ->first();

        if ($voucher === null) {
            $this->logScan($request, null, 'invalid');

            return view('vouchers.verify-result', [
                'voucher' => null,
                'result' => 'invalid',
                'language' => $this->resolveLanguage($request),
            ]);
        }

        $result = $this->resolveResult($voucher);

        $voucher->verification_count = (int) $voucher->verification_count + 1;
        $voucher->save();

        $this->logScan($request, $voucher, $result);

        $language = in_array($voucher->language, Voucher::LANGUAGES, true)
            ? $voucher->language
            : $this->resolveLanguage($request);

        App::setLocale($language);

        return view('vouchers.verify-result', [
            'voucher' => $voucher,
            'result' => $result,
            'language' => $language,
        ]);
    }

    private function resolveResult(Voucher $voucher): string
    {
        if ($voucher->status === 'void' || $voucher->status === 'reissued') {
            return 'void';
        }

        if ($voucher->status === 'used') {
            return 'used';
        }

        if ($voucher->isExpired()) {
            return 'expired';
        }

        return 'valid';
    }

    private function logScan(Request $request, ?Voucher $voucher, string $result): void
    {
        if ($voucher === null) {
            return; // we don't log invalid token attempts to keep the table FK-clean;
            // future fraud-detection step (Phase 2) can add a separate table.
        }

        VoucherVerificationLog::query()->create([
            'voucher_id' => $voucher->id,
            'scanned_at' => now(),
            'ip' => substr((string) $request->ip(), 0, 45),
            'user_agent' => $request->userAgent() !== null
                ? substr((string) $request->userAgent(), 0, 500)
                : null,
            'location' => null, // GeoIP enrichment deferred
            'result' => $result,
        ]);
    }

    private function resolveLanguage(Request $request): string
    {
        $accepted = strtolower((string) $request->getPreferredLanguage(['en', 'hy', 'ru']));

        return in_array($accepted, ['en', 'hy', 'ru'], true) ? $accepted : 'en';
    }
}
