<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Models\ItemSelection;
use App\Models\SalesEventPeriod;
use App\Models\SalesLine;
use App\Services\Actuals\StockoutHistory;
use App\Services\InventoryTrend\TrendSettings;
use Carbon\CarbonImmutable;

/**
 * 需要予測の当たり具合を、過去に戻って予測をやり直して測る（バックテスト、バックログ B-130・決定記録 K-111）。
 *
 * - 起点は月の1日。起点の前日までに分かっていた販売実績・在庫だけで予測し、その後の販売実績と比べる。起点より後のデータは使わない。
 * - 何か月先を測るか（BacktestHorizon）：1か月目（起点の月）、1～3か月目の合計、判定する月（在庫推移の設定、初期値は6か月後）。
 *   それぞれ、販売実績がそろった最新の月までで答え合わせできる、新しい方から12の起点を使う。
 * - 方式（BacktestMethod）：今の方式（イベント＋登録した季節係数、B-106・K-113）、イベントの補正だけ（B-129・K-112）、
 *   補正を入れる前の基準平均日販（B-006）と、
 *   比べるための単純な方式（直近30日の平均・前年同月の実績）。
 *   イベントの補正は、登録したイベントの日を平均日販から除き、起点以降のイベントの日に、起点より前に終わった同じイベントの期間から求めた倍率の候補を掛ける。
 *   担当者が入れた倍率は、後から分かったことで決めた値のため使わない。
 *   季節係数は担当者が登録した今の係数をすべての起点で使う（起点の時点で登録していたかは問わない。係数が当たるかを確かめるため）。
 * - 対象は対象品番（最新の選定）の有効なSKU。チャネル（BOSS・Amazon）ごとに予測と販売実績を比べ、子ASINの無いSKUは Amazon で比べない（K-042）。
 * - すべての方式で予測でき、答え合わせの期間の販売実績を取り込んでいるSKU・チャネル・起点だけを比べる（方式どうしを同じ条件で比べるため）。
 *   比べられなかったものは件数だけ数える。
 * - 答え合わせは取り込んだ販売実績そのもので行う。在庫の履歴が短いため、欠品していた月の販売実績も補正しない。
 * - 結果は品番・方式・何か月先・チャネル・起点ごとに、販売実績・予測・誤差の絶対値の合計と件数で返す。WAPE などは集計するときに求める。
 */
class ForecastBacktester
{
    /** 1つの何か月先あたりの起点の数。 */
    public const ORIGINS = 12;

    /** 直近30日の平均（比べるための方式）の日数。 */
    public const RECENT_DAYS = 30;

    public function __construct(private DemandForecaster $forecaster, private StockoutHistory $stockouts, private EventUpliftEstimator $eventUplifts) {}

