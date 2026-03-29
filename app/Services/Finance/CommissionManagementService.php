<?php

namespace App\Services\Finance;

use App\Models\Commission;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;

class CommissionManagementService
{
    public function setServiceCommission(
        int $companyId,
        string $serviceType,
        float $value,
        string $type = Commission::TYPE_PERCENTAGE
    ): Commission {
        $clean = $this->validatePayload($companyId, $serviceType, $value, $type);

        return Commission::query()->updateOrCreate(
            [
                'company_id' => $clean['company_id'],
                'service_type' => $clean['service_type'],
            ],
            [
                'commission_type' => $clean['commission_type'],
                'value' => $clean['value'],
                'is_active' => true,
            ]
        );
    }

    public function getCommissionForService(int $companyId, string $serviceType): float
    {
        $commission = Commission::query()
            ->where('company_id', $companyId)
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->first();

        if ($commission === null) {
            return 0.0;
        }

        return round((float) $commission->value, 2);
    }

    /**
     * @return array{client_price:float,commission_amount:float}
     */
    public function applyCommission(float $netPrice, int $companyId, string $serviceType): array
    {
        $commission = Commission::query()
            ->where('company_id', $companyId)
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->first();

        if ($commission === null) {
            return [
                'client_price' => round($netPrice, 2),
                'commission_amount' => 0.0,
            ];
        }

        $value = (float) $commission->value;
        $commissionAmount = $commission->commission_type === Commission::TYPE_FIXED
            ? $value
            : (($netPrice * $value) / 100);

        $commissionAmount = round(max($commissionAmount, 0.0), 2);

        return [
            'client_price' => round($netPrice + $commissionAmount, 2),
            'commission_amount' => $commissionAmount,
        ];
    }

    /**
     * @return array{company_id:int,service_type:string,commission_type:string,value:float}
     */
    private function validatePayload(int $companyId, string $serviceType, float $value, string $type): array
    {
        $validator = Validator::make([
            'company_id' => $companyId,
            'service_type' => $serviceType,
            'commission_type' => $type,
            'value' => $value,
        ], [
            'company_id' => ['required', 'integer', 'min:1', 'exists:companies,id'],
            'service_type' => ['required', 'string', Rule::in(Commission::SERVICE_TYPES)],
            'commission_type' => ['required', 'string', Rule::in([Commission::TYPE_PERCENTAGE, Commission::TYPE_FIXED])],
            'value' => ['required', 'numeric', 'min:0'],
        ]);

        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        /** @var array{company_id:int,service_type:string,commission_type:string,value:float} $validated */
        $validated = $validator->validated();

        return $validated;
    }
}
