<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Enums\Warehouse;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\Sku;
use App\Services\Actuals\ActualsReader;
use App\Services\Actuals\Coverage;
use App\Services\Actuals\SalesUnit;
use App\Services\Actuals\StockoutHistory;
use App\Services\Actuals\StoreSalesReader;
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
     * 在庫を取得していない日と、その日にSKUの記録が無いときは null。在庫を取得していない日は、販売実績から推定した在庫を
     * estimates に、推定できたかと元にした在庫基準日を meta.dates に返す（K-087）。
     */
    public function stock(Request $request, ActualsReader $reader): JsonResponse
    {
        $scope = $this->scope($request);
        $items = $reader->items();
        $stock = $reader->stock($items, CarbonImmutable::now(), $scope);

        return response()->json([
            'data' => $this->rows($items, $stock['quantities'], $scope, estimates: $stock['estimates']),
            'meta' => [
                ...$this->meta($scope),
                'scope_warehouses' => array_map(fn (Warehouse $warehouse): array => ['warehouse' => $warehouse->value, 'label' => $warehouse->label()], $scope->warehouses()),
                'dates' => array_map(fn (array $date): array => [
                    'date' => $date['date']->toDateString(),
                    'has_data' => $date['hasData'],
                    'estimate' => $date['estimate']?->value,
                    'estimate_base_date' => $date['baseDate']?->toDateString(),
                ], $stock['dates']),
            ],
        ]);
    }

    /**
     * 店舗ごとの販売数と金額（税込）（バックログ B-126）。unit・from・to は販売実績（sales）と同じ。products で全商品（初期値、CrossWalkerに
     * 未登録のSKUを含む）か対象品番（target）だけかを選ぶ（K-088）。店舗の販売実績を取り込んでいない期間は null。
     * previous・last_year は前月（前日）と前年の比べる値（K-082）。
     */
    public function stores(Request $request, ActualsReader $reader, StoreSalesReader $stores): JsonResponse
    {
        $request->validate(['unit' => ['nullable', Rule::enum(SalesUnit::class)], 'products' => ['nullable', Rule::in(['all', 'target'])]]);
        $unit = $request->enum('unit', SalesUnit::class) ?? SalesUnit::Month;
        $isTarget = $request->input('products') === 'target';
        $now = CarbonImmutable::now();
        [$from, $to] = $this->period($request, $reader, $unit, $now);
        $sales = $stores->sales($now, $unit, $from, $to, $isTarget ? $this->targetSkuIds($reader) : null);
        $ranges = $reader->salesRanges();
        $format = fn (CarbonImmutable $date): string => $unit === SalesUnit::Month ? $date->format('Y-m') : $date->toDateString();

        return response()->json([
            'data' => array_map(fn (Mall $mall): array => [
                'mall' => $mall->value,
                'label' => $mall->label(),
                'channel' => $mall->channel()->value,
                'coverage' => array_map(fn (Coverage $coverage): string => $coverage->value, $sales['stores'][$mall->value]['coverage']),
                'quantities' => $sales['stores'][$mall->value]['quantities'],
                'amounts' => $sales['stores'][$mall->value]['amounts'],
                'previous' => $sales['stores'][$mall->value]['previous'],
                'last_year' => $sales['stores'][$mall->value]['last_year'],
            ], Mall::displayOrder()),
            'meta' => [
                'calculated_at' => $now->toIso8601String(),
                'unit' => $unit->value,
                'from' => $format($from),
                'to' => $format($to),
                'products' => $isTarget ? 'target' : 'all',
                'selection' => $this->meta(TrendScope::Total)['selection'],
                'periods' => array_map(fn (array $period): array => ['period' => $format($period['start']), 'coverage' => $period['coverage']->value], $sales['periods']),
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
     * 店舗売上の月・日の欄の、売上上位の品番とSKU（各10件、K-091）。period は月ごとなら YYYY-MM、日ごとなら YYYY-MM-DD（今月・今日まで）。
     * mall を指定するとその店舗だけ、無ければ全店舗。products は店舗売上と同じ。order（amount（初期値）か quantity）の多い順。
     * CrossWalkerに未登録のSKUは、品番の順位に入れず unregistered にまとめる。各行に、前の期間の順位（previous_rank）とチャネルごとの欠品日数（stockouts）を添える（K-095）。
     */
    public function storeRanking(Request $request, ActualsReader $reader, StoreSalesReader $stores): JsonResponse
    {
        $request->validate([
            'unit' => ['nullable', Rule::enum(SalesUnit::class)],
            'mall' => ['nullable', Rule::enum(Mall::class)],
            'products' => ['nullable', Rule::in(['all', 'target'])],
            'order' => ['nullable', Rule::in(['amount', 'quantity'])],
        ]);
        $unit = $request->enum('unit', SalesUnit::class) ?? SalesUnit::Month;
        $isMonth = $unit === SalesUnit::Month;
        $format = $isMonth ? 'Y-m' : 'Y-m-d';
        $request->validate(['period' => ['required', "date_format:{$format}"]], [], ['period' => '期間']);
        $now = CarbonImmutable::now();
        $period = CarbonImmutable::createFromFormat("!{$format}", $request->string('period')->toString());
        if ($period->gt($isMonth ? $now->startOfMonth()->startOfDay() : $now->startOfDay())) {
            throw ValidationException::withMessages(['period' => $isMonth ? '期間は今月までにしてください。' : '期間は今日までにしてください。']);
        }
        $mall = $request->enum('mall', Mall::class);
        $isTarget = $request->input('products') === 'target';
        $order = $request->input('order') === 'quantity' ? 'quantity' : 'amount';
        $ranking = $stores->ranking($now, $unit, $period, $mall, $isTarget ? $this->targetSkuIds($reader) : null, $order);

        return response()->json([
            'data' => ['items' => $ranking['items'], 'skus' => $ranking['skus'], 'unregistered' => $ranking['unregistered']],
            'meta' => [
                'unit' => $unit->value,
                'period' => $period->format($format),
                'from' => $ranking['start']->toDateString(),
                'to' => $ranking['end']->toDateString(),
                'mall' => $mall?->value,
                'mall_label' => $mall?->label() ?? '全店舗',
                'products' => $isTarget ? 'target' : 'all',
                'order' => $order,
                'total' => $ranking['total'],
                // 順位の変動を比べる前の期間（前月・前日）。販売実績を取り込んでいなければ available は false
                'previous' => ['from' => $ranking['previous']['start']->toDateString(), 'to' => $ranking['previous']['end']->toDateString(), 'available' => $ranking['previous']['available']],
                // 欠品日数を数えるチャネル（店舗のチャネル。全店舗なら BOSS と Amazon）
                'stockout_channels' => array_map(fn (Channel $channel): array => ['channel' => $channel->value, 'label' => $channel->label()], $ranking['channels']),
            ],
        ]);
    }

    /**
     * 対象品番（最新の選定）のSKU。
     *
     * @return list<int>
     */
    private function targetSkuIds(ActualsReader $reader): array
    {
        $skuIds = [];
        foreach ($reader->items() as $item) {
            foreach ($item->skus as $sku) {
                $skuIds[] = $sku->id;
            }
        }

        return $skuIds;
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
     * @param  array<int, list<array{quantity: int, base_quantity: int, sold: int}|null>>|null  $estimates  在庫実績のときだけ、在庫を取得していない日の推定在庫（K-087）
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, array $quantities, TrendScope $scope, ?array $amounts = null, ?array $byChannel = null, ?array $estimates = null): array
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
                ...($estimates === null ? [] : ['estimates' => $estimates[$sku->id] ?? []]),
            ])->all(),
        ], $items);
    }
}
