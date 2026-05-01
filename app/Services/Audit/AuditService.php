<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AuditService
{
    /**
     * Record an audit log entry.
     *
     * Tamper-evidence: each row stores SHA-256 hash of its own canonical content
     * + the hash of the previous log entry. Verification job (TBD) walks the
     * chain and detects mutations.
     *
     * @param  array<string, mixed>  $payload
     *                                         Required: category, subject_type, subject_id, action.
     *                                         Optional: actor (User|null), changes, context, request, actor_type override.
     */
    public function log(array $payload): ?AuditLog
    {
        try {
            return $this->writeRow($payload);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed', [
                'category' => $payload['category'] ?? null,
                'subject_type' => $payload['subject_type'] ?? null,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if (app()->environment('testing')) {
                throw $e;
            }

            return null;
        }
    }

    /**
     * Convenience: capture a Model's dirty changes (useful inside Eloquent
     * observers or before/after a service mutation).
     */
    public function logModelChange(
        string $category,
        string $action,
        Model $model,
        ?User $actor = null,
        ?array $context = null,
        ?Request $request = null,
    ): ?AuditLog {
        $changes = null;
        if ($action === 'updated' || $action === 'restored') {
            $changes = [
                'before' => $model->getOriginal(),
                'after' => $model->getAttributes(),
                'dirty_keys' => array_keys($model->getDirty()),
            ];
        } elseif ($action === 'created') {
            $changes = ['after' => $model->getAttributes()];
        } elseif ($action === 'deleted') {
            $changes = ['before' => $model->getOriginal()];
        }

        return $this->log([
            'category' => $category,
            'subject_type' => class_basename($model),
            'subject_id' => (string) ($model->getKey() ?? ''),
            'action' => $action,
            'actor' => $actor,
            'changes' => $changes,
            'context' => $context,
            'request' => $request,
        ]);
    }

    private function writeRow(array $payload): AuditLog
    {
        $actor = $payload['actor'] ?? null;
        $request = $payload['request'] ?? request();

        $row = [
            'category' => (string) ($payload['category'] ?? 'data_change'),
            'actor_type' => $payload['actor_type'] ?? ($actor instanceof User ? 'user' : 'system'),
            'actor_id' => $actor instanceof User ? (int) $actor->id : null,
            'actor_name_snapshot' => $actor instanceof User
                ? (string) ($actor->name ?? $actor->email ?? 'unknown')
                : 'system',
            'subject_type' => (string) ($payload['subject_type'] ?? ''),
            'subject_id' => (string) ($payload['subject_id'] ?? ''),
            'action' => (string) ($payload['action'] ?? ''),
            'changes' => $payload['changes'] ?? null,
            'context' => $payload['context'] ?? null,
            'ip_address' => $request instanceof Request ? substr((string) $request->ip(), 0, 45) : null,
            'user_agent' => $request instanceof Request && $request->userAgent() !== null
                ? substr((string) $request->userAgent(), 0, 500)
                : null,
            'session_id' => $request instanceof Request && $request->hasSession()
                ? substr((string) $request->session()->getId(), 0, 191)
                : null,
            'request_id' => $request instanceof Request
                ? substr((string) $request->headers->get('X-Request-Id', ''), 0, 64) ?: null
                : null,
            'created_at' => now(),
        ];

        $previousHash = AuditLog::query()->latest('created_at')->value('hash');
        $row['previous_log_hash'] = $previousHash;
        $row['hash'] = $this->computeHash($row, $previousHash);

        return AuditLog::query()->create($row);
    }

    public function computeHash(array $row, ?string $previousHash): string
    {
        $createdAt = $row['created_at'] ?? null;
        if ($createdAt instanceof \DateTimeInterface) {
            $createdAtIso = $createdAt->format(\DateTimeInterface::ATOM);
        } elseif (is_string($createdAt) && $createdAt !== '') {
            $createdAtIso = Carbon::parse($createdAt)->toIso8601String();
        } else {
            $createdAtIso = null;
        }

        $canonical = json_encode([
            'category' => $row['category'] ?? null,
            'actor_type' => $row['actor_type'] ?? null,
            'actor_id' => $row['actor_id'] ?? null,
            'subject_type' => $row['subject_type'] ?? null,
            'subject_id' => $row['subject_id'] ?? null,
            'action' => $row['action'] ?? null,
            'changes' => $row['changes'] ?? null,
            'context' => $row['context'] ?? null,
            'created_at' => $createdAtIso,
            'previous' => $previousHash,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $canonical);
    }

    /**
     * Walk the chain from oldest to newest and verify each row's hash.
     * Returns array of corrupted log IDs (empty if all good).
     *
     * @return array<int, string>
     */
    public function verifyIntegrity(int $limit = 1000): array
    {
        $corrupted = [];
        $previousHash = null;

        AuditLog::query()
            ->orderBy('created_at')
            ->limit($limit)
            ->get()
            ->each(function (AuditLog $log) use (&$corrupted, &$previousHash): void {
                $expected = $this->computeHash($log->toArray(), $previousHash);
                if ($expected !== $log->hash || $log->previous_log_hash !== $previousHash) {
                    $corrupted[] = (string) $log->id;
                }
                $previousHash = $log->hash;
            });

        return $corrupted;
    }
}
