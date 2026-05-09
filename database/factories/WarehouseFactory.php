<?php

namespace Database\Factories;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        static $counter = 0;
        $counter++;

        return [
            'code' => 'KHO-'.str_pad($counter, 2, '0', STR_PAD_LEFT),
            'name' => 'Kho hàng '.$this->faker->city(),
            'address' => $this->faker->streetAddress(),
            'is_active' => true,
        ];
    }
}