    /**
     * @return array{measured_at: CarbonImmutable, check_month_offset: int, origins: array<string, list<CarbonImmutable>>, item_count: int, sku_count: int, events: list<array<string, mixed>>, seasonal_factors: array<string, array<int, float>>, rows: list<array{horizon: string, method: string, item_no: string, channel: string, origin: string, evaluated: int, skipped: int, actual: float, forecast: float, abs_error: float}>}
     */
    public function run(CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $checkMonthOffset = TrendSettings::current()->checkMonthOffset;
        $items = $this->forecaster->items(collect(ItemSelection::current()?->items->pluck('item_no')->all() ?? []));
        $skus = $items->flatMap(fn (Item $item) => $item->skus);
        $channelRanges = collect($this->forecaster->channelSalesRanges($today))->filter(fn (array $range): bool => $range['to'] !== null)->values();

        // 答え合わせできる最新の月：今日を含む月の前月まで、かつ全チャネルの販売実績がその月末までそろっていること
        $lastMonthEnd = $today->startOfMonth()->subDay();
        foreach ($channelRanges as $range) {
            $isMonthEnd = $range['to']->equalTo($range['to']->endOfMonth()->startOfDay());
            $lastMonthEnd = $lastMonthEnd->min($isMonthEnd ? $range['to'] : $range['to']->startOfMonth()->subDay());
        }
        $lastMonth = $lastMonthEnd->startOfMonth();

        $origins = [];
        foreach (BacktestHorizon::cases() as $horizon) {
            [, $last] = $horizon->monthOffsets($checkMonthOffset);
            $latest = $lastMonth->subMonthsNoOverflow($last);
            $origins[$horizon->value] = array_map(fn (int $index): CarbonImmutable => $latest->subMonthsNoOverflow(self::ORIGINS - 1 - $index), range(0, self::ORIGINS - 1));
        }
        $allOrigins = collect($origins)->flatten()->unique(fn (CarbonImmutable $origin): string => $origin->toDateString())->sortBy(fn (CarbonImmutable $origin): string => $origin->toDateString())->values();

        $rows = [];
        if ($skus->isNotEmpty() && $allOrigins->isNotEmpty()) {
            $rows = $this->evaluate($items->all(), $channelRanges->all(), $origins, $allOrigins->all(), $lastMonthEnd, $checkMonthOffset);
        }

        return [
            'measured_at' => $now,
            'check_month_offset' => $checkMonthOffset,
            'origins' => $origins,
            'item_count' => $items->count(),
            'sku_count' => $skus->count(),
            'events' => $this->eventUplifts->periods()->map(fn (SalesEventPeriod $event): array => [
                'id' => $event->id,
                'event_id' => $event->sales_event_id,
                'name' => $event->name,
                'starts_on' => $event->starts_on->toDateString(),
                'ends_on' => $event->ends_on->toDateString(),
                'malls' => $event->salesEvent->malls->map(fn ($mall): string => $mall->value)->all(),
            ])->all(),
            'seasonal_factors' => ItemSeasonalFactor::currentFactors($items->pluck('item_no')->all()),
            'rows' => $rows,
        ];
    }

