<?php

namespace App\Services\Finance;

use App\Services\Commissions\CommissionRuleResolver;
use App\Services\Commissions\DTOs\CommissionResolutionContext;

class CommissionManagementService
{
    public function __construct(
        private CommissionRuleResolver $resolver,
    ) {}

    /**
     * @return array{client_price:float,commission_amount:float}
     */
    public function applyCommission(float $netPrice, int $companyId, string $serviceType): array
    {
        $ctx = CommissionResolutionContext::make(
            sellerId: $companyId,
            serviceType: $serviceType,
            opts: [
                'atTime' => now(),
                'categoryId' => null,
                'partnerAgreementId' => null,
            ]
        );
        $rule = $this->resolver->resolve($ctx)->chosenRule;

        if ($rule === null) {
            return [
                'client_price' => round($netPrice, 2),
                'commission_amount' => 0.0,
            ];
        }

        $baseAmount = bcadd((string) $netPrice, '0', 2);
        if ($rule->type === 'percentage') {
            $commission = bcdiv(bcmul($baseAmount, (string) $rule->percentage_value, 8), '100', 4);
        } elseif ($rule->type === 'fixed') {
            $commission = bcadd((string) $rule->fixed_value, '0', 4);
        } else {
            $commission = bcadd(
                bcdiv(bcmul($baseAmount, (string) $rule->percentage_value, 8), '100', 4),
                (string) $rule->fixed_value,
                4
            );
        }

        $commissionAmount = (float) $commission;

        return [
            'client_price' => round($netPrice + $commissionAmount, 2),
            'commission_amount' => round(max($commissionAmount, 0.0), 2),
        ];
    }
}
