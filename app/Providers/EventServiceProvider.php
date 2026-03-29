<?php

namespace App\Providers;

use App\Events\BookingConfirmed;
use App\Events\CompanyApplicationApproved;
use App\Events\CompanyApplicationRejected;
use App\Events\CompanyApplicationSubmitted;
use App\Events\PaymentReceived;
use App\Events\SellerApplicationApproved;
use App\Events\SellerApplicationRejected;
use App\Listeners\SendBookingConfirmedEmail;
use App\Listeners\SendCompanyApplicationApprovedEmail;
use App\Listeners\SendCompanyApplicationReceivedEmail;
use App\Listeners\SendCompanyApplicationRejectedEmail;
use App\Listeners\SendPaymentReceivedEmail;
use App\Listeners\SendSellerApplicationApprovedEmail;
use App\Listeners\SendSellerApplicationRejectedEmail;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        CompanyApplicationSubmitted::class => [
            SendCompanyApplicationReceivedEmail::class,
        ],
        CompanyApplicationApproved::class => [
            SendCompanyApplicationApprovedEmail::class,
        ],
        CompanyApplicationRejected::class => [
            SendCompanyApplicationRejectedEmail::class,
        ],
        SellerApplicationApproved::class => [
            SendSellerApplicationApprovedEmail::class,
        ],
        SellerApplicationRejected::class => [
            SendSellerApplicationRejectedEmail::class,
        ],
        BookingConfirmed::class => [
            SendBookingConfirmedEmail::class,
        ],
        PaymentReceived::class => [
            SendPaymentReceivedEmail::class,
        ],
    ];
}
