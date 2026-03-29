<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Sanctum\PersonalAccessToken;

class PruneExpiredTokens extends Command
{
    protected $signature = 'tokens:prune';

    protected $description = 'Prune expired Sanctum personal access tokens';

    public function handle(): int
    {
        $prunedCount = PersonalAccessToken::query()
            ->where('expires_at', '<', now())
            ->whereNotNull('expires_at')
            ->delete();

        $this->info("Pruned {$prunedCount} expired tokens.");

        return self::SUCCESS;
    }
}

