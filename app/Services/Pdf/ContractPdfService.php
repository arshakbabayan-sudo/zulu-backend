<?php

namespace App\Services\Pdf;

use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Response;

class ContractPdfService
{
    /**
     * @param  list<string>  $serviceTypes
     */
    public function generate(Company $company, array $serviceTypes = []): Response
    {
        $agreementDate = Carbon::now();
        $effectiveFrom = Carbon::now();
        $expiresAt = Carbon::now()->addMonths(6);
        $documentId = 'CONTRACT-'.$company->id.'-'.$agreementDate->timestamp;

        $pdf = Pdf::loadView('pdf.contract', [
            'company' => $company,
            'serviceTypes' => $serviceTypes,
            'agreementDate' => $agreementDate,
            'effectiveFrom' => $effectiveFrom,
            'expiresAt' => $expiresAt,
            'documentId' => $documentId,
        ])
            ->setPaper('a4', 'portrait');

        return $pdf->download('contract-'.$company->id.'.pdf');
    }
}
