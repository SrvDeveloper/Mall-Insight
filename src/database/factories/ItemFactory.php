<?php

namespace Database\Factories;

use App\Enums\ActiveStatus;
use App\Models\Item;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_no' => fake()->unique()->bothify('??-####'),
            'brand' => fake()->randomElement(['SHIORI', 'FEELLIFE', 'BEAMS DESIGN', 'GGeyewear', 'REFLET', 'SPC']),
            'category' => fake()->randomElement(['老眼鏡', 'サングラス', '調光サングラス', '調光老眼鏡', 'インスタントグラス']),
            'parent_asin' => fake()->unique()->regexify('B0[A-Z0-9]{8}'),
            'status' => ActiveStatus::Active,
            'crosswalker_updated_at' => fake()->dateTimeBetween('-60 days'),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['status' => ActiveStatus::Inactive]);
    }

    /**
     * カラー × 度数の組合せで、CrossWalker と同じ形式のSKUを持たせる。
     *
     * @param  list<string>  $colors
     * @param  list<string>  $sizes
     */
    public function withSkus(array $colors = ['1', '2'], array $sizes = ['10', '15', '20']): static
    {
        return $this->afterCreating(function (Item $item) use ($colors, $sizes): void {
            $position = 0;
            foreach ($colors as $color) {
                foreach ($sizes as $size) {
                    Sku::factory()->for($item)->create([
                        'sku_code' => "{$item->item_no}-{$color}-{$size}",
                        'tq_item_no' => strtoupper(str_replace('-', '', $item->item_no)),
                        'tq_color_no' => $color,
                        'tq_size' => $size,
                        'position' => $position++,
                    ]);
                }
            }
        });
    }
}
