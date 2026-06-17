<?php

namespace App\Console\Commands;

use App\Services\Contracts\ContractService;
use Illuminate\Console\Command;

/**
 * §12 — auto-renew contracts whose renewal window has opened.
 *
 * A contract with auto_renew=true rolls over for another term once it enters
 * its termination-notice window (now within `termination_notice_days` of
 * expiry) without having been terminated. Scheduled daily in routes/console.php.
 */
class AutoRenewContracts extends Command
{
    protected $signature = 'contracts:auto-renew';

    protected $description = 'Auto-renew contracts whose renewal window has opened (auto_renew=true).';

    public function handle(ContractService $service): int
    {
        $count = $service->renewDueContracts();
        $this->info(sprintf('Auto-renewed %d contract(s).', $count));

        return self::SUCCESS;
    }
}
