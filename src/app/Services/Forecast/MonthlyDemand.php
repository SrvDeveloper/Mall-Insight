<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * 1か月分の需要予測。予測の初月は、予測の開始日から月末までの日数分。数量は丸めずに持つ。
 */
final readonly class MonthlyDemand
{
    public function __construct(
        public CarbonImmutable $month,
        public int $days,
        public float $quantity,
    ) {}
}
