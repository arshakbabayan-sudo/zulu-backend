<?php

namespace App\Events;

use App\Models\CompanySellerApplication;

class SellerApplicationApproved
{
    public function __construct(
        public CompanySellerApplication $application
    ) {}
}
