<?php

declare(strict_types=1);

namespace App\Services\Commissions;

use App\Models\CommissionRule;
use App\Services\Commissions\DTOs\CommissionResolutionContext;
use App\Services\Commissions\DTOs\CommissionResolutionResult;
use Illuminate\Support\Collection;

class CommissionRuleResolver
{
    public function resolve(CommissionResolutionContext $ctx): CommissionResolutionResult
    {
        /** @var list<array{0: string, 1: int|null}> $priorityOrder */
        $priorityOrder = [
            ['partner_agreement', $ctx->partnerAgreementId],
            ['seller', $ctx->sellerId],
            ['category', $ctx->categoryId],
            ['global', null],
        ];

        foreach ($priorityOrder as [$level, $scopeId]) {
            if ($level !== 'global' && $scopeId === null) {
                continue;
            }

            /** @var Collection<int, CommissionRule> $candidates */
            $candidates = CommissionRule::query()
                ->active()
                ->effectiveAt($ctx->atTime)
                ->forLevel($level)
                ->forScope($scopeId)
                ->matchingServiceType($ctx->serviceType)
                ->get();

            if ($candidates->isEmpty()) {
                continue;
            }

            /** @var Collection<int, CommissionRule> $orderedCandidates */
            $orderedCandidates = $candidates
                ->sort(function (CommissionRule $left, CommissionRule $right): int {
                    $leftIsWildcard = $left->service_type === null ? 1 : 0;
                    $rightIsWildcard = $right->service_type === null ? 1 : 0;

                    if ($leftIsWildcard !== $rightIsWildcard) {
                        return $leftIsWildcard <=> $rightIsWildcard;
                    }

                    return $right->effective_from->getTimestamp() <=> $left->effective_from->getTimestamp();
                })
                ->values();

            $chosen = $orderedCandidates->first();

            return new CommissionResolutionResult(
                chosenRule: $chosen,
                candidateRules: $orderedCandidates->all(),
                reason: 'matched at level='.$level.($scopeId !== null ? ', scope_id='.$scopeId : ''),
                level: $level,
            );
        }

        return new CommissionResolutionResult(
            chosenRule: null,
            candidateRules: [],
            reason: 'no matching rule at any level',
            level: 'none',
        );
    }
}
