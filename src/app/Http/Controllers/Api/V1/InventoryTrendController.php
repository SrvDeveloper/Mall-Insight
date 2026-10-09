<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Warehouse;
use App\Http\Controllers\Controller;
use App\Http\Resources\SkuTrendResource;
use App\Services\InventoryTrend\DemandBasis;
use App\Services\InventoryTrend\InventoryTrendCalculator;
use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryTrendController extends Controller
{
    /**
     * 対象品番のSKUの12か月在庫推移（バックログ B-008・B-122）。開くたびに最新の在庫・需要・入荷予定から計算し、保存しない。
     * basis で需要予測（初期値）と販売試算を、scope で全体（初期値）・Amazon・BOSSを切り替える。
     */
    public function index(Request $request, InventoryTrendCalculator $calculator): JsonResponse
    {
        $request->validate(['basis' => ['nullable', Rule::enum(DemandBasis::class)], 'scope' => ['nullable', Rule::enum(TrendScope::class)]]);
        $basis = $request->enum('basis', DemandBasis::class) ?? DemandBasis::Forecast;
        $scope = $request->enum('scope', TrendScope::class) ?? TrendScope::Total;
        $trend = $calculator->calculate(CarbonImmutable::now(), basis: $basis, scope: $scope);
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
                'basis' => $trend->basis->value,
                'basis_label' => $trend->basis->label(),
                'scope' => $trend->scope->value,
                'scope_label' => $trend->scope->label(),
                'scope_warehouses' => array_map(fn (Warehouse $warehouse): array => ['warehouse' => $warehouse->value, 'label' => $warehouse->label()], $trend->scope->warehouses()),
                'months' => array_map(fn (array $month): array => ['month' => $month['month']->format('Y-m'), 'days' => $month['days']], $trend->months),
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
                'sales_targets' => $trend->salesTargets === null ? null : array_map(fn (array $year): array => [
                    'fiscal_year' => $year['fiscalYear'],
                    'from' => $year['from']->format('Y-m'),
                    'to' => $year['to']->format('Y-m'),
                    'calculated_item_count' => $year['calculatedItemCount'],
                ], $trend->salesTargets),
            ],
        ]);
    }
}
