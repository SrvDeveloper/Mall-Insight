<?php

namespace Database\Factories;

use App\Models\SalesEvent;
use App\Models\SalesEventPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesEventPeriod>
 */
class SalesEventPeriodFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startsOn = CarbonImmutable::instance(fake()->dateTimeBetween('-1 year', '+3 months'))->startOfDay();

        return [
            'sales_event_id' => SalesEvent::factory(),
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->addDays(6),
        ];
    }

    public function period(string $startsOn, string $endsOn): static
    {
        return $this->state(fn (): array => ['starts_on' => $startsOn, 'ends_on' => $endsOn]);
    }
}
