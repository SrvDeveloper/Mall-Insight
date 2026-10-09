<?php

namespace App\Services\Actuals;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;

/**
 * 販売実績と在庫実績の推移（バックログ B-124・B-127）。対象品番（最新の選定）のSKUについて、月ごと（直近12か月＋今月）か日ごと（直近90日）の販売数と、
 * 日ごとの在庫数（直近31日）を、全体・Amazon・BOSSのどれかで集める。取り込んだ実績は変えない（原則3）。
 *
 * - 販売数は販売実績の出荷倉庫でモールに分ける（K-038）。モールの販売実績を取り込んだ期間の外の月は0とせず、データ無しとする（原則2）。
 *   期間の途中で始まる・終わる月、取り込んでいないモールのある日、今月・今日は「途中まで」とする。
 * - 在庫数は在庫基準日ごとの、そのモールの在庫の区分の合計（全体は6区分、K-028）。在庫を取得していない日（調査の無い日など）と、
 *   その日にSKUの記録が無いときは0とせず、データ無しとする。
 * - 販売実績は販売数と金額（税込、取り込んだ金額の合計）を集める。
 * - SKUは廃番も含める（過去には売れているため）。
 */
class ActualsReader
{
    /** 販売実績の月数（直近12か月と今月）。 */
    public const SALES_MONTHS = 13;

    /** 日ごとの販売実績の日数（今日まで、B-127）。 */
    public const SALES_DAYS = 90;

    /** 期間を指定するときの上限（月ごとは36か月、日ごとは92日。グラフと表が読めなくならないように、K-080）。 */
    public const MAX_MONTHS = 36;

    public const MAX_DAYS = 92;

    /** 在庫実績の日数（今日まで）。 */
    public const STOCK_DAYS = 31;

    /**
     * 対象品番（選定の順）。SKUを読み込んだもの。$itemNo を指定すると、対象品番のうちその品番だけ（SKU別売上、B-127）。
     *
     * @return list<Item>
     */
    public function items(?string $itemNo = null): array
    {
        $itemNos = ItemSelection::current()?->items->pluck('item_no') ?? collect();
        if ($itemNo !== null) {
            $itemNos = $itemNos->filter(fn (string $selected): bool => $selected === $itemNo)->values();
        }
        if ($itemNos->isEmpty()) {
            return [];
        }
        $items = Item::query()->with('skus')->whereIn('item_no', $itemNos)->get()->keyBy('item_no');

        return $itemNos->map(fn (string $itemNo) => $items->get($itemNo))->filter()->values()->all();
    }

    /**
     * モールごとの販売実績の最初と最後の販売日。取り込んでいないモールは含めない。
     *
     * @return array<string, array{from: CarbonImmutable, to: CarbonImmutable}> Channel の値 => 期間
     */
    public function salesRanges(): array
    {
        $ranges = [];
        $rows = SalesLine::query()->selectRaw('warehouse, min(sales_date) as first_date, max(sales_date) as last_date')->groupBy('warehouse')->toBase()->get();
        foreach ($rows as $row) {
            $channel = Warehouse::tryFrom((string) $row->warehouse)?->channel();
            if ($channel === null) {
                continue;
            }
            $from = CarbonImmutable::parse($row->first_date)->startOfDay();
            $to = CarbonImmutable::parse($row->last_date)->startOfDay();
            $current = $ranges[$channel->value] ?? null;
            $ranges[$channel->value] = [
                'from' => $current === null || $from->lt($current['from']) ? $from : $current['from'],
                'to' => $current === null || $to->gt($current['to']) ? $to : $current['to'],
            ];
        }

        return $ranges;
    }

