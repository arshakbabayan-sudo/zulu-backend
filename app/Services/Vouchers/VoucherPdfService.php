<?php

namespace App\Services\Vouchers;

use App\Models\Voucher;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;

class VoucherPdfService
{
    public const STORAGE_DIR = 'vouchers';

    /**
     * Render the voucher PDF, store it on the local disk, and update
     * voucher.pdf_url with the relative storage path.
     *
     * Returns the storage-relative path (e.g. "vouchers/{uuid}.pdf").
     */
    public function render(Voucher $voucher): string
    {
        $previousLocale = App::getLocale();
        App::setLocale($voucher->language);

        try {
            $pdf = Pdf::loadView('vouchers.voucher', [
                'voucher' => $voucher,
                'verifyUrl' => $this->verifyUrl($voucher),
            ])->setPaper('a4');

            $relativePath = self::STORAGE_DIR.'/'.$voucher->id.'.pdf';
            Storage::disk('local')->put($relativePath, $pdf->output());

            $voucher->pdf_url = $relativePath;
            $voucher->save();

            return $relativePath;
        } finally {
            App::setLocale($previousLocale);
        }
    }

    /**
     * Public verification URL embedded in the voucher (becomes the QR target).
     */
    public function verifyUrl(Voucher $voucher): string
    {
        $base = rtrim((string) config('app.url'), '/');

        return $base.'/verify/'.$voucher->qr_token;
    }
}