    /**
     * @param  list<Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @param  array<string, list<CarbonImmutable>>  $origins
     * @param  list<CarbonImmutable>  $allOrigins
     * @return list<array{horizon: string, method: string, item_no: string, channel: string, origin: string, evaluated: int, skipped: int, actual: float, forecast: float, abs_error: float}>
     */
    private function evaluate(array $items, array $channelRanges, array $origins, array $allOrigins, CarbonImmutable $lastMonthEnd, int $checkMonthOffset): array
    {
        $firstOrigin = $allOrigins[0];
        $lastOrigin = $allOrigins[count($allOrigins) - 1];
        // 予測に使う期間（起点の180日前から）と、前年同月の実績（最初の答え合わせの月の12か月前から）を1回で読む
        $windowDays = array_sum(array_keys(DemandForecaster::WINDOW_WEIGHTS));
        $loadFrom = $firstOrigin->subDays($windowDays)->min($firstOrigin->subMonthsNoOverflow(12));
        $skuIds = [];
        foreach ($items as $item) {
            foreach ($item->skus as $sku) {
                $skuIds[] = $sku->id;
            }
        }

        // イベント：平均日販から除く日と、終わったイベントごとの倍率（起点ごとの候補は、起点より前に終わったものから求める）
        $events = $this->eventUplifts->periods();
        $excludedDates = $this->eventUplifts->excludedDates($events);
        $today = $lastMonthEnd->addDay();
        $measurements = $this->eventUplifts->measure($this->forecaster, $excludedDates, $events->filter(fn ($event): bool => $event->hasEndedBefore($today)), collect($items), $channelRanges);

        // 季節係数：担当者が登録した今の係数を、すべての起点で使う。イベントの倍率も、季節係数を掛けた普段の数と比べて測り直す
        $seasonal = ItemSeasonalFactor::currentFactors(array_map(fn (Item $item): string => $item->item_no, $items));
        $seasonalMeasurements = $seasonal === [] ? $measurements : $this->eventUplifts->measure($this->forecaster, $excludedDates, $events->filter(fn ($event): bool => $event->hasEndedBefore($today)), collect($items), $channelRanges, $seasonal);

        $sums = [];
        foreach ($channelRanges as $range) {
            $channel = $range['channel'];
            $channelExcluded = $excludedDates[$channel->value] ?? [];
            $daily = $this->dailySales($skuIds, $channel, $loadFrom, $lastMonthEnd);
            $stockoutDates = [];
            foreach ($this->stockouts->dailyStates($skuIds, $loadFrom, $lastOrigin->subDay(), $channel) as $skuId => $states) {
                foreach ($states as $date => $isOutOfStock) {
                    if ($isOutOfStock === true) {
                        $stockoutDates[$skuId][$date] = true;
                    }
                }
            }

            // 起点ごとの期間・日付は SKU によらないため、先に求めて使い回す
            $contexts = [];
            foreach ($allOrigins as $origin) {
                $contexts[] = [
                    ...$this->context($range, $origin, $origins, $checkMonthOffset),
                    'uplifts' => $this->eventUplifts->candidateUplifts($channel, $origin, $events, $measurements, $items),
                    'seasonal_uplifts' => $this->eventUplifts->candidateUplifts($channel, $origin, $events, $seasonalMeasurements, $items),
                ];
            }

            foreach ($items as $item) {
                foreach ($item->skus as $sku) {
                    if (! DemandForecaster::sellsOn($sku, $channel)) {
                        continue;
                    }
                    $sales = $daily[$sku->id] ?? [];
                    $monthly = $this->monthlyTotals($sales);
                    foreach ($contexts as $context) {
                        if ($context['horizons'] === []) {
                            continue;
                        }
                        $predictions = $this->predict($channel, $context, $sales, $monthly, $stockoutDates[$sku->id] ?? [], $channelExcluded, $context['uplifts'][$item->item_no] ?? [], $context['seasonal_uplifts'][$item->item_no] ?? [], $seasonal[$item->item_no] ?? null);
                        foreach ($context['horizons'] as $horizon => $spec) {
                            $actual = $spec['actual_months'] === null ? null : (float) array_sum(array_map(fn (string $month): int => $monthly[$month] ?? 0, $spec['actual_months']));
                            $forecasts = [];
                            foreach (BacktestMethod::cases() as $method) {
                                $forecasts[$method->value] = $this->sumMonths($predictions[$method->value], $spec['first'], $spec['last']);
                            }
                            $isEvaluated = $actual !== null && ! in_array(null, $forecasts, true);
                            foreach (BacktestMethod::cases() as $method) {
                                $key = implode('|', [$horizon, $method->value, $item->item_no, $channel->value, $context['origin_key']]);
                                $current = $sums[$key] ?? ['evaluated' => 0, 'skipped' => 0, 'actual' => 0.0, 'forecast' => 0.0, 'abs_error' => 0.0];
                                if ($isEvaluated) {
                                    $forecast = $forecasts[$method->value];
                                    $current = [
                                        'evaluated' => $current['evaluated'] + 1,
                                        'skipped' => $current['skipped'],
                                        'actual' => $current['actual'] + $actual,
                                        'forecast' => $current['forecast'] + $forecast,
                                        'abs_error' => $current['abs_error'] + abs($actual - $forecast),
                                    ];
                                } else {
                                    $current['skipped']++;
                                }
                                $sums[$key] = $current;
                            }
                        }
                    }
                }
            }
        }

        $rows = [];
        foreach ($sums as $key => $values) {
            [$horizon, $method, $itemNo, $channel, $origin] = explode('|', $key);
            $rows[] = ['horizon' => $horizon, 'method' => $method, 'item_no' => $itemNo, 'channel' => $channel, 'origin' => $origin, ...$values];
        }

        return $rows;
    }

