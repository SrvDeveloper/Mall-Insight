<?php

namespace App\Http\Resources;

use App\Models\ForecastBacktest;
use App\Services\Forecast\BacktestHorizon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 予測精度を測った記録（B-130）。何か月先ごとの名前と起点の範囲を添える。
 *
 * @mixin ForecastBacktest
 */
class ForecastBacktestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'measured_at' => $this->measured_at->toIso8601String(),
            'measured_by' => $this->measuredBy?->name,
            'check_month_offset' => $this->check_month_offset,
            'item_count' => $this->item_count,
            'sku_count' => $this->sku_count,
            'duration_ms' => $this->duration_ms,
            'horizons' => array_map(function (BacktestHorizon $horizon): array {
                $origins = $this->settings['origins'][$horizon->value] ?? [];

                return [
                    'horizon' => $horizon->value,
                    'label' => $horizon->label($this->check_month_offset),
                    'origin_from' => $origins[0] ?? null,
                    'origin_to' => $origins[count($origins) - 1] ?? null,
                    'origin_count' => count($origins),
                ];
            }, BacktestHorizon::cases()),
        ];
    }
}
