<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 季節係数（B-106、決定記録 K-113）の初期値を、過去の販売実績から求める。需要予測には、担当者が登録した係数だけを使う
 * （在庫の記録が2026年9月16日からで過去の欠品を除けず、実績から求めた係数では予測が外れたため）。
 *
 * - チャネルごとに、月ごとの平均日販（イベントの日を除く）を、その月を真ん中にした12か月の移動平均で割り（中心化移動平均比率）、
 *   伸びている・落ちている分を取り除いた「その月が普段の何倍か」を求める。前後6か月の販売実績がそろっている月だけ使う。
 * - 同じ月が何年かあれば、新しい年を重くして平均する（0.5・0.3・0.2）。BOSS と Amazon は、販売数で重みを付けて合わせる。
 * - 品番の月の販売数が少ないと偶然に左右されるため、月平均の販売数が20個未満か、12か月のうち求められない月がある品番は、
 *   同じカテゴリーの対象品番を合わせた係数を、それでも足りなければ対象品番の全体の係数を初期値にする。12か月の平均が1になるよう割り戻す。
 * - 初めて売れた月より前（発売前）の月は使わない。過去の欠品は在庫の記録が無く除けないため、欠品していた月は少なめに出る。
 */
class SeasonalIndexEstimator
{
    /** 品番の係数を使うのに必要な、月平均の販売数。 */
    public const MIN_MONTHLY_QUANTITY = 20.0;

    /** 月の平均日販を使うのに必要な、販売実績のある日数（イベントの日を除く）。 */
    public const MIN_MONTH_DAYS = 14;

    /** 新しい年から順の重み。 */
    public const YEAR_WEIGHTS = [0.5, 0.3, 0.2];

