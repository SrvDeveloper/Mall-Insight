<?php

namespace App\Services\ItemSelection;

use App\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

/**
 * 品番の売上順位を求める（決定記録 K-032）。直近12か月の販売実績の金額を、品番に属するSKUから品番へ合計する。
 * 同額は同順位とし、次の順位は同順位の件数分進める（1位、2位、2位、4位）。
 */
class ItemRanker
{
    public function rank(CarbonImmutable $today): ItemRanking
    {
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
                ->where('sales_lines.sales_date', '<', $to->addDay()->toDateString()))
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
            $ranks[] = new ItemRank($item, $amount, (int) $item->sales_quantity, $rank);
        }

        foreach ($unsold->sortBy('item_no') as $item) {
            $ranks[] = new ItemRank($item, null, null, null);
        }

        return new ItemRanking($from, $to, $ranks);
    }
}
