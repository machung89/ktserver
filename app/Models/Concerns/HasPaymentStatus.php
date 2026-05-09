<?php

namespace App\Models\Concerns;

use App\Enums\PaymentStatus;

trait HasPaymentStatus
{
    public function syncPaymentStatus(): void
    {
        $paid = (float) $this->payments()->sum('amount');
        $total = (float) $this->total_amount;

        $status = match (true) {
            $paid <= 0 => PaymentStatus::Unpaid,
            $paid >= $total => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };

        $this->updateQuietly(['paid_amount' => $paid, 'payment_status' => $status]);
    }
}
