<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ShowForecastTimelineRequest;
use App\Http\Resources\SkuForecastResource;
use App\Models\Item;
use App\Services\Actuals\SalesUnit;
use App\Services\Forecast\ForecastTimeline;
use App\Services\Forecast\SkuForecast;
use App\Services\Pricing\UnitPriceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class ForecastTimelineController extends Controller
{
    /**
     * 需要予測の画面（B-131、K-123）。対象品番のSKU・チャネルごとに、過去の月・日の販売実績（数・金額）・欠品・予測し直した数と、
     * 今月（今日）から先の需要予測を、columns の順に並べる。販売実績を取り込んでいない月・日と、予測できない月・日は null。
     * item_no を指定すると、その品番だけを返し、SKUごとの予測の根拠（平均日販の期間・月別の内訳）を添える（SKU別の需要予測）。
     */
    public function index(ShowForecastTimelineRequest $request, ForecastTimeline $timeline): JsonResponse
    {
        $now = CarbonImmutable::now();
        $unit = $request->unit();
        [$from, $to] = $unit === SalesUnit::Day ? $request->period() : [null, null];
        $itemNo = $request->itemNo();
        $result = $timeline->build($now, $unit, $from, $to, $itemNo === null ? null : [$itemNo]);
        $forecast = $result['forecast'];
        $prices = UnitPriceCatalog::build(collect($forecast->items)->map(fn (array $entry): Item => $entry['item']), $now);

        return response()->json([
            'data' => array_map(fn (array $entry): array => [
                'item_no' => $entry['item']->item_no,
                'brand' => $entry['item']->brand,
                'category' => $entry['item']->category,
                'skus' => array_map(fn (SkuForecast $sku): array => [
                    'sku_id' => $sku->sku->id,
                    'sku_code' => $sku->sku->sku_code,
                    'color_no' => $sku->sku->tq_color_no,
                    'size' => $sku->sku->tq_size,
                    'base_average' => $sku->baseAverage === null ? null : round($sku->baseAverage, 4),
                    'is_partial' => $sku->isPartial,
                    'unpredictable_reason_label' => $sku->unpredictableReasonLabel(),
                    'unit_prices' => $prices->forecastPrices($sku, $entry['item']->item_no),
                    'channels' => (object) ($result['skus'][$sku->sku->id] ?? []),
                    ...($itemNo === null ? [] : ['detail' => SkuForecastResource::make($sku)->resolve()]),
                ], $entry['skus']),
            ], $forecast->items),
            'meta' => [
                ...DemandForecastController::meta($forecast),
                'unit' => $unit->value,
                'max_future_days' => ShowForecastTimelineRequest::MAX_FUTURE_DAYS,
                'columns' => array_map(fn (array $column): array => ['key' => $column['key'], 'kind' => $column['kind'], 'days' => $column['days']], $result['columns']),
            ],
        ]);
    }
}
