<?php

namespace Database\Factories;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesOrderItem>
 */
class SalesOrderItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $qty = $this->faker->randomFloat(2, 1, 50);
        $price = $this->faker->randomFloat(2, 10000, 500000);

        return [
            'sales_order_id' => SalesOrder::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => $qty,
            'unit_price' => $price,
            'cost_price' => $price * 0.7,
            'tax_rate' => 0,
            'discount_type' => null,
            'discount_value' => 0,
            'amount' => $qty * $price,
            'is_return' => false,
        ];
    }
}
