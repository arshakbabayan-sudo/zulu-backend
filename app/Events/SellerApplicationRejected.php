<?php

namespace App\Events;

use App\Models\CompanySellerApplication;

class SellerApplicationRejected
{
    public function __construct(
        public CompanySellerApplication $application
    ) {}
}
