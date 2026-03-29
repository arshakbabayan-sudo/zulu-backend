<?php

namespace App\Events;

use App\Models\CompanyApplication;

class CompanyApplicationRejected
{
    public function __construct(
        public CompanyApplication $application
    ) {}
}
