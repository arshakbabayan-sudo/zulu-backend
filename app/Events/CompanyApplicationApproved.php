<?php

namespace App\Events;

use App\Models\CompanyApplication;
use App\Models\User;

class CompanyApplicationApproved
{
    public function __construct(
        public CompanyApplication $application,
        public User $user,
        public string $temporaryPassword
    ) {}
}
