<?php

namespace App\Services\Vouchers;

use App\Mail\VoucherIssuedMail;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Voucher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class VoucherService
{
    public function __construct(
        private ?VoucherPdfService $pdfService = null
    ) {}

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
            }

            return $issued;
        })->each(function (Voucher $voucher) use ($order): void {
            $this->renderPdfSafely($voucher);
            $this->sendIssuedMailSafely($voucher, $order);
        });
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

            return Voucher::query()->create(array_merge($base, $overrides));
        });
    }

    /**
     * Mark a voucher as void (e.g., booking cancelled).
     */
    public function void(Voucher $voucher): Voucher
    {
        $voucher->status = 'void';
        $voucher->save();

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
