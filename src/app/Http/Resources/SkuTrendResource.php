<?php

namespace App\Http\Resources;

use App\Enums\Warehouse;
use App\Services\InventoryTrend\MonthTrend;
use App\Services\InventoryTrend\SkuTrend;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SKU1件の12か月在庫推移。数量は小数2桁で返し、整数への丸めは表示するときに行う。
 *
 * @mixin SkuTrend
 */
class SkuTrendResource extends JsonResource
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
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'opening_stock' => $this->openingStock(),
            'stock_by_warehouse' => $this->stockByWarehouse === null ? null : array_map(fn (Warehouse $warehouse): array => [
                'warehouse' => $warehouse->value,
                'label' => $warehouse->label(),
                'quantity' => $this->stockByWarehouse[$warehouse->value] ?? null,
            ], Warehouse::cases()),
            'average_daily' => $this->forecast->baseAverage === null ? null : round($this->forecast->baseAverage, 4),
            'is_partial_forecast' => $this->forecast->isPartial,
            'forecast_reason_label' => $this->forecast->unpredictableReasonLabel(),
            'warning' => $this->warning->value,
            'warning_label' => $this->warning->label(),
            'first_shortage_month' => $this->firstShortageIndex === null ? null : $this->months[$this->firstShortageIndex]->month->format('Y-m'),
            'months' => $this->months === null ? null : array_map(fn (MonthTrend $month): array => [
                'month' => $month->month->format('Y-m'),
                'days' => $month->days,
                'opening_stock' => round($month->openingStock, 2),
                'demand' => round($month->demand, 2),
                'inbound' => round($month->inbound, 2),
                'provisional_inbound' => round($month->provisionalInbound, 2),
                'ending_stock' => round($month->endingStock, 2),
                'shortfall' => round($month->shortfall, 2),
            ], $this->months),
        ];
    }
}
