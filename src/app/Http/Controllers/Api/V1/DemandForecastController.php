<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Resources\ForecastAdjustmentResource;
use App\Http\Resources\SkuForecastResource;
use App\Models\ForecastAdjustment;
use App\Models\Item;
use App\Models\SalesEventPeriod;
use App\Services\Forecast\DemandForecast;
use App\Services\Forecast\DemandForecaster;
use App\Services\Forecast\SkuForecast;
use App\Services\InventoryTrend\TrendScope;
use App\Services\ItemSelection\TargetItems;
use App\Services\Pricing\UnitPriceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemandForecastController extends Controller
{
    /**
     * 対象品番のSKUの需要予測（バックログ B-006）。チャネルごとに予測して合計する（決定記録 K-038）。
     * 開くたびに最新の販売実績から計算する（K-035）。
     * 対象品番が未確定なら data は空。
     * item_no を指定すると、対象品番かどうかによらず、その品番だけを予測する（入荷予定の自動割り振り、K-054）。
     * 金額の概算（B-123）のため、SKUごとに合計・モール別の単価を添える。合計の単価は、モールの単価を基準平均日販の比率で
     * 加重平均したもので、合計の金額がモールの金額の合計になる（K-073）。
     */
    public function index(Request $request, DemandForecaster $forecaster): JsonResponse
    {
        $request->validate(['item_no' => ['nullable', 'string', 'max:255']]);
        $itemNo = $request->string('item_no')->toString();
        $now = CarbonImmutable::now();
        $forecast = $forecaster->forecast($now, $itemNo === '' ? null : [$itemNo]);
        $prices = UnitPriceCatalog::build(collect($forecast->items)->map(fn (array $entry): Item => $entry['item']), $now);

        return response()->json([
            'data' => array_map(fn (array $entry): array => [
                'item_no' => $entry['item']->item_no,
                'brand' => $entry['item']->brand,
                'category' => $entry['item']->category,
                'skus' => array_map(fn (SkuForecast $sku, array $resource): array => [
                    ...$resource,
                    'unit_prices' => $prices->forecastPrices($sku, $entry['item']->item_no),
                ], $entry['skus'], SkuForecastResource::collection($entry['skus'])->resolve()),
            ], $forecast->items),
            'meta' => self::meta($forecast),
        ]);
    }

    /**
     * 需要予測の計算の条件（販売実績の期間・対象品番・平均日販の期間と重み・イベント・季節係数を登録した品番）。需要予測の画面（B-131）でも使う。
     *
     * @return array<string, mixed>
     */
    public static function meta(DemandForecast $forecast): array
    {
        return [
            'calculated_at' => $forecast->calculatedAt->toIso8601String(),
            'channels' => array_map(fn (array $channel): array => [
                'channel' => $channel['channel']->value,
                'label' => $channel['channel']->label(),
                'sales_data_from' => $channel['from']?->toDateString(),
                'sales_data_to' => $channel['to']?->toDateString(),
            ], $forecast->channels),
            'forecast_from' => $forecast->forecastFrom->toDateString(),
            'selection' => app(TargetItems::class)->meta(TrendScope::Total),
            // 全体・Amazon・BOSS ごとの対象品番と説明（B-136、K-129・K-130）。画面で全体・Amazon・BOSS を切り替えたときに、その対象品番だけを出す
            'target_items' => app(TargetItems::class)->lists(),
            'target_selections' => array_combine(
                array_map(fn (TrendScope $scope): string => $scope->value, TrendScope::cases()),
                array_map(fn (TrendScope $scope): ?array => app(TargetItems::class)->meta($scope), TrendScope::cases()),
            ),
            'window_weights' => array_map(fn (int $length, float $weight): array => ['length' => $length, 'weight' => $weight], array_keys(DemandForecaster::WINDOW_WEIGHTS), DemandForecaster::WINDOW_WEIGHTS),
            'min_sales_days' => DemandForecaster::MIN_SALES_DAYS,
            // 平均日販から日を除き、これからの日に倍率を掛けたイベントの期間（B-129、K-112・K-114）
            'events' => $forecast->events->map(fn (SalesEventPeriod $period): array => [
                'id' => $period->id,
                'event_id' => $period->sales_event_id,
                'name' => $period->name,
                'starts_on' => $period->starts_on->toDateString(),
                'ends_on' => $period->ends_on->toDateString(),
                'channels' => array_map(fn (Channel $channel): string => $channel->value, $period->channels()),
                'status' => $period->status($forecast->forecastFrom)->value,
            ])->values()->all(),
            // 季節係数を登録した品番（B-106、K-113）
            'seasonal_item_nos' => array_keys($forecast->seasonalFactors),
            // 掛けている（これから掛ける）手動での補正（B-109、K-132）
            'adjustments' => array_values(array_map(
                fn (ForecastAdjustment $adjustment): array => ForecastAdjustmentResource::make($adjustment)->resolve(),
                array_merge(...array_values(array_map(fn (array $channels): array => array_merge(...array_values($channels)), $forecast->adjustments))),
            )),
        ];
    }
}
