<?php

namespace App\Services\Forecast;

use App\Enums\Mall;

/**
 * SKU1件・モール1つの基準平均日販（B-134、K-126）。チャネルの基準平均日販は、チャネルのモールの基準平均日販を足したもの。
 * 期間別平均日販は、そのモールのイベントの日と、チャネルの欠品日を除いて求める。予測できないときは baseAverage が null で、理由を持つ。
 */
final readonly class MallForecast
{
    /**
     * @param  list<AverageWindow>  $windows
     */
    public function __construct(
        public Mall $mall,
        public array $windows,
        public ?float $baseAverage,
        public ?UnpredictableReason $unpredictableReason = null,
    ) {}

    public function isPredictable(): bool
    {
        return $this->baseAverage !== null;
    }
}
