<?php

namespace App\Services\Contracts;

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\ContractVersion;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class ContractService
{
    public function __construct(
        private ?AuditService $auditService = null,
    ) {}

    private function audit(): AuditService
    {
        return $this->auditService ?? app(AuditService::class);
    }

    /**
     * Generate a new Contract from a template, substituting variables and
     * snapshotting v1 of the document.
     *
     * Status starts at 'draft'. Called by ZULU Admin (for Platform Contracts)
     * or by a Seller (for Partner Agreements).
     *
     * @param  array<string, mixed>  $payload  variables, commission_clause,
     *                                         payment_terms, cancellation_policy,
     *                                         effective_date, expiry_date, language,
     *                                         auto_renew, termination_notice_days
     */
    public function generateFromTemplate(
        ContractTemplate $template,
        ?Company $partyA,
        Company $partyB,
        User $createdBy,
        array $payload = []
    ): Contract {
        if ($template->type === 'platform' && $partyA !== null) {
            throw new InvalidArgumentException('Platform contracts must have NULL party_a (ZULU side).');
        }

        if (str_starts_with($template->type, 'partner') && $partyA === null) {
            throw new InvalidArgumentException('Partner agreements require both party_a and party_b.');
        }

        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        $rendered = $this->renderBody($template, $variables);

        $language = $payload['language'] ?? $template->language;
        if (! in_array($language, Contract::LANGUAGES, true)) {
            $language = 'en';
        }

        $contractType = $template->type === 'platform' ? 'platform' : 'partner';

        return DB::transaction(function () use (
            $template,
            $partyA,
            $partyB,
            $createdBy,
            $payload,
            $rendered,
            $variables,
            $language,
            $contractType
        ): Contract {
            $contract = Contract::query()->create([
                'contract_number' => $this->generateContractNumber($contractType),
                'type' => $contractType,
                'party_a_company_id' => $partyA?->id,
                'party_b_company_id' => $partyB->id,
                'template_id' => $template->id,
                'status' => 'draft',
                'commission_clause' => is_array($payload['commission_clause'] ?? null) ? $payload['commission_clause'] : [],
                'payment_terms' => is_array($payload['payment_terms'] ?? null) ? $payload['payment_terms'] : [],
                'cancellation_policy' => $payload['cancellation_policy'] ?? null,
                'rendered_body' => [
                    'html' => $rendered,
                    'variables' => $variables,
                    'template_id' => $template->id,
                    'template_version' => $template->version,
                ],
                'effective_date' => $payload['effective_date'] ?? null,
                'expiry_date' => $payload['expiry_date'] ?? null,
                'auto_renew' => (bool) ($payload['auto_renew'] ?? true),
                'termination_notice_days' => (int) ($payload['termination_notice_days'] ?? 30),
                'created_by_user_id' => $createdBy->id,
                'language' => $language,
            ]);

            $this->snapshotVersion($contract, $createdBy, 1);

            return $contract->fresh();
        });
    }

    /**
     * Move a draft contract to 'sent' state — sent to the counter-party
     * for review/signature.
     */
    public function send(Contract $contract, User $sender): Contract
    {
        $this->assertTransition($contract->status, 'sent');

        $previousStatus = $contract->status;
        $contract->status = 'sent';
        $contract->save();

        $this->snapshotVersion($contract, $sender);

        $this->audit()->log([
            'category' => 'contract',
            'actor' => $sender,
            'subject_type' => 'Contract',
            'subject_id' => (string) $contract->id,
            'action' => 'sent',
            'changes' => ['status' => ['from' => $previousStatus, 'to' => 'sent']],
            'context' => ['contract_number' => $contract->contract_number],
        ]);

        return $contract->fresh();
    }

    /**
     * Apply Party A's signature. For Platform Contracts party_a is ZULU
     * and represents counter-signature — this typically follows party_b
     * having signed first.
     */
    public function signAsPartyA(Contract $contract, User $signer, string $ip): Contract
    {
        return $this->applySignature($contract, $signer, $ip, 'a');
    }

    /**
     * Apply Party B's signature.
     */
    public function signAsPartyB(Contract $contract, User $signer, string $ip): Contract
    {
        return $this->applySignature($contract, $signer, $ip, 'b');
    }

    /**
     * Terminate a contract. Captures reason + actor in audit version.
     */
    public function terminate(Contract $contract, string $reason, User $actor): Contract
    {
        if (in_array($contract->status, ['terminated', 'expired'], true)) {
            throw new RuntimeException("Contract is already {$contract->status}.");
        }

        $previousStatus = $contract->status;
        $contract->status = 'terminated';
        $contract->termination_reason = $reason;
        $contract->terminated_at = now();
        $contract->save();

        $this->snapshotVersion($contract, $actor);

        $this->audit()->log([
            'category' => 'contract',
            'actor' => $actor,
            'subject_type' => 'Contract',
            'subject_id' => (string) $contract->id,
            'action' => 'terminated',
            'changes' => ['status' => ['from' => $previousStatus, 'to' => 'terminated']],
            'context' => ['contract_number' => $contract->contract_number, 'reason' => $reason],
        ]);

        return $contract->fresh();
    }

    /**
     * Variable substitution: {{key}} placeholders → variables[key] values.
     * Unmatched placeholders are kept literal (caller can grep them out).
     *
     * @param  array<string, mixed>  $variables
     */
    public function renderBody(ContractTemplate $template, array $variables): string
    {
        $body = (string) $template->body;

        foreach ($variables as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }
            $body = str_replace('{{'.$key.'}}', (string) $value, $body);
        }

        return $body;
    }

    private function applySignature(Contract $contract, User $signer, string $ip, string $party): Contract
    {
        if (! in_array($party, ['a', 'b'], true)) {
            throw new InvalidArgumentException("party must be 'a' or 'b', got '{$party}'.");
        }

        if (in_array($contract->status, ['terminated', 'expired', 'disputed'], true)) {
            throw new RuntimeException("Cannot sign contract in status '{$contract->status}'.");
        }

        $signatures = is_array($contract->signatures) ? $contract->signatures : [];
        $signatures[] = [
            'party' => $party,
            'user_id' => $signer->id,
            'ip' => substr($ip, 0, 45),
            'timestamp' => now()->toIso8601String(),
        ];
        $contract->signatures = $signatures;

        $hasA = collect($signatures)->contains(fn ($s) => ($s['party'] ?? null) === 'a');
        $hasB = collect($signatures)->contains(fn ($s) => ($s['party'] ?? null) === 'b');

        if ($hasA && $hasB) {
            $contract->status = 'countersigned';
            if ($contract->effective_date === null || ! $contract->effective_date->isFuture()) {
                $contract->status = 'active';
            }
        } else {
            $contract->status = $party === 'a' ? 'signed_by_a' : 'signed_by_b';
        }

        $previousStatus = $contract->getOriginal('status');
        $contract->save();

        $this->snapshotVersion($contract, $signer);

        $this->audit()->log([
            'category' => 'contract',
            'actor' => $signer,
            'subject_type' => 'Contract',
            'subject_id' => (string) $contract->id,
            'action' => 'signed',
            'changes' => ['status' => ['from' => $previousStatus, 'to' => $contract->status]],
            'context' => [
                'contract_number' => $contract->contract_number,
                'party' => $party,
                'signature_count' => count($signatures),
            ],
        ]);

        return $contract->fresh();
    }

    private function snapshotVersion(Contract $contract, User $actor, ?int $forceVersion = null): ContractVersion
    {
        $version = $forceVersion ?? $this->nextVersionNumber($contract);

        return ContractVersion::query()->create([
            'contract_id' => $contract->id,
            'version_number' => $version,
            'snapshot' => $contract->fresh()->toArray(),
            'changed_by_user_id' => $actor->id,
            'changed_at' => now(),
        ]);
    }

    private function nextVersionNumber(Contract $contract): int
    {
        return ContractVersion::query()
            ->where('contract_id', $contract->id)
            ->max('version_number') + 1;
    }

    private function generateContractNumber(string $type): string
    {
        $prefix = $type === 'platform' ? 'CT-PLAT' : 'CT-PART';
        $year = now()->format('Y');
        $rand = strtoupper(bin2hex(random_bytes(3)));

        return "{$prefix}-{$year}-{$rand}";
    }

    private function assertTransition(string $current, string $target): void
    {
        $allowed = [
            'draft' => ['sent', 'terminated'],
            'sent' => ['signed_by_a', 'signed_by_b', 'terminated'],
            'signed_by_a' => ['signed_by_b', 'countersigned', 'terminated'],
            'signed_by_b' => ['signed_by_a', 'countersigned', 'terminated'],
            'countersigned' => ['active', 'terminated'],
            'active' => ['expired', 'terminated', 'disputed'],
            'expired' => [],
            'terminated' => [],
            'disputed' => ['active', 'terminated'],
        ];

        $allowedFrom = $allowed[$current] ?? [];
        if (! in_array($target, $allowedFrom, true)) {
            throw new RuntimeException("Cannot transition contract from '{$current}' to '{$target}'.");
        }
    }
}
