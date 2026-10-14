<?php

namespace Database\Factories;

use App\Enums\Channel;
use App\Models\ForecastAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ForecastAdjustment>
 */
class ForecastAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'item_no' => fake()->bothify('??-##'),
            'channel' => fake()->randomElement(Channel::cases()),
            'starts_on' => CarbonImmutable::today()->startOfMonth(),
            'ends_on' => null,
            'rate_percent' => fake()->numberBetween(50, 200),
            'reason' => fake()->sentence(),
        ];
    }

    /**
     * 品番・チャネルの、始める月～終える月（Y-m、終える月が null なら取り消すまで）の補正。
     */
    public function adjusting(string $itemNo, Channel $channel, string $from, ?string $to, int $percent): static
    {
        return $this->state(fn (): array => [
            'item_no' => $itemNo,
            'channel' => $channel,
            'starts_on' => "{$from}-01",
            'ends_on' => $to === null ? null : "{$to}-01",
            'rate_percent' => $percent,
        ]);
    }
}
