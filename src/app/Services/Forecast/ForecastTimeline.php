<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Item;
use App\Models\SalesLine;
use App\Services\Actuals\SalesUnit;
use App\Services\Actuals\StockoutHistory;
use Carbon\CarbonImmutable;

/**
 * 需要予測の画面（B-131、K-123）の、月ごと・日ごとの並び。SKU・チャネルごとに、過去の月・日は販売実績と欠品と予測し直した数、
 * 今月（今日）から先は需要予測を並べる。
 *
 * - 月ごと：過去12か月・今月・これからの11か月。今月の販売実績は前日までで、今月の予測は1か月分（K-052）。
 * - 日ごと：$from～$to の日。今日の販売実績はまだ無い（前日までを取り込む）。予測はモールごとの基準平均日販×その月の季節係数×イベントの倍率の合計。
 * - 予測し直した数（ForecastReplayer）：月ごとは、その月の $offset か月前の1日を起点にした、その月の予測。日ごとは、その日を含む月の
 *   $offset か月前の1日を起点にしたモールごとの基準平均日販に、その月の季節係数と、そのモールでその日に重なるイベントの倍率を掛けて足した1日分（B-134）。
 * - イベントで増やした数（event）：今月（今日）から先の予測のうち、イベントの倍率で増やした分。日ごとは予測－基準平均日販×季節係数、
 *   月ごとは月別の予測のイベントで増やした数。予測を「普段の予測＋イベントで増やした数」に分けて見せるため（原則4）。
 * - チャネルの販売実績を取り込んでいない月・日の販売実績は null（原則2）。欠品は、在庫の記録が無い日は分からないため null。
 *   欠品率をグラフに出すため、在庫の記録で欠品かを判定できた日数（stockout_known）を添える。
 */
class ForecastTimeline
{
    /** 月ごとに出す過去の月の数。 */
    public const PAST_MONTHS = 12;

    /** 月ごとに出す、今月から先の月の数（今月を含む）。 */
    public const FUTURE_MONTHS = 12;

    /** 選べる、予測し直す起点（何か月前の1日か）。 */
    public const OFFSETS = [0, 3, 6];

    public function __construct(private DemandForecaster $forecaster, private ForecastReplayer $replayer, private StockoutHistory $stockouts) {}

