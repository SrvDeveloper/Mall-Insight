<?php

namespace App\Services\Forecast;

use App\Enums\Channel;

/**
 * SKU1件・チャネル1つの需要予測。予測不能のときは、基準平均日販と月別の予測が null で、理由を持つ。
 * 基準平均日販は、チャネルのモールごとの基準平均日販（malls）の合計（B-134、K-126）。windows は、モールが1つのチャネル（Amazon）では
 * そのモールの期間、モールがいくつもあるチャネル（BOSS）では空で、期間はモールごとに malls に持つ。
 */
final readonly class ChannelForecast
{
    /**
     * @param  list<AverageWindow>  $windows
     * @param  list<MonthlyDemand>|null  $monthly
     * @param  list<MallForecast>  $malls
     */
    public function __construct(
        public Channel $channel,
        public array $windows,
        public ?float $baseAverage,
        public ?array $monthly,
        public ?UnpredictableReason $unpredictableReason = null,
        public array $malls = [],
    ) {}

    /**
     * モールごとの基準平均日販（売れていないモールは0）。予測できなければ空。
     *
     * @return array<string, float> モールの値 => 基準平均日販
     */
    public function mallBases(): array
    {
        if (! $this->isPredictable()) {
            return [];
        }
        $bases = [];
        foreach ($this->malls as $mall) {
            $bases[$mall->mall->value] = $mall->baseAverage ?? 0.0;
        }

        return $bases;
    }

    public function isPredictable(): bool
    {
        return $this->baseAverage !== null;
    }
}
