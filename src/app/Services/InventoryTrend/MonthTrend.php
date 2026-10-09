<?php

namespace App\Services\InventoryTrend;

use Carbon\CarbonImmutable;

/**
 * SKU1件・1か月分の在庫推移。数量は丸めずに持つ。入荷のうち provisionalInbound は、未割り振りの入荷予定を需要の比率で
 * 仮に割り振った分（K-043）。
 */
final readonly class MonthTrend
{
    public function __construct(
        public CarbonImmutable $month,
        public int $days,
        public float $openingStock,
        public float $demand,
        public float $inbound,
        public float $provisionalInbound,
        public float $endingStock,
        public float $shortfall,
    ) {}
}
