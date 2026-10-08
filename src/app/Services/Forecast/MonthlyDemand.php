<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * 1か月分の需要予測。今月も1か月すべての日数分（K-052）。数量は丸めずに持つ。
 */
final readonly class MonthlyDemand
{
    public function __construct(
        public CarbonImmutable $month,
        public int $days,
        public float $quantity,
    ) {}
}
