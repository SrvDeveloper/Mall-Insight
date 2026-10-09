<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\Sku;
use App\Services\Actuals\ActualsReader;
use App\Services\Actuals\SalesUnit;
use App\Services\Actuals\StockoutHistory;
use App\Services\Forecast\DemandForecaster;
use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActualController extends Controller
{
    /**
     * 対象品番のSKUの、販売数と金額（税込）（バックログ B-124・B-127）。unit で月ごと（初期値、直近12か月＋今月）か日ごと（直近30日）、
     * from・to で期間（月ごとは YYYY-MM、日ごとは YYYY-MM-DD。今日（今月）まで、36か月・92日以内、K-080）、
     * scope で全体（初期値）・Amazon・BOSSを選ぶ。item_no を指定すると、対象品番のうちその品番だけを返す（SKU別売上）。
     * 販売実績を取り込んでいない期間は null。
     * SKU別売上の月ごとには、モールごとの欠品日数と判定できた日数を添える（K-084）。
     */
    public function sales(Request $request, ActualsReader $reader, StockoutHistory $stockouts): JsonResponse
    {
        $scope = $this->scope($request);
        $request->validate(['unit' => ['nullable', Rule::enum(SalesUnit::class)], 'item_no' => ['nullable', 'string', 'max:255']]);
        $unit = $request->enum('unit', SalesUnit::class) ?? SalesUnit::Month;
        $now = CarbonImmutable::now();
        [$from, $to] = $this->period($request, $reader, $unit, $now);
        $items = $reader->items($request->filled('item_no') ? $request->string('item_no')->toString() : null);
        $sales = $reader->sales($items, $now, $scope, $unit, $from, $to);
        $ranges = $reader->salesRanges();

        if ($unit === SalesUnit::Month && $request->filled('item_no')) {
            $channels = $scope->channel() === null ? Channel::cases() : [$scope->channel()];
            foreach ($channels as $channel) {
                $skuIds = [];
                foreach ($items as $item) {
                    foreach ($item->skus as $sku) {
                        if (DemandForecaster::sellsOn($sku, $channel)) {
                            $skuIds[] = $sku->id;
                        }
                    }
                }
                foreach ($stockouts->monthlyCounts($skuIds, $from, $to, $now, $channel) as $skuId => $counts) {
                    $sales['byChannel'][$skuId][$channel->value]['stockouts'] = $counts;
                }
            }
        }

        return response()->json([
            'data' => $this->rows($items, $sales['quantities'], $scope, $sales['amounts'], $sales['byChannel']),
            'meta' => [
                ...$this->meta($scope),
                'unit' => $unit->value,
                'from' => $unit === SalesUnit::Month ? $from->format('Y-m') : $from->toDateString(),
                'to' => $unit === SalesUnit::Month ? $to->format('Y-m') : $to->toDateString(),
                'periods' => array_map(fn (array $period): array => [
                    'period' => $unit === SalesUnit::Month ? $period['start']->format('Y-m') : $period['start']->toDateString(),
                    'coverage' => $period['coverage']->value,
                ], $sales['periods']),
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

    /**
     * 指定した期間（無ければ初期値）。開始が終了より後、今日（今月）より先、長すぎる期間は受け付けない（K-080）。
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function period(Request $request, ActualsReader $reader, SalesUnit $unit, CarbonImmutable $now): array
    {
        $isMonth = $unit === SalesUnit::Month;
        $format = $isMonth ? 'Y-m' : 'Y-m-d';
        $request->validate(['from' => ['nullable', "date_format:{$format}"], 'to' => ['nullable', "date_format:{$format}"]], [], ['from' => '開始', 'to' => '終了']);
        [$from, $to] = $reader->defaultRange($unit, $now);
        $from = $request->filled('from') ? CarbonImmutable::createFromFormat("!{$format}", $request->string('from')->toString()) : $from;
        $to = $request->filled('to') ? CarbonImmutable::createFromFormat("!{$format}", $request->string('to')->toString()) : $to;
        $latest = $isMonth ? $now->startOfMonth()->startOfDay() : $now->startOfDay();
        $length = $isMonth ? (int) $from->diffInMonths($to) + 1 : (int) $from->diffInDays($to) + 1;

        $errors = match (true) {
            $from->gt($to) => ['from' => '開始は終了と同じか、それより前にしてください。'],
            $to->gt($latest) => ['to' => $isMonth ? '終了は今月までにしてください。' : '終了は今日までにしてください。'],
            $isMonth && $length > ActualsReader::MAX_MONTHS => ['from' => '期間は'.ActualsReader::MAX_MONTHS.'か月以内にしてください。'],
            ! $isMonth && $length > ActualsReader::MAX_DAYS => ['from' => '期間は'.ActualsReader::MAX_DAYS.'日以内にしてください。'],
            default => [],
        };
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [$from, $to];
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
     * @param  array<int, array<string, array{quantities: list<float|null>, amounts: list<float|null>}>>|null  $byChannel  販売実績のときだけ、モールごとの内訳（グラフの積み上げに使う）
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, array $quantities, TrendScope $scope, ?array $amounts = null, ?array $byChannel = null): array
    {
        return array_map(fn (Item $item): array => [
            'item_no' => $item->item_no,
            'brand' => $item->brand,
            'category' => $item->category,
            'skus' => $item->skus->map(fn (Sku $sku): array => [
                'sku_id' => $sku->id,
                'sku_code' => $sku->sku_code,
                // カラー×度数の表に使う（CrossWalker の TQカラーNo・TQサイズ。度数の無いSKUはサイズが 0）
                'color_no' => $sku->tq_color_no,
                'size' => $sku->tq_size,
                'status' => $sku->status?->value,
                'status_label' => $sku->status?->skuLabel(),
                'not_sold' => $scope->channel() !== null && ! DemandForecaster::sellsOn($sku, $scope->channel()),
                'quantities' => $quantities[$sku->id] ?? [],
                ...($amounts === null ? [] : ['amounts' => $amounts[$sku->id] ?? []]),
                ...($byChannel === null ? [] : ['channels' => $byChannel[$sku->id] ?? []]),
            ])->all(),
        ], $items);
    }
}
