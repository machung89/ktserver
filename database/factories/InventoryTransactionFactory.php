<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\InventoryTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransaction>
 */
class InventoryTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TransactionType::Adjustment,
            'transaction_date' => now()->toDateString(),
            'is_posted' => true,
        ];
    }
}
