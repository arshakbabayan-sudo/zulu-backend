<?php

namespace App\Services\ErrorReporting;

use App\Models\AuditLog;
use App\Services\Audit\AuditService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Self-hosted error capture (Sprint 67, PART 31).
 *
 * Persists fatal exceptions and frontend-reported errors as audit_logs
 * entries with category='error'. The admin error viewer reads from the
 * same table via category filter.
 */
class ErrorReportService
{
    public function __construct(
        private AuditService $auditService,
    ) {}

    /**
     * Capture a backend exception. Called from the bootstrap reportable hook.
     */
    public function captureException(Throwable $e, ?Request $request = null): void
    {
        try {
            $severity = $this->severityFor($e);

            $context = [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $this->trimTrace($e),
                'severity' => $severity,
                'source' => 'backend',
            ];

            if ($request !== null) {
                $context['url'] = $request->fullUrl();
                $context['method'] = $request->method();
                $context['route'] = optional($request->route())->getName();
            }

            $actorId = $request?->user()?->id;

            $this->auditService->log([
                'category' => 'error',
                'actor_type' => $actorId ? 'user' : 'system',
                'actor_id' => $actorId,
                'actor_name_snapshot' => $actorId ? ($request?->user()?->name ?? null) : 'exception_handler',
                'subject_type' => 'exception',
                'subject_id' => substr(get_class($e), -64),
                'action' => 'error.captured',
                'context' => $context,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'session_id' => $request?->hasSession() ? $request->session()->getId() : null,
                'request_id' => $request?->headers->get('X-Request-ID'),
            ]);
        } catch (Throwable $inner) {
            // Never let error reporting itself raise.
            logger()->warning('ErrorReportService capture failed', [
                'inner' => $inner->getMessage(),
                'original' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Capture a frontend-reported error (POST /api/errors/report).
     *
     * @param  array<string,mixed>  $payload
     */
    public function captureFrontend(array $payload, ?Request $request = null): void
    {
        try {
            $severity = $payload['severity'] ?? 'error';
            if (! in_array($severity, AuditLog::ERROR_SEVERITIES, true)) {
                $severity = 'error';
            }

            $context = [
                'message' => (string) ($payload['message'] ?? 'Unknown frontend error'),
                'stack' => isset($payload['stack']) ? substr((string) $payload['stack'], 0, 8000) : null,
                'url' => isset($payload['url']) ? (string) $payload['url'] : null,
                'route' => isset($payload['route']) ? (string) $payload['route'] : null,
                'component' => isset($payload['component']) ? (string) $payload['component'] : null,
                'severity' => $severity,
                'source' => 'frontend',
                'browser' => $request?->userAgent(),
            ];

            $actorId = $request?->user()?->id;

            $this->auditService->log([
                'category' => 'error',
                'actor_type' => $actorId ? 'user' : 'system',
                'actor_id' => $actorId,
                'actor_name_snapshot' => $actorId ? ($request?->user()?->name ?? null) : 'frontend',
                'subject_type' => 'frontend_error',
                'subject_id' => substr((string) ($payload['component'] ?? 'unknown'), 0, 64),
                'action' => 'error.frontend_reported',
                'context' => $context,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
                'request_id' => $request?->headers->get('X-Request-ID'),
            ]);
        } catch (Throwable $inner) {
            logger()->warning('ErrorReportService frontend capture failed', [
                'inner' => $inner->getMessage(),
            ]);
        }
    }

    private function severityFor(Throwable $e): string
    {
        // Validation / not-found / auth shouldn't reach here, but guard anyway.
        if ($e instanceof ValidationException) {
            return 'info';
        }
        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            if ($status >= 400 && $status < 500) {
                return 'warning';
            }
        }
        if ($e instanceof \Error) {
            return 'critical';
        }

        return 'error';
    }

    /**
     * Limit trace size so audit_log rows stay reasonable.
     */
    private function trimTrace(Throwable $e): string
    {
        $trace = $e->getTraceAsString();

        return strlen($trace) > 8000 ? substr($trace, 0, 8000)."\n…[truncated]" : $trace;
    }
}
