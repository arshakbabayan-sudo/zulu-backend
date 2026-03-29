<?php

namespace App\Events;

use App\Models\CompanyApplication;

class CompanyApplicationSubmitted
{
    public function __construct(
        public CompanyApplication $application
    ) {}
}
