<?php

namespace App\Exceptions;

class PaymentRefundFailedException extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly ?int $paymentId = null
    ) {
        parent::__construct("Payment refund failed: {$reason}");
    }
}
