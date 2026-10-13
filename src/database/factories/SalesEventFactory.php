<?php

namespace Database\Factories;

use App\Enums\Mall;
use App\Models\SalesEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesEvent>
 */
class SalesEventFactory extends Factory
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
            'name' => '楽天スーパーセール',
            'starts_on' => $startsOn,
            'ends_on' => $startsOn->addDays(6),
            'malls' => [Mall::Rakuten],
        ];
    }

    /**
     * その期間・モールのイベント。
     *
     * @param  list<Mall>  $malls
     */
    public function period(string $startsOn, string $endsOn, array $malls = [Mall::Rakuten]): static
    {
        return $this->state(fn (): array => ['starts_on' => $startsOn, 'ends_on' => $endsOn, 'malls' => $malls]);
    }
}
