<?php

namespace App\Console\Commands;

use App\Services\UserAccount\AccountDeletionService;
use Illuminate\Console\Command;

class PurgeDeletedAccounts extends Command
{
    protected $signature = 'accounts:purge-expired';

    protected $description = 'GDPR — permanently delete accounts whose 30-day grace window has elapsed.';

    public function handle(AccountDeletionService $service): int
    {
        $count = $service->purgeExpired();
        $this->info(sprintf('Purged %d account(s).', $count));

        return self::SUCCESS;
    }
}
