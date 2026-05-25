<?php

namespace App\Services\Vouchers;

use App\Mail\VoucherIssuedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationService;
use App\Services\Webhooks\WebhookService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class VoucherService
{
    public function __construct(
        private ?VoucherPdfService $pdfService = null,
        private ?NotificationService $notificationService = null,
        private ?AuditService $auditService = null,
        private ?WebhookService $webhookService = null,
    ) {}

    private function audit(): AuditService
    {
        return $this->auditService ?? app(AuditService::class);
    }

    private function webhook(): WebhookService
    {
        return $this->webhookService ?? app(WebhookService::class);
    }

    /**
     * Issue vouchers for every top-level (non-child) OrderItem in the given Order.
     *
     * Idempotent: if a voucher already exists for an OrderItem, the existing one is
     * kept and returned without modification. New vouchers are created for items that
     * don't yet have one.
     *
     * Voucher numbering: V-{first 8 hex chars of order UUID, uppercase}-{3-digit seq}
     * QR token: 64-char hex (256-bit entropy from random_bytes), unique per voucher.
     *
     * @return Collection<int, Voucher>
     */
    public function issueForOrder(Order $order): Collection
    {
        return DB::transaction(function () use ($order): Collection {
            $order->loadMissing(['items', 'user']);

            $issued = collect();
            $seq = $this->nextSequenceFor($order);

            $language = $this->resolveLanguage($order);
            $orderShort = $this->shortenOrderId($order->id);

            foreach ($order->items as $item) {
                // Skip children of nested package items — voucher is issued at the parent level.
                if ($item->parent_item_id !== null) {
                    continue;
                }

                $existing = Voucher::query()
                    ->where('order_item_id', $item->id)
                    ->whereIn('status', ['issued', 'used'])
                    ->first();

                if ($existing !== null) {
                    $issued->push($existing);

                    continue;
                }

                $voucher = Voucher::query()->create([
                    'voucher_number' => sprintf('V-%s-%03d', $orderShort, $seq),
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'service_type' => $item->item_type,
                    'issuer_company_id' => $order->company_id,
                    'holder_name' => $this->resolveHolderName($order, $item),
                    'holder_passport' => $this->resolvePassport($item),
                    'passenger_data' => $item->passenger_data,
                    'service_snapshot' => is_array($item->service_snapshot)
                        ? $item->service_snapshot
                        : [],
                    'qr_token' => $this->generateQrToken(),
                    'status' => 'issued',
                    'language' => $language,
                    'valid_from' => $item->date_from,
                    'valid_to' => $item->date_to,
                ]);

                $issued->push($voucher);
                $seq++;

                $this->audit()->log([
                    'category' => 'data_change',
                    'subject_type' => 'Voucher',
                    'subject_id' => (string) $voucher->id,
                    'action' => 'issued',
                    'context' => [
                        'voucher_number' => $voucher->voucher_number,
                        'order_id' => $voucher->order_id,
                        'service_type' => $voucher->service_type,
                    ],
                ]);
            }

            return $issued;
        })->each(function (Voucher $voucher) use ($order): void {
            $this->renderPdfSafely($voucher);
            $this->sendIssuedMailSafely($voucher, $order);
            $this->createInAppNotificationSafely($voucher, $order);
            $this->dispatchWebhookSafely($voucher, $order);
        });
    }

    private function createInAppNotificationSafely(Voucher $voucher, Order $order): void
    {
        if ($order->user_id === null) {
            return;
        }

        $service = $this->notificationService ?? app(NotificationService::class);

        try {
            $service->createForEvent([
                'user_id' => (int) $order->user_id,
                'event_type' => 'voucher.issued',
                'title' => 'Voucher issued',
                'message' => 'Your voucher '.$voucher->voucher_number.' is ready.',
                'subject_type' => 'voucher',
                'subject_id' => null,
                'priority' => 'normal',
                'variables' => [
                    'order_number' => (string) ($order->order_number ?? ''),
                    'voucher_number' => $voucher->voucher_number,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Voucher in-app notification failed', [
                'voucher_id' => $voucher->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function dispatchWebhookSafely(Voucher $voucher, Order $order): void
    {
        try {
            $this->webhook()->dispatch('voucher.issued', [
                'voucher_id' => (string) $voucher->id,
                'voucher_number' => $voucher->voucher_number,
                'order_id' => (string) $voucher->order_id,
                'order_number' => $order->order_number,
                'service_type' => $voucher->service_type,
                'company_id' => $order->company_id,
                'issued_at' => $voucher->created_at?->toIso8601String() ?? now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('voucher.issued webhook dispatch failed', [
                'voucher_id' => $voucher->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function renderPdfSafely(Voucher $voucher): void
    {
        if ($voucher->pdf_url !== null) {
            return; // already rendered
        }

        $service = $this->pdfService ?? app(VoucherPdfService::class);

        try {
            $service->render($voucher);
        } catch (\Throwable $e) {
            Log::warning('Voucher PDF render failed', [
                'voucher_id' => $voucher->id,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function sendIssuedMailSafely(Voucher $voucher, Order $order): void
    {
        $email = $order->user?->email;
        if (! is_string($email) || $email === '') {
            return;
        }

        try {
            Mail::to($email)->send(new VoucherIssuedMail($voucher->fresh()));
        } catch (\Throwable $e) {
            Log::warning('Voucher issuance email failed', [
                'voucher_id' => $voucher->id,
                'email' => $email,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reissue a voucher with corrected data (e.g., name correction). The original is
     * marked status='reissued' and the new voucher.reissued_from_id points back to it.
     *
     * @param  array<string, mixed>  $overrides  fillable Voucher fields to override
     */
    /**
     * Finance group v2 — Phase 2e (Item 4.1).
     *
     * Admin-side manual voucher issue. Used for compensation, gifts, and
     * one-off cases outside the normal booking → confirmed → voucher flow.
     *
     * The voucher number gets an `ADM-` prefix to distinguish from
     * order-derived vouchers (e.g. `ADM-2026-0042`).
     *
     * @param  array{
     *     service_type: string,
     *     holder_name: string,
     *     holder_passport?: ?string,
     *     language?: string,
     *     valid_from?: ?string,
     *     valid_to?: ?string,
     *     issuer_company_id?: ?int,
     *     order_id?: ?int,
     *     notes?: ?string,
     *     amount?: ?float,
     *     currency?: ?string,
     *  }  $payload
     */
    public function createManual(array $payload, ?\App\Models\User $issuingAdmin = null): Voucher
    {
        return DB::transaction(function () use ($payload, $issuingAdmin): Voucher {
            $year = now()->format('Y');
            $seq = (int) Voucher::query()
                ->where('voucher_number', 'like', "ADM-{$year}-%")
                ->count() + 1;
            $voucherNumber = sprintf('ADM-%s-%04d', $year, $seq);

            $voucher = Voucher::query()->create([
                'voucher_number' => $voucherNumber,
                'order_id' => $payload['order_id'] ?? null,
                'order_item_id' => null,
                'service_type' => $payload['service_type'],
                'issuer_company_id' => $payload['issuer_company_id'] ?? null,
                'holder_name' => $payload['holder_name'],
                'holder_passport' => $payload['holder_passport'] ?? null,
                'passenger_data' => null,
                'service_snapshot' => [
                    'manual_issue' => true,
                    'issued_by_admin_id' => $issuingAdmin?->id,
                    'notes' => $payload['notes'] ?? null,
                    'amount' => isset($payload['amount']) ? (float) $payload['amount'] : null,
                    'currency' => $payload['currency'] ?? null,
                ],
                'qr_token' => $this->generateQrToken(),
                'status' => 'issued',
                'language' => $payload['language'] ?? 'en',
                'valid_from' => $payload['valid_from'] ?? null,
                'valid_to' => $payload['valid_to'] ?? null,
            ]);

            $this->audit()->log([
                'category' => 'data_change',
                'subject_type' => 'Voucher',
                'subject_id' => (string) $voucher->id,
                'action' => 'manual_issued',
                'context' => [
                    'voucher_number' => $voucher->voucher_number,
                    'service_type' => $voucher->service_type,
                    'holder_name' => $voucher->holder_name,
                    'issued_by_admin_id' => $issuingAdmin?->id,
                    'reason' => $payload['notes'] ?? null,
                ],
            ]);

            return $voucher;
        });
    }

    public function reissue(Voucher $original, array $overrides = []): Voucher
    {
        return DB::transaction(function () use ($original, $overrides): Voucher {
            $original->status = 'reissued';
            $original->save();

            $base = $original->only([
                'order_id',
                'order_item_id',
                'service_type',
                'issuer_company_id',
                'holder_name',
                'holder_passport',
                'passenger_data',
                'service_snapshot',
                'language',
                'valid_from',
                'valid_to',
            ]);

            $orderShort = $this->shortenOrderId($original->order_id);
            $seq = $this->nextSequenceForOrderId($original->order_id);

            $base['voucher_number'] = sprintf('V-%s-R%03d', $orderShort, $seq);
            $base['qr_token'] = $this->generateQrToken();
            $base['status'] = 'issued';
            $base['reissued_from_id'] = $original->id;

            $reissued = Voucher::query()->create(array_merge($base, $overrides));

            $this->audit()->log([
                'category' => 'data_change',
                'subject_type' => 'Voucher',
                'subject_id' => (string) $reissued->id,
                'action' => 'reissued',
                'context' => [
                    'voucher_number' => $reissued->voucher_number,
                    'reissued_from_id' => $original->id,
                    'reissued_from_number' => $original->voucher_number,
                ],
            ]);

            return $reissued;
        });
    }

    /**
     * Mark a voucher as void (e.g., booking cancelled).
     */
    public function void(Voucher $voucher): Voucher
    {
        $previousStatus = $voucher->status;
        $voucher->status = 'void';
        $voucher->save();

        $this->audit()->log([
            'category' => 'data_change',
            'subject_type' => 'Voucher',
            'subject_id' => (string) $voucher->id,
            'action' => 'voided',
            'changes' => ['status' => ['from' => $previousStatus, 'to' => 'void']],
            'context' => ['voucher_number' => $voucher->voucher_number],
        ]);

        return $voucher;
    }

    private function nextSequenceFor(Order $order): int
    {
        return $this->nextSequenceForOrderId($order->id);
    }

    private function nextSequenceForOrderId(string $orderId): int
    {
        return Voucher::query()
            ->where('order_id', $orderId)
            ->withTrashed()
            ->count() + 1;
    }

    private function shortenOrderId(string $uuid): string
    {
        return strtoupper(substr(str_replace('-', '', $uuid), 0, 8));
    }

    private function resolveLanguage(Order $order): string
    {
        $metaLang = is_array($order->metadata) ? ($order->metadata['language'] ?? null) : null;

        if (is_string($metaLang) && in_array($metaLang, Voucher::LANGUAGES, true)) {
            return $metaLang;
        }

        return 'en';
    }

    private function resolveHolderName(Order $order, OrderItem $item): string
    {
        if (is_array($item->passenger_data) && $item->passenger_data !== []) {
            $first = $item->passenger_data[0] ?? null;
            if (is_array($first) && isset($first['name']) && is_string($first['name']) && $first['name'] !== '') {
                return $first['name'];
            }
        }

        if ($order->relationLoaded('user') && $order->user !== null) {
            return (string) $order->user->name;
        }

        return 'Guest';
    }

    private function resolvePassport(OrderItem $item): ?string
    {
        if (! is_array($item->passenger_data) || $item->passenger_data === []) {
            return null;
        }

        $first = $item->passenger_data[0] ?? null;
        if (is_array($first) && isset($first['passport']) && is_string($first['passport']) && $first['passport'] !== '') {
            return $first['passport'];
        }

        return null;
    }

    private function generateQrToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}
