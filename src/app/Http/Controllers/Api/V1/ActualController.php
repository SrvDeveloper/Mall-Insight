<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\Sku;
use App\Services\Actuals\ActualsReader;
use App\Services\Forecast\DemandForecaster;
use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ActualController extends Controller
{
    /**
     * 対象品番のSKUの、月ごとの販売数と金額（税込）（直近12か月＋今月、バックログ B-124）。scope で全体（初期値）・Amazon・BOSSを選ぶ。
     * 販売実績を取り込んでいない月は null。
     */
    public function sales(Request $request, ActualsReader $reader): JsonResponse
    {
        $scope = $this->scope($request);
        $items = $reader->items();
        $sales = $reader->sales($items, CarbonImmutable::now(), $scope);
        $ranges = $reader->salesRanges();

        return response()->json([
            'data' => $this->rows($items, $sales['quantities'], $scope, $sales['amounts']),
            'meta' => [
                ...$this->meta($scope),
                'months' => array_map(fn (array $month): array => ['month' => $month['month']->format('Y-m'), 'coverage' => $month['coverage']->value], $sales['months']),
                'channels' => array_map(fn (Channel $channel): array => [
                    'channel' => $channel->value,
                    'label' => $channel->label(),
                    'sales_data_from' => ($ranges[$channel->value]['from'] ?? null)?->toDateString(),
                    'sales_data_to' => ($ranges[$channel->value]['to'] ?? null)?->toDateString(),
                ], Channel::cases()),
            ],
        ]);
    }

    /**
     * 対象品番のSKUの、日ごとの在庫数（直近31日、バックログ B-124）。scope で全体（6区分の合計）・Amazon・BOSSを選ぶ。
     * 在庫を取得していない日と、その日にSKUの記録が無いときは null。
     */
    public function stock(Request $request, ActualsReader $reader): JsonResponse
    {
        $scope = $this->scope($request);
        $items = $reader->items();
        $stock = $reader->stock($items, CarbonImmutable::now(), $scope);

        return response()->json([
            'data' => $this->rows($items, $stock['quantities'], $scope),
            'meta' => [
                ...$this->meta($scope),
                'scope_warehouses' => array_map(fn (Warehouse $warehouse): array => ['warehouse' => $warehouse->value, 'label' => $warehouse->label()], $scope->warehouses()),
                'dates' => array_map(fn (array $date): array => ['date' => $date['date']->toDateString(), 'has_data' => $date['hasData']], $stock['dates']),
            ],
        ]);
    }

    private function scope(Request $request): TrendScope
    {
        $request->validate(['scope' => ['nullable', Rule::enum(TrendScope::class)]]);

        return $request->enum('scope', TrendScope::class) ?? TrendScope::Total;
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(TrendScope $scope): array
    {
        $selection = ItemSelection::current();

        return [
            'calculated_at' => CarbonImmutable::now()->toIso8601String(),
            'scope' => $scope->value,
            'scope_label' => $scope->label(),
            'selection' => $selection === null ? null : [
                'confirmed_at' => $selection->confirmed_at->toIso8601String(),
                'item_count' => $selection->items->count(),
            ],
        ];
    }

    /**
     * 品番ごとのSKUの行。そのモールで売っていないSKU（子ASINの無いSKUのAmazon、K-042）は not_sold にする。
     *
     * @param  list<Item>  $items
     * @param  array<int, list<float|int|null>>  $quantities
     * @param  array<int, list<float|null>>|null  $amounts  販売実績のときだけ、月ごとの金額（税込）
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, array $quantities, TrendScope $scope, ?array $amounts = null): array
    {
        return array_map(fn (Item $item): array => [
            'item_no' => $item->item_no,
            'brand' => $item->brand,
            'category' => $item->category,
            'skus' => $item->skus->map(fn (Sku $sku): array => [
                'sku_id' => $sku->id,
                'sku_code' => $sku->sku_code,
                'status' => $sku->status?->value,
                'status_label' => $sku->status?->skuLabel(),
                'not_sold' => $scope->channel() !== null && ! DemandForecaster::sellsOn($sku, $scope->channel()),
                'quantities' => $quantities[$sku->id] ?? [],
                ...($amounts === null ? [] : ['amounts' => $amounts[$sku->id] ?? []]),
            ])->all(),
        ], $items);
    }
}
