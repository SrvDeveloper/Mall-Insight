<?php

namespace Database\Factories;

use App\Enums\ActiveStatus;
use App\Models\Item;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sku>
 */
class SkuFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'sku_code' => fake()->unique()->bothify('??-####-#-##'),
            'child_asin' => fake()->unique()->regexify('B0[A-Z0-9]{8}'),
            'status' => ActiveStatus::Active,
            'tq_item_no' => fake()->bothify('??####'),
            'tq_color_no' => (string) fake()->numberBetween(1, 4),
            'tq_size' => fake()->randomElement(['10', '15', '20', '25']),
            'position' => 0,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => ActiveStatus::Inactive]);
    }

    /**
     * 販売実績・在庫にだけ現れ、CrossWalker の品番に属していないSKU。
     */
    public function unassigned(): static
    {
        return $this->state(fn (): array => [
            'item_id' => null,
            'child_asin' => null,
            'status' => null,
            'tq_item_no' => null,
            'tq_color_no' => null,
            'tq_size' => null,
            'position' => null,
        ]);
    }
}
