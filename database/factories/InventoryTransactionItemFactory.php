<?php

namespace Database\Factories;

use App\Models\InventoryTransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryTransactionItem>
 */
class InventoryTransactionItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quantity' => $this->faker->randomFloat(2, 1, 100),
            'unit_price' => $this->faker->randomFloat(2, 1000, 500000),
        ];
    }
}
