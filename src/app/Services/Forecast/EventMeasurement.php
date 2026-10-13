<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\SalesEventPeriod;

/**
 * 終わったイベント1つ・チャネル1つで、普段より何倍売れたか（B-129、K-112）。
 * 普段なら売れた数＝イベントの初日時点の基準平均日販（イベントの日を除いて求めたもの）×イベントの日数、を対象品番のSKUごとに求めて足す。
 * 欠品していた日と、初日時点で予測できなかったSKUは、普段なら売れた数・売れた数の両方から外す。
 */
final readonly class EventMeasurement
{
    /**
     * @param  array<string, array{expected: float, actual: int}>  $items  品番 => 普段なら売れた数・売れた数
     */
    public function __construct(
        public SalesEventPeriod $event,
        public Channel $channel,
        public array $items,
        public float $expected,
        public int $actual,
        public int $skuCount,
    ) {}

    /**
     * 普段の何倍売れたか。普段なら売れた数が0なら null。
     */
    public function ratio(): ?float
    {
        return $this->expected > 0 ? $this->actual / $this->expected : null;
    }
}
