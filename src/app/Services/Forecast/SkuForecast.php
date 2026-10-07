<?php

namespace App\Services\Forecast;

use App\Models\Sku;

/**
 * SKU1件の需要予測。販売実績を取り込んだチャネルのうち、そのSKUを売っているチャネルごとに予測し、予測できたチャネルを合計する
 * （決定記録 K-038・K-042）。
 * 予測できたチャネルが1つも無ければ、基準平均日販と月別の予測は null。一部のチャネルだけ予測できたときは isPartial。
 */
final readonly class SkuForecast
{
    /**
     * @param  list<ChannelForecast>  $channels
     * @param  list<MonthlyDemand>|null  $monthly
     */
    public function __construct(
        public Sku $sku,
        public array $channels,
        public ?float $baseAverage,
        public ?array $monthly,
        public bool $isPartial,
    ) {}

    public static function combine(Sku $sku, array $channels): self
    {
        $predictable = array_values(array_filter($channels, fn (ChannelForecast $channel): bool => $channel->isPredictable()));
        if ($predictable === []) {
            return new self($sku, $channels, null, null, false);
        }

        $monthly = array_map(
            fn (MonthlyDemand $month, int $index): MonthlyDemand => new MonthlyDemand($month->month, $month->days, array_sum(array_map(fn (ChannelForecast $channel): float => $channel->monthly[$index]->quantity, $predictable))),
            $predictable[0]->monthly,
            array_keys($predictable[0]->monthly),
        );

        return new self($sku, $channels, array_sum(array_map(fn (ChannelForecast $channel): float => $channel->baseAverage, $predictable)), $monthly, count($predictable) < count($channels));
    }

    /**
     * 予測できなかったチャネルの理由（「BOSS：直近180日に販売実績がありません」など）。
     */
    public function unpredictableReasonLabel(): ?string
    {
        if ($this->channels === []) {
            return '販売実績がまだ取り込まれていません（または、このSKUを売っているチャネルがありません）';
        }
        $reasons = array_map(fn (ChannelForecast $channel): string => "{$channel->channel->label()}：{$channel->unpredictableReason->label()}", array_values(array_filter($this->channels, fn (ChannelForecast $channel): bool => ! $channel->isPredictable())));

        return $reasons === [] ? null : implode('／', $reasons);
    }
}
