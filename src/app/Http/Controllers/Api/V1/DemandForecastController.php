<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SkuForecastResource;
use App\Services\Forecast\DemandForecaster;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DemandForecastController extends Controller
{
    /**
     * 対象品番のSKUの需要予測（バックログ B-006）。チャネルごとに予測して合計する（決定記録 K-038）。
     * 開くたびに最新の販売実績から計算する（K-035）。
     * 対象品番が未確定なら data は空。
     */
    public function index(DemandForecaster $forecaster): JsonResponse
    {
        $forecast = $forecaster->forecast(CarbonImmutable::now());

        return response()->json([
            'data' => array_map(fn (array $entry): array => [
                'item_no' => $entry['item']->item_no,
                'brand' => $entry['item']->brand,
                'category' => $entry['item']->category,
                'skus' => SkuForecastResource::collection($entry['skus'])->resolve(),
            ], $forecast->items),
            'meta' => [
                'calculated_at' => $forecast->calculatedAt->toIso8601String(),
                'channels' => array_map(fn (array $channel): array => [
                    'channel' => $channel['channel']->value,
                    'label' => $channel['channel']->label(),
                    'sales_data_from' => $channel['from']?->toDateString(),
                    'sales_data_to' => $channel['to']?->toDateString(),
                ], $forecast->channels),
                'forecast_from' => $forecast->forecastFrom->toDateString(),
                'selection' => $forecast->selection === null ? null : [
                    'confirmed_at' => $forecast->selection->confirmed_at->toIso8601String(),
                    'item_count' => $forecast->selection->items->count(),
                ],
                'window_weights' => array_map(fn (int $length, float $weight): array => ['length' => $length, 'weight' => $weight], array_keys(DemandForecaster::WINDOW_WEIGHTS), DemandForecaster::WINDOW_WEIGHTS),
                'min_sales_days' => DemandForecaster::MIN_SALES_DAYS,
            ],
        ]);
    }
}
