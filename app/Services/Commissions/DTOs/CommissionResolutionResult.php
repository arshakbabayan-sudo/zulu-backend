<?php

declare(strict_types=1);

namespace App\Services\Commissions\DTOs;

use App\Models\CommissionRule;

readonly class CommissionResolutionResult
{
    /**
     * @param  list<CommissionRule>  $candidateRules
     */
    public function __construct(
        public ?CommissionRule $chosenRule,
        public array $candidateRules,
        public string $reason,
        public string $level,
    ) {}
}