    /**
     * 月ごとか日ごとの販売数と金額（税込）。期間を指定しなければ、月ごとは直近12か月と今月、日ごとは直近90日（K-080）。
     *
     * @param  list<Item>  $items
     * @return array{periods: list<array{start: CarbonImmutable, coverage: Coverage}>, quantities: array<int, list<float|null>>, amounts: array<int, list<float|null>>, byChannel: array<int, array<string, array{quantities: list<float|null>, amounts: list<float|null>, previous: array{quantities: list<float|null>, amounts: list<float|null>}, last_year: array{quantities: list<float|null>, amounts: list<float|null>}}>>} SKU ID => 期間ごとの数（データ無しの期間は null）。byChannel は選んだモール（全体ならBOSS・Amazon）ごとの内訳。previous・last_year は前月（前日）と前年の比べる値（K-082）
     */
    public function sales(array $items, CarbonImmutable $today, TrendScope $scope, SalesUnit $unit = SalesUnit::Month, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): array
    {
        $today = $today->startOfDay();
        [$defaultFrom, $defaultTo] = $this->defaultRange($unit, $today);
        $from ??= $defaultFrom;
        $to ??= $defaultTo;
        $ranges = $this->salesRanges();
        $channels = array_values(array_filter($scope->channel() === null ? Channel::cases() : [$scope->channel()], fn (Channel $channel): bool => isset($ranges[$channel->value])));
        $skuIds = $this->skuIds($items);
        $warehouses = array_map(fn (Warehouse $warehouse): string => $warehouse->value, $scope->warehouses());

        $periods = [];
        foreach ($this->periods($unit, $today, $from, $to) as [$start, $end, $isIncomplete]) {
            $periods[] = ['start' => $start, 'end' => $end, 'coverage' => $this->coverage($channels, $ranges, $start, $end, $isIncomplete)];
        }

        // SKU ID => 期間の位置 => 合計（total）かモール => [販売数, 金額]
        $sold = [];
        $add = function (object $row, int $index) use (&$sold): void {
            $channel = Warehouse::tryFrom((string) $row->warehouse)?->channel();
            foreach (array_filter(['total', $channel?->value]) as $key) {
                $current = $sold[(int) $row->sku_id][$index][$key] ?? [0.0, 0.0];
                $sold[(int) $row->sku_id][$index][$key] = [$current[0] + (float) $row->quantity, $current[1] + (float) $row->amount];
            }
        };
        if ($skuIds !== []) {
            $periodIndex = $unit === SalesUnit::Day
                ? array_flip(array_map(fn (array $period): string => $period['start']->toDateString(), $periods))
                : null;
            foreach (array_chunk($skuIds, 500) as $chunk) {
                if ($periodIndex !== null) {
                    // 日ごとは1回で読み、日付で振り分ける
                    $rows = SalesLine::query()
                        ->selectRaw('sku_id, sales_date, warehouse, sum(quantity) as quantity, sum(amount) as amount')
                        ->whereIn('sku_id', $chunk)
                        ->whereIn('warehouse', $warehouses)
                        ->where('sales_date', '>=', $periods[0]['start']->toDateString())
                        ->where('sales_date', '<', $today->addDay()->toDateString())
                        ->groupBy('sku_id', 'sales_date', 'warehouse')
                        ->toBase()
                        ->get();
                    foreach ($rows as $row) {
                        $index = $periodIndex[CarbonImmutable::parse($row->sales_date)->toDateString()] ?? null;
                        if ($index !== null) {
                            $add($row, $index);
                        }
                    }

                    continue;
                }
                foreach ($periods as $index => $period) {
                    if ($period['coverage'] === Coverage::None) {
                        continue;
                    }
                    $rows = SalesLine::query()
                        ->selectRaw('sku_id, warehouse, sum(quantity) as quantity, sum(amount) as amount')
                        ->whereIn('sku_id', $chunk)
                        ->whereIn('warehouse', $warehouses)
                        ->where('sales_date', '>=', $period['start']->toDateString())
                        ->where('sales_date', '<', $period['end']->addDay()->toDateString())
                        ->groupBy('sku_id', 'warehouse')
                        ->toBase()
                        ->get();
                    foreach ($rows as $row) {
                        $add($row, $index);
                    }
                }
            }
        }

        $quantities = [];
        $amounts = [];
        $byChannel = [];
        $scopeChannels = $scope->channel() === null ? Channel::cases() : [$scope->channel()];
        foreach ($skuIds as $skuId) {
            foreach ($periods as $index => $period) {
                $hasData = $period['coverage'] !== Coverage::None;
                $quantities[$skuId][] = $hasData ? ($sold[$skuId][$index]['total'][0] ?? 0.0) : null;
                $amounts[$skuId][] = $hasData ? ($sold[$skuId][$index]['total'][1] ?? 0.0) : null;
                foreach ($scopeChannels as $channel) {
                    $byChannel[$skuId][$channel->value]['quantities'][] = $hasData ? ($sold[$skuId][$index][$channel->value][0] ?? 0.0) : null;
                    $byChannel[$skuId][$channel->value]['amounts'][] = $hasData ? ($sold[$skuId][$index][$channel->value][1] ?? 0.0) : null;
                }
            }
        }
        foreach ($this->comparisons($unit, $periods, $scopeChannels, $ranges, $skuIds, $warehouses) as $skuId => $channelComparisons) {
            foreach ($channelComparisons as $channel => $comparison) {
                $byChannel[$skuId][$channel] += $comparison;
            }
        }

        return [
            'periods' => array_map(fn (array $period): array => ['start' => $period['start'], 'coverage' => $period['coverage']], $periods),
            'quantities' => $quantities,
            'amounts' => $amounts,
            'byChannel' => $byChannel,
        ];
    }