    /**
     * @param  list<string>|null  $itemNos  null なら対象品番
     * @return array{forecast: DemandForecast, columns: list<array{key: string, kind: string, start: CarbonImmutable, end: CarbonImmutable, days: int}>, skus: array<int, array<string, array{base_average: float|null, actual: list<float|null>, actual_amount: list<float|null>, stockout: list<int|bool|null>, stockout_known: list<int|null>, replayed: list<float|null>, forecast: list<float|null>, event: list<float|null>}>>}
     */
    public function build(CarbonImmutable $now, SalesUnit $unit, int $offset, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null, ?array $itemNos = null): array
    {
        $today = $now->startOfDay();
        $forecast = $this->forecaster->forecast($now, $itemNos);
        $items = array_map(fn (array $entry): Item => $entry['item'], $forecast->items);
        $columns = $this->columns($unit, $today, $from, $to);
        $ranges = $forecast->channels;

        // 予測し直す起点（過去の月・日ごと）と、チャネルの販売実績がある月・日か。SKUごとに日付を比べ直すと遅いため、先に求めておく
        $pastEnd = $today->subDay();
        $origins = [];
        $originKeys = [];
        $covered = [];
        foreach ($columns as $index => $column) {
            if ($column['kind'] === 'past') {
                $origin = $column['start']->startOfMonth()->subMonthsNoOverflow($offset);
                $origins[$origin->toDateString()] = $origin;
                $originKeys[$index] = $origin->toDateString();
            }
            foreach ($ranges as $range) {
                $covered[$range['channel']->value][$index] = $range['from'] !== null && $range['to'] !== null && $column['start']->lte($pastEnd) && $range['from']->lte($column['end']) && $range['to']->gte($column['start']);
            }
        }
        $replayed = $this->replayer->replay($items, $ranges, array_values($origins), $today);
        // 日ごとの予測し直した数に掛ける、起点・チャネル・品番ごとのイベントの倍率
        $replayMultipliers = [];
        if ($unit === SalesUnit::Day) {
            foreach ($replayed as $origin => $channels) {
                foreach ($channels as $channel => $values) {
                    foreach ($values['uplifts'] as $itemNo => $uplifts) {
                        $replayMultipliers[$origin][$channel][$itemNo] = DemandForecaster::eventMultipliers($uplifts, $origin);
                    }
                }
            }
        }

        $skuIds = [];
        foreach ($items as $item) {
            foreach ($item->skus as $sku) {
                $skuIds[] = $sku->id;
            }
        }
        $first = $columns[0]['start'] ?? $today;
        $sold = $first->lte($pastEnd) ? $this->sales($skuIds, $first, $pastEnd, $unit) : [];

        $result = [];
        foreach ($ranges as $range) {
            $channel = $range['channel'];
            $stockouts = $first->lte($pastEnd) ? $this->stockoutsByColumn($this->stockouts->dailyStates($skuIds, $first, $pastEnd, $channel), $unit) : [];
            foreach ($forecast->items as $entry) {
                $item = $entry['item'];
                $seasonal = $forecast->seasonalFactors[$item->item_no] ?? null;
                $multipliers = DemandForecaster::eventMultipliers(array_map(fn (EventUplift $uplift): array => $uplift->toArray(), $forecast->eventPlan->upliftsFor($channel->value, $item->item_no)), $today->toDateString());
                foreach ($entry['skus'] as $skuForecast) {
                    $sku = $skuForecast->sku;
                    if (! DemandForecaster::sellsOn($sku, $channel)) {
                        continue;
                    }
                    $channelForecast = collect($skuForecast->channels)->first(fn (ChannelForecast $candidate): bool => $candidate->channel === $channel);
                    $row = ['base_average' => $channelForecast?->baseAverage === null ? null : round($channelForecast->baseAverage, 4), 'actual' => [], 'actual_amount' => [], 'stockout' => [], 'stockout_known' => [], 'replayed' => [], 'forecast' => [], 'event' => []];
                    $futureIndex = 0;
                    $skuSold = $sold[$sku->id][$channel->value] ?? [];
                    $skuStockouts = $stockouts[$sku->id] ?? [];
                    foreach ($columns as $index => $column) {
                        $isCovered = $covered[$channel->value][$index];
                        $values = $skuSold[$column['key']] ?? [0, 0];
                        $row['actual'][] = $isCovered ? (float) $values[0] : null;
                        $row['actual_amount'][] = $isCovered ? (float) $values[1] : null;
                        $row['stockout'][] = $skuStockouts[$column['key']]['state'] ?? null;
                        $row['stockout_known'][] = $skuStockouts[$column['key']]['known'] ?? null;

                        if ($column['kind'] === 'past') {
                            $origin = $originKeys[$index];
                            $row['replayed'][] = $this->replayedValue($replayed[$origin][$channel->value] ?? null, $sku->id, $column, $offset, $seasonal, $replayMultipliers[$origin][$channel->value][$item->item_no] ?? [], $unit);
                            $row['forecast'][] = null;
                            $row['event'][] = null;

                            continue;
                        }
                        $row['replayed'][] = null;
                        if ($unit === SalesUnit::Day) {
                            $isPredictable = $channelForecast?->isPredictable() ?? false;
                            $quantity = $isPredictable ? DemandForecaster::dailyQuantity($channelForecast->mallBases(), $seasonal, $multipliers, $column['key']) : null;
                            $row['forecast'][] = $quantity === null ? null : round($quantity, 4);
                            $row['event'][] = $quantity === null ? null : round($quantity - DemandForecaster::dailyQuantity($channelForecast->mallBases(), $seasonal, [], $column['key']), 4);
                        } else {
                            $month = $channelForecast?->monthly[$futureIndex] ?? null;
                            $row['forecast'][] = $month === null ? null : round($month->quantity, 2);
                            $row['event'][] = $month === null ? null : round($month->eventQuantity(), 2);
                            $futureIndex++;
                        }
                    }
                    $result[$sku->id][$channel->value] = $row;
                }
            }
        }

        return ['forecast' => $forecast, 'columns' => $columns, 'skus' => $result];
    }

    /**
     * 並べる月・日。kind は past（過去）・current（今月・今日）・future（これから）。
     *
     * @return list<array{key: string, kind: string, start: CarbonImmutable, end: CarbonImmutable, days: int}>
     */
    private function columns(SalesUnit $unit, CarbonImmutable $today, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $columns = [];
        if ($unit === SalesUnit::Day) {
            $from ??= $today->subDays(30);
            $to ??= $today->addDays(30);
            for ($day = new \DateTime($from->toDateString()); $day->format('Y-m-d') <= $to->toDateString(); $day->modify('+1 day')) {
                $date = CarbonImmutable::parse($day->format('Y-m-d'));
                $columns[] = ['key' => $date->toDateString(), 'kind' => $date->lt($today) ? 'past' : ($date->equalTo($today) ? 'current' : 'future'), 'start' => $date, 'end' => $date, 'days' => 1];
            }

            return $columns;
        }
        $currentMonth = $today->startOfMonth();
        for ($index = -self::PAST_MONTHS; $index < self::FUTURE_MONTHS; $index++) {
            $start = $currentMonth->addMonthsNoOverflow($index);
            $columns[] = ['key' => $start->format('Y-m'), 'kind' => $index < 0 ? 'past' : ($index === 0 ? 'current' : 'future'), 'start' => $start, 'end' => $start->endOfMonth()->startOfDay(), 'days' => $start->daysInMonth];
        }

        return $columns;
    }

