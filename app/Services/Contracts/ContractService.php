<?php

namespace App\Services\Contracts;

use App\Models\Company;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Carbon;

class ContractService
{
    /**
     * Generate a PDF contract for a given company.
     *
     * @param Company $company
     * @return string The relative path to the generated PDF
     */
    public function generateForCompany(Company $company): string
    {
        $data = [
            'company' => $company,
            'date' => Carbon::now()->format('d.m.Y'),
        ];

        $pdf = Pdf::loadView('pdf.contract', $data);
        
        $fileName = 'contracts/contract_' . $company->id . '_' . time() . '.pdf';
        
        // Ensure the directory exists in storage
        Storage::disk('public')->makeDirectory('contracts');
        
        // Save the PDF to public storage
        Storage::disk('public')->put($fileName, $pdf->output());

        return $fileName;
    }

    /**
     * Get the full URL of the contract.
     */
    public function getContractUrl(string $path): string
    {
        return Storage::disk('public')->url($path);
    }
}
