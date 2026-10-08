<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SkuTrendResource;
use App\Services\InventoryTrend\InventoryTrendCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class InventoryTrendController extends Controller
{
    /**
     * 対象品番のSKUの12か月在庫推移（バックログ B-008）。開くたびに最新の在庫・需要予測・入荷予定から計算し、保存しない。
     */
    public function index(InventoryTrendCalculator $calculator): JsonResponse
    {
        $trend = $calculator->calculate(CarbonImmutable::now());
        $forecast = $trend->forecast;
        $settings = $trend->settings;

        return response()->json([
            'data' => array_map(fn (array $entry): array => [
                'item_no' => $entry['item']->item_no,
                'brand' => $entry['item']->brand,
                'category' => $entry['item']->category,
                'unassignable_inbound' => $entry['unassignableInbound'],
                'skus' => SkuTrendResource::collection($entry['skus'])->resolve(),
            ], $trend->items),
            'meta' => [
                'calculated_at' => $trend->calculatedAt->toIso8601String(),
                'stock_date' => $trend->stockDate?->toDateString(),
                'forecast_from' => $forecast->forecastFrom->toDateString(),
                'sales_channels' => array_map(fn (array $channel): array => [
                    'channel' => $channel['channel']->value,
                    'label' => $channel['channel']->label(),
                    'sales_data_to' => $channel['to']?->toDateString(),
                ], $forecast->channels),
                'selection' => $forecast->selection === null ? null : [
                    'confirmed_at' => $forecast->selection->confirmed_at->toIso8601String(),
                    'item_count' => $forecast->selection->items->count(),
                ],
                'settings' => $settings->toArray(),
                'check_month' => $forecast->forecastFrom->startOfMonth()->addMonthsNoOverflow($settings->checkMonthOffset)->format('Y-m'),
                'overdue_inbound_count' => $trend->overdueInboundCount,
            ],
        ]);
    }
}
