<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Http\Resources\SkuForecastResource;
use App\Models\Item;
use App\Services\Forecast\DemandForecaster;
use App\Services\Forecast\SkuForecast;
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
                    'unit_prices' => $this->unitPrices($sku, $entry['item']->item_no, $prices),
                ], $entry['skus'], SkuForecastResource::collection($entry['skus'])->resolve()),
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

    /**
     * SKUの金額の概算に使う単価（税込）。合計は予測できたモールの基準平均日販で加重平均する。出せなければ null。
     *
     * @return array<string, float|null> total・Channel の値 => 単価
     */
    private function unitPrices(SkuForecast $sku, string $itemNo, UnitPriceCatalog $prices): array
    {
        $weights = [];
        foreach ($sku->channels as $channel) {
            if ($channel->isPredictable()) {
                $weights[$channel->channel->value] = $channel->baseAverage;
            }
        }
        $round = fn (?float $price): ?float => $price === null ? null : round($price, 2);
        $unitPrices = ['total' => $round($prices->blendedPrice($itemNo, $weights))];
        foreach (Channel::cases() as $channel) {
            $unitPrices[$channel->value] = $round($prices->channelPrice($itemNo, $channel));
        }

        return $unitPrices;
    }
}
