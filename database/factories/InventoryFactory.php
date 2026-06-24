<?php

namespace Database\Factories;

use App\Models\Inventory;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Inventory>
 */
class InventoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'warehouse_id' => Warehouse::factory(),
            'quantity' => $this->faker->randomFloat(3, 0, 10000),
            'min_quantity' => $this->faker->randomFloat(3, 0, 100),
        ];
    }

    /**
     * Giữ bất biến: stock_value = quantity × avg_cost cho tồn kho khởi tạo (chênh lệch giá vốn = 0).
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Inventory $inventory): void {
            $inventory->stock_value = round((float) $inventory->quantity * (float) $inventory->avg_cost, 2);
        });
    }
}
