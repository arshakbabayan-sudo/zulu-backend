<?php

namespace App\Console\Commands;

use App\Services\Loyalty\LoyaltyService;
use Illuminate\Console\Command;

/**
 * §8c — expire loyalty points whose earn lot has passed its expiry window.
 * Scheduled daily in routes/console.php.
 */
class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Expire loyalty points whose earned lot is past its expiry window.';

    public function handle(LoyaltyService $service): int
    {
        $expired = $service->expireStalePoints();
        $this->info(sprintf('Expired %d loyalty point(s).', $expired));

        return self::SUCCESS;
    }
}
