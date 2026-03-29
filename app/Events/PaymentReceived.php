<?php

namespace App\Events;

use App\Models\Invoice;
use App\Models\Payment;

class PaymentReceived
{
    public function __construct(
        public Payment $payment,
        public Invoice $invoice
    ) {}
}
