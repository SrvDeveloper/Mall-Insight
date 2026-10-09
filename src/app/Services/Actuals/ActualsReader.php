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
 * 販売実績と在庫実績の推移（バックログ B-124）。対象品番（最新の選定）のSKUについて、月ごとの販売数（直近12か月＋今月）と、
 * 日ごとの在庫数（直近31日）を、全体・Amazon・BOSSのどれかで集める。取り込んだ実績は変えない（原則3）。
 *
 * - 販売数は販売実績の出荷倉庫でモールに分ける（K-038）。モールの販売実績を取り込んだ期間の外の月は0とせず、データ無しとする（原則2）。
 *   期間の途中で始まる・終わる月と今月は「途中まで」とする。
 * - 在庫数は在庫基準日ごとの、そのモールの在庫の区分の合計（全体は6区分、K-028）。在庫を取得していない日（調査の無い日など）と、
 *   その日にSKUの記録が無いときは0とせず、データ無しとする。
 * - 販売実績は販売数と金額（税込、取り込んだ金額の合計）を集める。
 * - SKUは廃番も含める（過去には売れているため）。
 */
class ActualsReader
{
    /** 販売実績の月数（直近12か月と今月）。 */
    public const SALES_MONTHS = 13;

    /** 在庫実績の日数（今日まで）。 */
    public const STOCK_DAYS = 31;

    /**
     * 対象品番（選定の順）。SKUを読み込んだもの。
     *
     * @return list<Item>
     */
    public function items(): array
    {
        $itemNos = ItemSelection::current()?->items->pluck('item_no') ?? collect();
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
     * 月ごとの販売数と金額（税込）。
     *
     * @param  list<Item>  $items
     * @return array{months: list<array{month: CarbonImmutable, coverage: Coverage}>, quantities: array<int, list<float|null>>, amounts: array<int, list<float|null>>} SKU ID => 月ごとの数（データ無しの月は null）
     */
    public function sales(array $items, CarbonImmutable $today, TrendScope $scope): array
    {
        $today = $today->startOfDay();
        $ranges = $this->salesRanges();
        $channels = array_values(array_filter($scope->channel() === null ? Channel::cases() : [$scope->channel()], fn (Channel $channel): bool => isset($ranges[$channel->value])));
        $skuIds = $this->skuIds($items);
        $warehouses = array_map(fn (Warehouse $warehouse): string => $warehouse->value, $scope->warehouses());

        $months = [];
        $quantities = array_fill_keys($skuIds, []);
        $amounts = array_fill_keys($skuIds, []);
        $first = $today->startOfMonth()->subMonthsNoOverflow(self::SALES_MONTHS - 1);
        foreach (range(0, self::SALES_MONTHS - 1) as $index) {
            $start = $first->addMonthsNoOverflow($index);
            $end = $start->endOfMonth()->startOfDay()->min($today);
            $coverage = $this->coverage($channels, $ranges, $start, $end, $end->lt($start->endOfMonth()->startOfDay()));
            $months[] = ['month' => $start, 'coverage' => $coverage];

            $sold = [];
            $sales = [];
            if ($coverage !== Coverage::None && $skuIds !== []) {
                foreach (array_chunk($skuIds, 500) as $chunk) {
                    $rows = SalesLine::query()
                        ->selectRaw('sku_id, sum(quantity) as quantity, sum(amount) as amount')
                        ->whereIn('sku_id', $chunk)
                        ->whereIn('warehouse', $warehouses)
                        ->where('sales_date', '>=', $start->toDateString())
                        ->where('sales_date', '<', $end->addDay()->toDateString())
                        ->groupBy('sku_id')
                        ->toBase()
                        ->get();
                    foreach ($rows as $row) {
                        $sold[(int) $row->sku_id] = (float) $row->quantity;
                        $sales[(int) $row->sku_id] = (float) $row->amount;
                    }
                }
            }
            foreach ($skuIds as $skuId) {
                $quantities[$skuId][] = $coverage === Coverage::None ? null : ($sold[$skuId] ?? 0.0);
                $amounts[$skuId][] = $coverage === Coverage::None ? null : ($sales[$skuId] ?? 0.0);
            }
        }

        return ['months' => $months, 'quantities' => $quantities, 'amounts' => $amounts];
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
     * 月（start～end）の販売実績がそろっているか。対象のモールのどれも期間に入っていなければデータ無し、
     * どれかの期間が月の途中で始まる・終わる（今月を含む）なら途中まで。
     *
     * @param  list<Channel>  $channels  販売実績を取り込んだ、対象のモール
     * @param  array<string, array{from: CarbonImmutable, to: CarbonImmutable}>  $ranges
     */
    private function coverage(array $channels, array $ranges, CarbonImmutable $start, CarbonImmutable $end, bool $isCurrentMonth): Coverage
    {
        $overlapping = array_filter($channels, fn (Channel $channel): bool => $ranges[$channel->value]['from']->lte($end) && $ranges[$channel->value]['to']->gte($start));
        if ($overlapping === []) {
            return Coverage::None;
        }
        $isFull = ! $isCurrentMonth && count($overlapping) === count($channels)
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
