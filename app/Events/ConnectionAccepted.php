<?php

namespace App\Events;

use App\Models\ServiceConnection;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConnectionAccepted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public ServiceConnection $connection
    ) {}
}
