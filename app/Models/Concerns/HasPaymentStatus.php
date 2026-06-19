<?php

namespace App\Models\Concerns;

use App\Enums\PaymentStatus;

trait HasPaymentStatus
{
    public function syncPaymentStatus(): void
    {
        // Phiếu thu gắn trực tiếp (đơn lẻ) + phần phân bổ từ phiếu thu gộp (1 phiếu → nhiều đơn)
        $paid = (float) $this->payments()->sum('amount');
        if (method_exists($this, 'allocations')) {
            $paid += (float) $this->allocations()->sum('amount');
        }
        $total = (float) $this->total_amount;

        // Đơn hoàn tiền có total âm nhưng phiếu chi lưu amount dương → so sánh theo trị tuyệt đối
        $paidAbs = abs($paid);
        $totalAbs = abs($total);

        $status = match (true) {
            $paidAbs < 0.01 => PaymentStatus::Unpaid,
            $paidAbs >= $totalAbs - 0.01 => PaymentStatus::Paid,
            default => PaymentStatus::Partial,
        };

        $this->updateQuietly(['paid_amount' => $paid, 'payment_status' => $status]);
    }
}
