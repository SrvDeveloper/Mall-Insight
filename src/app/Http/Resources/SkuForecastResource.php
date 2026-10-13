<?php

namespace App\Http\Resources;

use App\Services\Forecast\AverageWindow;
use App\Services\Forecast\ChannelForecast;
use App\Services\Forecast\MonthlyDemand;
use App\Services\Forecast\MonthlyEventDemand;
use App\Services\Forecast\SkuForecast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SKU1件の需要予測（チャネルの合計）と、チャネルごとの予測・根拠（期間別の販売数量・販売できた日数・欠品日数・イベントの日数・平均日販・重み）。
 * 月別の予測には、イベント（B-129）の日に倍率で増やした数と、その内訳を添える（K-112）。
 * 平均日販・重みは小数4桁、数量は小数2桁で返し、整数への丸めは表示するときに行う。
 *
 * @mixin SkuForecast
 */
class SkuForecastResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sku_id' => $this->sku->id,
            'sku_code' => $this->sku->sku_code,
            'status' => $this->sku->status?->value,
            'status_label' => $this->sku->status?->skuLabel(),
            'base_average' => $this->roundAverage($this->baseAverage),
            'monthly' => $this->monthly($this->monthly),
            'is_partial' => $this->isPartial,
            'unpredictable_reason_label' => $this->unpredictableReasonLabel(),
            'channels' => array_map(fn (ChannelForecast $channel): array => [
                'channel' => $channel->channel->value,
                'channel_label' => $channel->channel->label(),
                'base_average' => $this->roundAverage($channel->baseAverage),
                'unpredictable_reason' => $channel->unpredictableReason?->value,
                'unpredictable_reason_label' => $channel->unpredictableReason?->label(),
                'windows' => array_map(fn (AverageWindow $window): array => [
                    'length' => $window->length,
                    'offset' => $window->offset,
                    'label' => $window->label(),
                    'from' => $window->from?->toDateString(),
                    'to' => $window->to?->toDateString(),
                    'sales_days' => $window->salesDays,
                    'stockout_days' => $window->stockoutDays,
                    'event_days' => $window->eventDays,
                    'sales_quantity' => $window->salesQuantity,
                    'average' => $this->roundAverage($window->average),
                    'base_weight' => $window->baseWeight,
                    'weight' => round($window->weight, 4),
                    'is_excluded' => $window->isExcluded(),
                ], $channel->windows),
                'monthly' => $this->monthly($channel->monthly),
            ], $this->channels),
        ];
    }

    private function roundAverage(?float $value): ?float
    {
        return $value === null ? null : round($value, 4);
    }

    /**
     * @param  list<MonthlyDemand>|null  $months
     * @return list<array{month: string, days: int, quantity: float, event_quantity: float, events: list<array<string, mixed>>}>|null
     */
    private function monthly(?array $months): ?array
    {
        return $months === null ? null : array_map(fn (MonthlyDemand $month): array => [
            'month' => $month->month->format('Y-m'),
            'days' => $month->days,
            'quantity' => round($month->quantity, 2),
            'event_quantity' => round($month->eventQuantity(), 2),
            'events' => array_map(fn (MonthlyEventDemand $event): array => [
                'event_id' => $event->eventId,
                'name' => $event->name,
                'channel' => $event->channel->value,
                'days' => $event->days,
                'multiplier' => $event->multiplier,
                'is_candidate' => $event->isCandidate,
                'quantity' => round($event->quantity, 2),
            ], $month->events),
        ], $months);
    }
}
