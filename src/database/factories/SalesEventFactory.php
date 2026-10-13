<?php

namespace Database\Factories;

use App\Enums\Mall;
use App\Models\SalesEvent;
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
        return [
            'name' => '楽天スーパーセール',
            'malls' => [Mall::Rakuten],
        ];
    }

    /**
     * @param  list<Mall>  $malls
     */
    public function malls(array $malls): static
    {
        return $this->state(fn (): array => ['malls' => $malls]);
    }
}
