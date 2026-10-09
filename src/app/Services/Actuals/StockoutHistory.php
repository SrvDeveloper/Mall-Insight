<?php

namespace App\Services\Actuals;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Inventory;
use Carbon\CarbonImmutable;

/** モールごとの欠品履歴。記録の無い日は直前の状態を引き継ぐ（K-037・K-038）。 */
class StockoutHistory
{
    /**
     * @param  list<int>  $skuIds
     * @return array<int, array<string, bool|null>> SKU ID => 日付 => 欠品か（最初の記録より前は null）
     */
    public function dailyStates(array $skuIds, CarbonImmutable $from, CarbonImmutable $to, Channel $channel): array
    {
        $dates = [];
        for ($day = $from->startOfDay(); $day->lte($to); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }
        if ($dates === []) {
            return [];
        }

        $states = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = Inventory::query()
                ->selectRaw('sku_id, stock_date, sum(quantity) as quantity')
                ->whereIn('sku_id', $chunk)
                ->whereIn('warehouse', array_map(fn (Warehouse $warehouse): string => $warehouse->value, $channel->warehouses()))
                ->where('stock_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'stock_date')
                ->orderBy('stock_date')
                ->toBase()
                ->get();
            foreach ($rows->groupBy('sku_id') as $skuId => $surveys) {
                $byDate = [];
                $state = null;
                foreach ($surveys as $survey) {
                    $date = substr((string) $survey->stock_date, 0, 10);
                    $byDate[$date] = (int) $survey->quantity <= 0;
                    if ($date < $dates[0]) {
                        $state = $byDate[$date];
                    }
                }
                foreach ($dates as $date) {
                    $state = $byDate[$date] ?? $state;
                    $states[$skuId][$date] = $state;
                }
            }
        }

        return $states;
    }

    /**
     * 月ごとの欠品日数と判定できた日数。今月は今日まで数える（K-084）。
     *
     * @param  list<int>  $skuIds
     * @return array<int, list<array{days: ?int, known_days: int, period_days: int}>>
     */
    public function monthlyCounts(array $skuIds, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today, Channel $channel): array
    {
        $states = $this->dailyStates($skuIds, $from->startOfMonth(), $to->endOfMonth()->startOfDay()->min($today->startOfDay()), $channel);
        $counts = [];
        foreach ($skuIds as $skuId) {
            for ($month = $from->startOfMonth(); $month->lte($to); $month = $month->addMonthNoOverflow()) {
                $days = 0;
                $knownDays = 0;
                $periodDays = 0;
                $end = $month->endOfMonth()->startOfDay()->min($today->startOfDay());
                for ($day = $month; $day->lte($end); $day = $day->addDay()) {
                    $state = $states[$skuId][$day->toDateString()] ?? null;
                    $periodDays++;
                    if ($state !== null) {
                        $knownDays++;
                        $days += (int) $state;
                    }
                }
                $counts[$skuId][] = ['days' => $knownDays === 0 ? null : $days, 'known_days' => $knownDays, 'period_days' => $periodDays];
            }
        }

        return $counts;
    }
}
