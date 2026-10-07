<?php

namespace App\Http\Resources;

use App\Services\Forecast\AverageWindow;
use App\Services\Forecast\MonthlyDemand;
use App\Services\Forecast\SkuForecast;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SKU1件の需要予測と、その根拠（期間別の販売数量・販売できた日数・欠品日数・平均日販・重み）。平均日販・重みは小数4桁、数量は小数2桁で返し、整数への丸めは表示するときに行う。
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
            'status_label' => $this->sku->status?->label(),
            'base_average' => $this->baseAverage === null ? null : round($this->baseAverage, 4),
            'unpredictable_reason' => $this->unpredictableReason?->value,
            'unpredictable_reason_label' => $this->unpredictableReason?->label(),
            'windows' => array_map(fn (AverageWindow $window): array => [
                'length' => $window->length,
                'offset' => $window->offset,
                'label' => $window->label(),
                'from' => $window->from?->toDateString(),
                'to' => $window->to?->toDateString(),
                'sales_days' => $window->salesDays,
                'stockout_days' => $window->stockoutDays,
                'sales_quantity' => $window->salesQuantity,
                'average' => $window->average === null ? null : round($window->average, 4),
                'base_weight' => $window->baseWeight,
                'weight' => round($window->weight, 4),
                'is_excluded' => $window->isExcluded(),
            ], $this->windows),
            'monthly' => $this->monthly === null ? null : array_map(fn (MonthlyDemand $month): array => [
                'month' => $month->month->format('Y-m'),
                'days' => $month->days,
                'quantity' => round($month->quantity, 2),
            ], $this->monthly),
        ];
    }
}
