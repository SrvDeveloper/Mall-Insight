<?php

namespace App\Services\ItemSelection;

use App\Enums\Warehouse;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * 品番の売上順位を求める（決定記録 K-032）。直近12か月の販売実績の金額を、品番に属するSKUから品番へ合計する。
 * 同額は同順位とし、次の順位は同順位の件数分進める（1位、2位、2位、4位）。
 * Amazon・BOSS の対象品番（B-136、K-129）では、そのチャネルの出荷倉庫の販売実績だけで順位を付ける。
 */
class ItemRanker
{
    public function rank(CarbonImmutable $today, TrendScope $scope = TrendScope::Total): ItemRanking
    {
        $warehouses = $scope->channel() === null ? null : array_map(fn (Warehouse $warehouse): string => $warehouse->value, $scope->channel()->warehouses());
        $candidateRank = ItemSelection::candidateRank($scope);
        $to = $today->startOfDay();
        $from = $to->subYear()->addDay();

        $items = Item::query()
            ->select('items.*')
            ->withCount('skus')
            ->selectRaw('sum(sales_lines.amount) as sales_amount, sum(sales_lines.quantity) as sales_quantity, count(sales_lines.id) as sales_line_count')
            ->leftJoin('skus', 'skus.item_id', '=', 'items.id')
            ->leftJoin('sales_lines', fn (JoinClause $join) => $join
                ->on('sales_lines.sku_id', '=', 'skus.id')
                ->where('sales_lines.sales_date', '>=', $from->toDateString())
                ->where('sales_lines.sales_date', '<', $to->addDay()->toDateString())
                ->when($warehouses !== null, fn (JoinClause $join) => $join->whereIn('sales_lines.warehouse', $warehouses)))
            ->groupBy('items.id')
            ->get();

        [$sold, $unsold] = $items->partition(fn (Item $item) => (int) $item->sales_line_count > 0);

        $ranks = [];
        $previousAmount = null;
        $rank = 0;
        foreach ($sold->sortBy([['sales_amount', 'desc'], ['item_no', 'asc']])->values() as $position => $item) {
            $amount = (int) $item->sales_amount;
            if ($amount !== $previousAmount) {
                $rank = $position + 1;
                $previousAmount = $amount;
            }
            $ranks[] = new ItemRank($item, $amount, (int) $item->sales_quantity, $rank, $candidateRank);
        }

        foreach ($unsold->sortBy('item_no') as $item) {
            $ranks[] = new ItemRank($item, null, null, null, $candidateRank);
        }

        return new ItemRanking($from, $to, $ranks);
    }
}