    /**
     * 予測し直した数。月ごとは起点からその月の位置の月別の予測、日ごとはモールごとの基準平均日販×季節係数×イベントの倍率の合計。予測できなければ null。
     *
     * @param  array{skus: array<int, array{base: float|null, bases: array<string, float>, monthly: list<float>|null}>, uplifts: array<string, list<array{dates: list<string>, multiplier: float, malls: list<string>}>>}|null  $values
     * @param  array{key: string, kind: string, start: CarbonImmutable, end: CarbonImmutable, days: int}  $column
     * @param  array<int, float>|null  $seasonal
     * @param  array<string, array<string, float>>  $multipliers  起点時点の、モールごと・日ごとのイベントの倍率
     */
    private function replayedValue(?array $values, int $skuId, array $column, int $offset, ?array $seasonal, array $multipliers, SalesUnit $unit): ?float
    {
        $sku = $values['skus'][$skuId] ?? null;
        if ($sku === null || $sku['base'] === null) {
            return null;
        }
        if ($unit === SalesUnit::Month) {
            $quantity = $sku['monthly'][$offset] ?? null;

            return $quantity === null ? null : round($quantity, 2);
        }

        return round(DemandForecaster::dailyQuantity($sku['bases'], $seasonal, $multipliers, $column['key']), 4);
    }

    /**
     * 月ごとは欠品した日数（在庫の記録がまったく無ければ null）、日ごとは欠品したか（記録が無ければ null）と、在庫の記録で欠品かを判定できた日数。
     * 判定できた日数は、欠品率（欠品した日数÷判定できた日数）を求めるのに使う。在庫の記録が月の途中から始まる月があるため。
     *
     * @param  array<int, array<string, bool|null>>  $states  SKU ID => Y-m-d => 欠品か
     * @return array<int, array<string, array{state: int|bool, known: int}>> SKU ID => 月（Y-m）か日（Y-m-d） => 欠品と判定できた日数
     */
    private function stockoutsByColumn(array $states, SalesUnit $unit): array
    {
        $byColumn = [];
        foreach ($states as $skuId => $dates) {
            foreach ($dates as $date => $state) {
                if ($state === null) {
                    continue;
                }
                if ($unit === SalesUnit::Day) {
                    $byColumn[$skuId][$date] = ['state' => $state, 'known' => 1];
                } else {
                    $month = substr($date, 0, 7);
                    $current = $byColumn[$skuId][$month] ?? ['state' => 0, 'known' => 0];
                    $byColumn[$skuId][$month] = ['state' => $current['state'] + (int) $state, 'known' => $current['known'] + 1];
                }
            }
        }

        return $byColumn;
    }

    /**
     * SKU・チャネル・月（日）ごとの販売数と金額（税込）。
     *
     * @param  list<int>  $skuIds
     * @return array<int, array<string, array<string, array{0: int, 1: int}>>> SKU ID => チャネルの値 => 月（Y-m）か日（Y-m-d） => [販売数, 金額]
     */
    private function sales(array $skuIds, CarbonImmutable $from, CarbonImmutable $to, SalesUnit $unit): array
    {
        $sold = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = SalesLine::query()
                ->selectRaw('sku_id, sales_date, warehouse, sum(quantity) as quantity, sum(amount) as amount')
                ->whereIn('sku_id', $chunk)
                ->where('sales_date', '>=', $from->toDateString())
                ->where('sales_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'sales_date', 'warehouse')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $channel = Warehouse::tryFrom((string) $row->warehouse)?->channel();
                if (! $channel instanceof Channel) {
                    continue;
                }
                $key = substr((string) $row->sales_date, 0, $unit === SalesUnit::Day ? 10 : 7);
                $current = $sold[(int) $row->sku_id][$channel->value][$key] ?? [0, 0];
                $sold[(int) $row->sku_id][$channel->value][$key] = [$current[0] + (int) $row->quantity, $current[1] + (int) $row->amount];
            }
        }

        return $sold;
    }
}