    /**
     * 前の期間（月ごとは前月、日ごとは前日）と前年の同じ期間の販売数と金額（グラフのツールチップの前月比・前年比、K-082）。
     * モールごとに、そのモールの販売実績がある日までと同じ日数で比べる（今月なら前月・前年の同じ月の1日から同じ日まで）。
     * そのモールの販売実績が期間の初日からそろっていない期間と、比べる期間の販売実績を一部でも取り込んでいないときは null（原則2）。
     *
     * @param  list<array{start: CarbonImmutable, end: CarbonImmutable, coverage: Coverage}>  $periods
     * @param  list<Channel>  $channels
     * @param  array<string, array{from: CarbonImmutable, to: CarbonImmutable}>  $ranges
     * @param  list<int>  $skuIds
     * @param  list<string>  $warehouses
     * @return array<int, array<string, array{previous: array{quantities: list<float|null>, amounts: list<float|null>}, last_year: array{quantities: list<float|null>, amounts: list<float|null>}}>>
     */
    private function comparisons(SalesUnit $unit, array $periods, array $channels, array $ranges, array $skuIds, array $warehouses): array
    {
        // 期間の位置 => モール => 比べ方 => 比べる期間の日付（比べられなければ null）
        $windows = [];
        $first = null;
        $last = null;
        foreach ($periods as $index => $period) {
            foreach ($channels as $channel) {
                $range = $ranges[$channel->value] ?? null;
                $end = $range === null ? null : $period['end']->min($range['to']);
                foreach (['previous', 'last_year'] as $kind) {
                    $windows[$index][$channel->value][$kind] = null;
                    if ($range === null || $range['from']->gt($period['start']) || $end->lt($period['start'])) {
                        continue;
                    }
                    $start = match (true) {
                        $kind === 'last_year' => $period['start']->subYearNoOverflow(),
                        $unit === SalesUnit::Day => $period['start']->subDay(),
                        default => $period['start']->subMonthNoOverflow(),
                    };
                    $periodEnd = $unit === SalesUnit::Day ? $start : $start->endOfMonth()->startOfDay();
                    $windowEnd = $start->addDays((int) $period['start']->diffInDays($end))->min($periodEnd);
                    if ($range['from']->gt($start) || $range['to']->lt($windowEnd)) {
                        continue;
                    }
                    $dates = [];
                    for ($day = $start; $day->lte($windowEnd); $day = $day->addDay()) {
                        $dates[] = $day->toDateString();
                    }
                    $windows[$index][$channel->value][$kind] = $dates;
                    $first = $first === null ? $start : $first->min($start);
                    $last = $last === null ? $windowEnd : $last->max($windowEnd);
                }
            }
        }

        // SKU ID => モール => 日付 => [販売数, 金額]
        $daily = [];
        if ($first !== null && $skuIds !== []) {
            foreach (array_chunk($skuIds, 500) as $chunk) {
                $rows = SalesLine::query()
                    ->selectRaw('sku_id, sales_date, warehouse, sum(quantity) as quantity, sum(amount) as amount')
                    ->whereIn('sku_id', $chunk)
                    ->whereIn('warehouse', $warehouses)
                    ->where('sales_date', '>=', $first->toDateString())
                    ->where('sales_date', '<', $last->addDay()->toDateString())
                    ->groupBy('sku_id', 'sales_date', 'warehouse')
                    ->toBase()
                    ->get();
                foreach ($rows as $row) {
                    $channel = Warehouse::tryFrom((string) $row->warehouse)?->channel();
                    if ($channel === null) {
                        continue;
                    }
                    $date = CarbonImmutable::parse($row->sales_date)->toDateString();
                    $current = $daily[(int) $row->sku_id][$channel->value][$date] ?? [0.0, 0.0];
                    $daily[(int) $row->sku_id][$channel->value][$date] = [$current[0] + (float) $row->quantity, $current[1] + (float) $row->amount];
                }
            }
        }

        $comparisons = [];
        foreach ($skuIds as $skuId) {
            foreach ($channels as $channel) {
                $sold = $daily[$skuId][$channel->value] ?? [];
                foreach (['previous', 'last_year'] as $kind) {
                    $comparisons[$skuId][$channel->value][$kind] = ['quantities' => [], 'amounts' => []];
                    foreach (array_keys($periods) as $index) {
                        $dates = $windows[$index][$channel->value][$kind];
                        $total = $dates === null ? null : [0.0, 0.0];
                        foreach ($dates ?? [] as $date) {
                            if (isset($sold[$date])) {
                                $total = [$total[0] + $sold[$date][0], $total[1] + $sold[$date][1]];
                            }
                        }
                        $comparisons[$skuId][$channel->value][$kind]['quantities'][] = $total[0] ?? null;
                        $comparisons[$skuId][$channel->value][$kind]['amounts'][] = $total[1] ?? null;
                    }
                }
            }
        }

        return $comparisons;
    }

