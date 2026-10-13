<?php

namespace App\Services\Forecast;

use App\Enums\Channel;

/**
 * 1か月のうち、1つのイベント（B-129）の日に倍率で増やした需要（K-112）。増やした数＝基準平均日販×（倍率－1）×その月のイベントの日数。
 * 同じチャネルでイベントが重なる日は、倍率の大きいイベントだけを数える。
 */
final readonly class MonthlyEventDemand
{
    public function __construct(
        public int $eventId,
        public string $name,
        public Channel $channel,
        public int $days,
        public float $multiplier,
        public bool $isCandidate,
        public float $quantity,
    ) {}
}
