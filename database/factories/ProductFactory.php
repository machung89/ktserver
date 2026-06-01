<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('SP-####')),
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->sentence(),
            'unit' => $this->faker->randomElement(['cái', 'kg', 'hộp', 'lít', 'thùng']),
            'category_id' => null,
            'price' => $this->faker->randomFloat(2, 10000, 10000000),
            'cost_price' => $this->faker->randomFloat(2, 5000, 8000000),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