    /**
     * 期間を指定しないときの最初と最後（月ごとは月の初日）。
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function defaultRange(SalesUnit $unit, CarbonImmutable $today): array
    {
        $today = $today->startOfDay();

        return $unit === SalesUnit::Day
            ? [$today->subDays(self::SALES_DAYS - 1), $today]
            : [$today->startOfMonth()->subMonthsNoOverflow(self::SALES_MONTHS - 1), $today->startOfMonth()];
    }

    /**
     * 集める期間（$from～$to、月ごとは月の初日）の初日・末日と、今日を含んでまだ終わっていないか。
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable, 2: bool}>
     */
    private function periods(SalesUnit $unit, CarbonImmutable $today, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $periods = [];
        if ($unit === SalesUnit::Day) {
            for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
                $periods[] = [$day, $day, $day->equalTo($today)];
            }

            return $periods;
        }
        for ($start = $from->startOfMonth(); $start->lte($to); $start = $start->addMonthNoOverflow()) {
            $monthEnd = $start->endOfMonth()->startOfDay();
            $periods[] = [$start, $monthEnd->min($today), $today->lt($monthEnd)];
        }

        return $periods;
    }

    /**
     * 日ごとの在庫数。
     *
     * @param  list<Item>  $items
     * @return array{dates: list<array{date: CarbonImmutable, hasData: bool}>, quantities: array<int, list<int|null>>} quantities は SKU ID => 日ごとの在庫数（データ無しの日は null）
     */
    public function stock(array $items, CarbonImmutable $today, TrendScope $scope): array
    {
        $to = $today->startOfDay();
        $from = $to->subDays(self::STOCK_DAYS - 1);
        $skuIds = $this->skuIds($items);
        $warehouses = array_map(fn (Warehouse $warehouse): string => $warehouse->value, $scope->warehouses());

        // 在庫を取得した日（どのSKUでも記録のある日）
        $dataDates = Inventory::query()
            ->select('stock_date')
            ->distinct()
            ->where('stock_date', '>=', $from->toDateString())
            ->where('stock_date', '<', $to->addDay()->toDateString())
            ->pluck('stock_date')
            ->map(fn ($date): string => CarbonImmutable::parse($date)->toDateString())
            ->flip();

        $stocks = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = Inventory::query()
                ->select(['sku_id', 'stock_date', 'quantity'])
                ->whereIn('sku_id', $chunk)
                ->whereIn('warehouse', $warehouses)
                ->where('stock_date', '>=', $from->toDateString())
                ->where('stock_date', '<', $to->addDay()->toDateString())
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $date = CarbonImmutable::parse($row->stock_date)->toDateString();
                $stocks[(int) $row->sku_id][$date] = ($stocks[(int) $row->sku_id][$date] ?? 0) + (int) $row->quantity;
            }
        }

        $dates = [];
        $quantities = array_fill_keys($skuIds, []);
        foreach (range(0, self::STOCK_DAYS - 1) as $index) {
            $date = $from->addDays($index);
            $key = $date->toDateString();
            $dates[] = ['date' => $date, 'hasData' => $dataDates->has($key)];
            foreach ($skuIds as $skuId) {
                $quantities[$skuId][] = $stocks[$skuId][$key] ?? null;
            }
        }

        return ['dates' => $dates, 'quantities' => $quantities];
    }

    /**
     * 期間（start～end、月か日）の販売実績がそろっているか。対象のモールのどれも期間に入っていなければデータ無し、
     * どれかの期間が途中で始まる・終わる（今月・今日を含む）なら途中まで。
     *
     * @param  list<Channel>  $channels  販売実績を取り込んだ、対象のモール
     * @param  array<string, array{from: CarbonImmutable, to: CarbonImmutable}>  $ranges
     */
    private function coverage(array $channels, array $ranges, CarbonImmutable $start, CarbonImmutable $end, bool $isIncomplete): Coverage
    {
        $overlapping = array_filter($channels, fn (Channel $channel): bool => $ranges[$channel->value]['from']->lte($end) && $ranges[$channel->value]['to']->gte($start));
        if ($overlapping === []) {
            return Coverage::None;
        }
        $isFull = ! $isIncomplete && count($overlapping) === count($channels)
            && array_reduce($channels, fn (bool $carry, Channel $channel): bool => $carry && $ranges[$channel->value]['from']->lte($start) && $ranges[$channel->value]['to']->gte($end), true);

        return $isFull ? Coverage::Full : Coverage::Partial;
    }

    /**
     * @param  list<Item>  $items
     * @return list<int>
     */
    private function skuIds(array $items): array
    {
        $skuIds = [];
        foreach ($items as $item) {
            foreach ($item->skus as $sku) {
                $skuIds[] = $sku->id;
            }
        }

        return $skuIds;
    }
}