    /**
     * チャネル・起点ごとの、SKU によらない値。平均日販の期間と日付（今の方式）、直近30日の日付、予測する月の日数と前年同月、
     * 測る何か月先ごとの答え合わせの月（販売実績を取り込んでいなければ null）。起点時点でそのチャネルの販売実績が無ければ plan は null。
     *
     * @param  array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}  $range
     * @param  array<string, list<CarbonImmutable>>  $origins
     * @return array{origin: CarbonImmutable, origin_key: string, plan: array<string, mixed>|null, recent_dates: list<string>|null, month_days: list<int>, last_year_months: list<string|null>, horizons: array<string, array{first: int, last: int, actual_months: list<string>|null}>}
     */
    private function context(array $range, CarbonImmutable $origin, array $origins, int $checkMonthOffset): array
    {
        $originKey = $origin->toDateString();
        $months = max(3, $checkMonthOffset + 1);
        $monthStarts = array_map(fn (int $index): CarbonImmutable => $origin->addMonthsNoOverflow($index), range(0, $months - 1));
        $knownTo = $range['to']->min($origin->subDay());
        $hasData = $range['from'] !== null && $range['from']->lte($knownTo);

        $recentFrom = $origin->subDays(self::RECENT_DAYS);
        $recentDates = null;
        if ($hasData && $range['from']->lte($recentFrom) && $knownTo->equalTo($origin->subDay())) {
            $recentDates = [];
            for ($day = $recentFrom; $day->lt($origin); $day = $day->addDay()) {
                $recentDates[] = $day->toDateString();
            }
        }

        $horizons = [];
        foreach (BacktestHorizon::cases() as $horizon) {
            if (! in_array($originKey, array_map(fn (CarbonImmutable $item): string => $item->toDateString(), $origins[$horizon->value]), true)) {
                continue;
            }
            [$first, $last] = $horizon->monthOffsets($checkMonthOffset);
            $start = $monthStarts[$first];
            $end = $monthStarts[$last]->endOfMonth()->startOfDay();
            $isCovered = $range['from'] !== null && $range['from']->lte($start) && $range['to']->gte($end);
            $horizons[$horizon->value] = ['first' => $first, 'last' => $last, 'actual_months' => $isCovered ? array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), array_slice($monthStarts, $first, $last - $first + 1)) : null];
        }

        return [
            'origin' => $origin,
            'origin_key' => $originKey,
            'plan' => $hasData ? $this->forecaster->windowPlan($range['from'], $knownTo, $origin) : null,
            'recent_dates' => $recentDates,
            'month_days' => array_map(fn (CarbonImmutable $month): int => $month->daysInMonth, $monthStarts),
            // 前年同月の実績は、その月の販売実績を初日から取り込んでいるときだけ使う
            'last_year_months' => array_map(fn (CarbonImmutable $month): ?string => $range['from'] !== null && $range['from']->lte($month->subYearNoOverflow()) ? $month->subYearNoOverflow()->format('Y-m') : null, $monthStarts),
            'horizons' => $horizons,
        ];
    }

    /**
     * 起点時点の、方式ごとの月別の予測（起点の月から判定する月まで）。予測できない方式・月は null。
     *
     * @param  array{origin: CarbonImmutable, plan: array<string, mixed>|null, recent_dates: list<string>|null, month_days: list<int>, last_year_months: list<string|null>}  $context
     * @param  array<string, int>  $sales  [Y-m-d => 販売数量]
     * @param  array<string, int>  $monthly  [Y-m => 販売数量]
     * @param  array<string, true>  $stockoutDates
     * @param  array<string, true>  $eventDates  平均日販から除くイベントの日
     * @param  list<EventUplift>  $uplifts  起点以降のイベントの倍率（その品番の分）
     * @param  list<EventUplift>  $seasonalUplifts  季節係数を掛けた普段の数と比べて求めた、起点以降のイベントの倍率
     * @param  array<int, float>|null  $seasonal  登録した季節係数
     * @return array<string, list<float|null>> 方式 => 月の位置（起点の月が0） => 予測
     */
    private function predict(Channel $channel, array $context, array $sales, array $monthly, array $stockoutDates, array $eventDates, array $uplifts, array $seasonalUplifts, ?array $seasonal): array
    {
        $none = array_fill(0, count($context['month_days']), null);

        // 今の方式（イベント＋季節係数。季節係数を登録していない品番は、イベントの補正だけと同じ）
        $seasonalMonthly = $none;
        if ($context['plan'] !== null) {
            $adjusted = $this->forecaster->forecastChannelWith($channel, $context['plan'], $sales, $stockoutDates, $context['origin'], $eventDates, $seasonalUplifts, $seasonal);
            if ($adjusted->isPredictable()) {
                $seasonalMonthly = array_map(fn (int $index): float => $adjusted->monthly[$index]->quantity, array_keys($context['month_days']));
            }
        }

        // 今の方式（イベントの補正あり）と、補正を入れる前の基準平均日販
        $eventMonthly = $none;
        $baseMonthly = $none;
        if ($context['plan'] !== null) {
            $adjusted = $this->forecaster->forecastChannelWith($channel, $context['plan'], $sales, $stockoutDates, $context['origin'], $eventDates, $uplifts);
            if ($adjusted->isPredictable()) {
                $eventMonthly = array_map(fn (int $index): float => $adjusted->monthly[$index]->quantity, array_keys($context['month_days']));
            }
            $base = $this->forecaster->forecastChannelWith($channel, $context['plan'], $sales, $stockoutDates, $context['origin']);
            if ($base->isPredictable()) {
                $baseMonthly = array_map(fn (int $days): float => $base->baseAverage * $days, $context['month_days']);
            }
        }

        // 直近30日の平均（欠品日を除かない）
        $recentMonthly = $none;
        if ($context['recent_dates'] !== null) {
            $quantity = 0;
            foreach ($context['recent_dates'] as $date) {
                $quantity += $sales[$date] ?? 0;
            }
            $recentMonthly = array_map(fn (int $days): float => $quantity / self::RECENT_DAYS * $days, $context['month_days']);
        }

        // 前年同月の実績
        $lastYearMonthly = array_map(fn (?string $month): ?float => $month === null ? null : (float) ($monthly[$month] ?? 0), $context['last_year_months']);

        return [
            BacktestMethod::Seasonal->value => $seasonalMonthly,
            BacktestMethod::EventAdjusted->value => $eventMonthly,
            BacktestMethod::BaseAverage->value => $baseMonthly,
            BacktestMethod::RecentAverage->value => $recentMonthly,
            BacktestMethod::LastYear->value => $lastYearMonthly,
        ];
    }

    /**
     * @param  list<float|null>  $values
     */
    private function sumMonths(array $values, int $first, int $last): ?float
    {
        $total = 0.0;
        for ($index = $first; $index <= $last; $index++) {
            if (($values[$index] ?? null) === null) {
                return null;
            }
            $total += $values[$index];
        }

        return $total;
    }

    /**
     * @param  list<int>  $skuIds
     * @return array<int, array<string, int>> [SKU ID => [Y-m-d => 販売数量]]
     */
    private function dailySales(array $skuIds, Channel $channel, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = SalesLine::query()
                ->selectRaw('sku_id, sales_date, sum(quantity) as quantity')
                ->whereIn('sku_id', $chunk)
                ->whereIn('warehouse', array_map(fn (Warehouse $warehouse): string => $warehouse->value, $channel->warehouses()))
                ->where('sales_date', '>=', $from->toDateString())
                ->where('sales_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'sales_date')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $sales[(int) $row->sku_id][substr((string) $row->sales_date, 0, 10)] = (int) $row->quantity;
            }
        }

        return $sales;
    }

    /**
     * @param  array<string, int>  $sales  [Y-m-d => 販売数量]
     * @return array<string, int> [Y-m => 販売数量]
     */
    private function monthlyTotals(array $sales): array
    {
        $monthly = [];
        foreach ($sales as $date => $quantity) {
            $month = substr($date, 0, 7);
            $monthly[$month] = ($monthly[$month] ?? 0) + $quantity;
        }

        return $monthly;
    }
}
