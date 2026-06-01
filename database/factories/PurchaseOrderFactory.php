<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $seq = 0;
        $seq++;

        return [
            'order_number' => 'NK'.str_pad($seq, 6, '0', STR_PAD_LEFT),
            'status' => OrderStatus::Draft,
            'payment_status' => PaymentStatus::Unpaid,
            'order_date' => now()->toDateString(),
            'subtotal' => 0,
            'tax_amount' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
        ];
    }
}
