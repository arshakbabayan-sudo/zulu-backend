<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use App\Services\Pricing\Fx\CbaRateProvider;
use App\Services\Pricing\Fx\EcbRateProvider;
use App\Services\Pricing\Fx\ExchangerateApiProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 1 / Step C.4 — daily FX refresh.
 *
 * Sources, in fallback order:
 *   1. CBA (Central Bank of Armenia) — primary, authoritative for AMD pairs
 *   2. ECB (European Central Bank)   — fallback for EUR pairs / non-AMD
 *   3. exchangerate-api.com           — last-resort fallback (free tier)
 *
 * Per call: for each (source_currency, target_currency) pair we want to
 * keep fresh, attempt sources in order, persist the first success.
 *
 * Writes go through a small transaction: deactivate previously-active
 * row for that (source, target, provider) tuple, then insert the new
 * row with is_active=true. The partial unique index
 * `exchange_rates_active_uq` enforces uniqueness.
 *
 * Manual / partner_override rows are NEVER touched — they always win
 * over auto-pulled rows in the consumer side.
 */
class FxRefresh extends Command
{
    protected $signature = 'fx:refresh
                            {--source=auto : Restrict to a single provider (cba|ecb|exchangerate_api|auto)}
                            {--quiet-success : Suppress per-pair success logs}';

    protected $description = 'Refresh exchange_rates rows from the configured providers (Phase 1 / C.4).';

    public function __construct(
        private CbaRateProvider $cba,
        private EcbRateProvider $ecb,
        private ExchangerateApiProvider $api,
    ) {
        parent::__construct();
    }

    /**
     * Pairs we proactively refresh. Extend as new currencies enter the
     * marketplace.
     *
     * @var array<int, array{0:string,1:string}>
     */
    private array $pairs = [
        ['USD', 'AMD'],
        ['EUR', 'AMD'],
        ['RUB', 'AMD'],
        ['GBP', 'AMD'],
        ['USD', 'EUR'],
        ['EUR', 'USD'],
        ['USD', 'RUB'],
        ['EUR', 'RUB'],
    ];

    public function handle(): int
    {
        $restrict = (string) $this->option('source');
        $providers = $this->resolveProviderChain($restrict);
        $now = now();

        $okCount = 0;
        $failCount = 0;
        $failures = [];

        foreach ($this->pairs as [$src, $tgt]) {
            $persisted = false;
            foreach ($providers as $providerKey => $provider) {
                try {
                    $rate = $provider->fetch($src, $tgt);
                } catch (Throwable $e) {
                    Log::warning('fx:refresh provider error', [
                        'provider' => $providerKey,
                        'pair' => "{$src}->{$tgt}",
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                if ($rate === null) {
                    continue;
                }

                $this->persist($src, $tgt, $rate, $providerKey, $now);
                $persisted = true;
                $okCount++;

                if (! $this->option('quiet-success')) {
                    $this->line(sprintf('  %s -> %s = %s (via %s)', $src, $tgt, $rate, $providerKey));
                }
                break;
            }

            if (! $persisted) {
                $failCount++;
                $failures[] = "{$src}->{$tgt}";
            }
        }

        $this->info(sprintf(
            'fx:refresh done — %d ok, %d failed%s',
            $okCount,
            $failCount,
            $failures === [] ? '' : ' ('.implode(', ', $failures).')'
        ));

        // Non-zero only if EVERY pair failed.
        return $failCount === count($this->pairs) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, object>  provider-key => provider instance
     */
    private function resolveProviderChain(string $restrict): array
    {
        $chain = [
            ExchangeRate::SOURCE_CBA => $this->cba,
            ExchangeRate::SOURCE_ECB => $this->ecb,
            ExchangeRate::SOURCE_EXCHANGERATE_API => $this->api,
        ];

        if ($restrict !== 'auto' && isset($chain[$restrict])) {
            return [$restrict => $chain[$restrict]];
        }

        return $chain;
    }

    private function persist(string $src, string $tgt, string $rate, string $source, Carbon $now): void
    {
        DB::transaction(function () use ($src, $tgt, $rate, $source, $now): void {
            // Deactivate previous row for this (src, tgt, source) tuple.
            ExchangeRate::query()
                ->where('source_currency', strtoupper($src))
                ->where('target_currency', strtoupper($tgt))
                ->where('source', $source)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            ExchangeRate::create([
                'source_currency' => strtoupper($src),
                'target_currency' => strtoupper($tgt),
                'rate' => $rate,
                'source' => $source,
                'fetched_at' => $now,
                'is_active' => true,
                'created_at' => $now,
            ]);
        });
    }
}
