<?php

namespace App\Console\Commands;

use App\Services\Cart\CheckoutService;
use Illuminate\Console\Command;

class ReleaseExpiredCartHolds extends Command
{
    protected $signature = 'cart:release-expired-holds';

    protected $description = 'Release pending_payment orders whose held_until has passed (back to cart status).';

    public function handle(CheckoutService $checkoutService): int
    {
        $released = $checkoutService->releaseExpiredHolds();
        $this->info("Released {$released} expired cart hold(s).");

        return self::SUCCESS;
    }
}