    /**
     * 品番・チャネルごとの日別の販売数。全期間を1回で読む（予測精度の測定では起点ごとに切って使う）。
     *
     * @param  Collection<int, Item>  $items
     * @return array<string, array<string, array<string, int>>> 品番 => チャネルの値 => [Y-m-d => 販売数]
     */
    public function loadDailySales(Collection $items, CarbonImmutable $to): array
    {
        $skuItems = [];
        foreach ($items as $item) {
            foreach ($item->skus as $sku) {
                $skuItems[$sku->id] = $item->item_no;
            }
        }
        $warehouseChannels = [];
        foreach (Warehouse::cases() as $warehouse) {
            $warehouseChannels[$warehouse->value] = $warehouse->channel()?->value;
        }
        $sales = [];
        foreach (array_chunk(array_keys($skuItems), 500) as $chunk) {
            $rows = DB::table('sales_lines')
                ->selectRaw('sku_id, warehouse, sales_date, sum(quantity) as quantity')
                ->whereIn('sku_id', $chunk)
                ->where('sales_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'warehouse', 'sales_date')
                ->get();
            foreach ($rows as $row) {
                $channel = $warehouseChannels[$row->warehouse] ?? null;
                if ($channel === null) {
                    continue;
                }
                $itemNo = $skuItems[(int) $row->sku_id];
                $date = substr((string) $row->sales_date, 0, 10);
                $sales[$itemNo][$channel][$date] = ($sales[$itemNo][$channel][$date] ?? 0) + (int) $row->quantity;
            }
        }

        return $sales;
    }

    /**
     * $asOf の前日までの販売実績から、品番ごとの季節係数を求める（品番・カテゴリー・全体のうち、使えるもののいちばん細かい単位）。
     *
     * @param  Collection<int, Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @param  array<string, array<string, true>>  $excludedDates  チャネルの値 => イベントの日
     * @param  array<string, array<string, array<string, int>>>|null  $dailySales  loadDailySales() の結果（無ければ読む）
     * @return array<string, SeasonalIndex> 品番 => 季節係数
     */
    public function estimate(Collection $items, array $channelRanges, array $excludedDates, CarbonImmutable $asOf, ?array $dailySales = null): array
    {
        return array_map(
            fn (array $levels): SeasonalIndex => collect($levels)->first(fn (SeasonalIndex $index): bool => $index->isAvailable() && $index->monthlyQuantity >= self::MIN_MONTHLY_QUANTITY)
                ?? new SeasonalIndex($levels[0]->itemNo, null),
            $this->levels($items, $channelRanges, $excludedDates, $asOf, $dailySales),
        );
    }

    /**
     * 品番ごとに、品番・カテゴリー・対象品番の全体の3つの単位で求めた季節係数（求められない単位は factors が null）。
     *
     * @param  Collection<int, Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @param  array<string, array<string, true>>  $excludedDates
     * @param  array<string, array<string, array<string, int>>>|null  $dailySales
     * @return array<string, list<SeasonalIndex>> 品番 => [品番, カテゴリー, 全体]
     */
    public function levels(Collection $items, array $channelRanges, array $excludedDates, CarbonImmutable $asOf, ?array $dailySales = null): array
    {
        $dailySales ??= $this->loadDailySales($items, $asOf->subDay());
        $lastDay = $asOf->subDay();

        // チャネルごとに、月ごとの使える日（販売実績の期間内で、イベントの日を除く）
        $channelMonths = [];
        foreach ($channelRanges as $range) {
            if ($range['from'] === null || $range['to'] === null) {
                continue;
            }
            $to = $range['to']->min($lastDay);
            $excluded = $excludedDates[$range['channel']->value] ?? [];
            $months = [];
            for ($day = $range['from']; $day->lte($to); $day = $day->addDay()) {
                $date = $day->toDateString();
                if (! isset($excluded[$date])) {
                    $months[substr($date, 0, 7)][] = $date;
                }
            }
            $channelMonths[$range['channel']->value] = $months;
        }

        // 品番・カテゴリー・全体の、チャネルごとの月の販売数
        $monthly = [];
        $groupOf = [];
        foreach ($items as $item) {
            $groupOf[$item->item_no] = (string) $item->category;
            foreach ($channelMonths as $channel => $months) {
                $sales = $dailySales[$item->item_no][$channel] ?? [];
                foreach ($months as $month => $dates) {
                    $quantity = 0;
                    foreach ($dates as $date) {
                        $quantity += $sales[$date] ?? 0;
                    }
                    foreach (['item:'.$item->item_no, 'category:'.$item->category, 'overall'] as $key) {
                        $monthly[$key][$channel][$month] = ($monthly[$key][$channel][$month] ?? 0) + $quantity;
                    }
                }
            }
        }

        $cache = [];
        $factorsOf = function (string $key) use (&$cache, $monthly, $channelMonths): array {
            return $cache[$key] ??= $this->factors($monthly[$key] ?? [], $channelMonths);
        };

        $levels = [];
        foreach ($items as $item) {
            foreach ([['item:'.$item->item_no, SeasonalIndex::SOURCE_ITEM, $item->item_no], ['category:'.$item->category, SeasonalIndex::SOURCE_CATEGORY, (string) $item->category], ['overall', SeasonalIndex::SOURCE_OVERALL, '対象品番の全体']] as [$key, $source, $label]) {
                [$factors, $years, $monthlyQuantity] = $factorsOf($key);
                $levels[$item->item_no][] = new SeasonalIndex($item->item_no, $factors, $source, $label, $years, $monthlyQuantity);
            }
        }

        return $levels;
    }

    /**
     * チャネルごとの月の販売数から、12か月の係数を求める。求められない月があれば null。
     *
     * @param  array<string, array<string, int>>  $quantities  チャネルの値 => [Y-m => 販売数]
     * @param  array<string, array<string, list<string>>>  $channelMonths  チャネルの値 => [Y-m => 使える日]
     * @return array{0: array<int, float>|null, 1: array<int, int>, 2: float} [係数, 月ごとの使った年数, 月平均の販売数]
     */
    private function factors(array $quantities, array $channelMonths): array
    {
        $weighted = array_fill(1, 12, 0.0);
        $weights = array_fill(1, 12, 0.0);
        $years = array_fill(1, 12, 0);
        $totalQuantity = 0;
        $totalMonths = 0;
        foreach ($quantities as $channel => $byMonth) {
            // 平均日販（使える日が足りない月と、初めて売れた月より前の月（発売前）は null）
            $averages = [];
            $hasSold = false;
            foreach ($channelMonths[$channel] as $month => $dates) {
                $hasSold = $hasSold || ($byMonth[$month] ?? 0) > 0;
                $averages[$month] = $hasSold && count($dates) >= self::MIN_MONTH_DAYS ? ($byMonth[$month] ?? 0) / count($dates) : null;
            }
            $channelQuantity = array_sum($byMonth);
            $totalQuantity += $channelQuantity;
            $totalMonths = max($totalMonths, count(array_filter($averages, fn (?float $average): bool => $average !== null)));
            if ($channelQuantity <= 0) {
                continue;
            }

            // 中心化移動平均比率を、新しい月から順に集める
            $ratios = [];
            $keys = array_keys($averages);
            $position = array_flip($keys);
            foreach (array_reverse($keys) as $month) {
                $index = $position[$month];
                if ($index < 6 || $index + 6 >= count($keys) || $averages[$month] === null) {
                    continue;
                }
                $window = array_slice($keys, $index - 6, 13);
                $values = array_map(fn (string $key): ?float => $averages[$key], $window);
                if (in_array(null, $values, true)) {
                    continue;
                }
                $centered = ($values[0] * 0.5 + array_sum(array_slice($values, 1, 11)) + $values[12] * 0.5) / 12;
                if ($centered <= 0) {
                    continue;
                }
                $ratios[(int) substr($month, 5, 2)][] = $averages[$month] / $centered;
            }
            foreach ($ratios as $calendarMonth => $values) {
                $values = array_slice($values, 0, count(self::YEAR_WEIGHTS));
                $yearWeights = array_slice(self::YEAR_WEIGHTS, 0, count($values));
                $ratio = array_sum(array_map(fn (float $value, float $weight): float => $value * $weight, $values, $yearWeights)) / array_sum($yearWeights);
                $weighted[$calendarMonth] += $ratio * $channelQuantity;
                $weights[$calendarMonth] += $channelQuantity;
                $years[$calendarMonth] = max($years[$calendarMonth], count($values));
            }
        }
        $monthlyQuantity = $totalMonths > 0 ? $totalQuantity / $totalMonths : 0.0;
        if (in_array(0.0, $weights, true)) {
            return [null, $years, $monthlyQuantity];
        }
        $factors = [];
        foreach ($weighted as $month => $value) {
            $factors[$month] = $value / $weights[$month];
        }
        $mean = array_sum($factors) / 12;

        return [array_map(fn (float $factor): float => round($factor / $mean, 4), $factors), $years, $monthlyQuantity];
    }
}
