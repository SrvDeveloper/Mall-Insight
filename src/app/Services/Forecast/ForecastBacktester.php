<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Models\ItemSelection;
use App\Models\SalesEventPeriod;
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
 * - イベントの日と普段の日に分けた販売実績・予測・誤差も返す（B-133、K-125）。イベントの日は、そのチャネルで平均日販から除く登録したイベントの日。
 *   予測は、月ごとの普段の予測（予測－イベントで増やした数）を日数で日に配り、イベントの日にイベントで増やした数を足して分ける。
 *   単純な方式はイベントで増やした数が無いため、日数で配る。
 */
class ForecastBacktester
{
    /** 1つの何か月先あたりの起点の数。 */
    public const ORIGINS = 12;

    /** 直近30日の平均（比べるための方式）の日数。 */
    public const RECENT_DAYS = 30;

    public function __construct(private DemandForecaster $forecaster, private StockoutHistory $stockouts, private EventUpliftEstimator $eventUplifts) {}

    /**
     * @return array{measured_at: CarbonImmutable, check_month_offset: int, origins: array<string, list<CarbonImmutable>>, item_count: int, sku_count: int, events: list<array<string, mixed>>, seasonal_factors: array<string, array<int, float>>, rows: list<array{horizon: string, method: string, item_no: string, channel: string, origin: string, evaluated: int, skipped: int, actual: float, forecast: float, abs_error: float, event_actual: float, event_forecast: float, event_abs_error: float, normal_actual: float, normal_forecast: float, normal_abs_error: float}>}
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
     * @return list<array{horizon: string, method: string, item_no: string, channel: string, origin: string, evaluated: int, skipped: int, actual: float, forecast: float, abs_error: float, event_actual: float, event_forecast: float, event_abs_error: float, normal_actual: float, normal_forecast: float, normal_abs_error: float}>
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
        $channelEventDates = $this->eventUplifts->channelEventDates($events);
        $today = $lastMonthEnd->addDay();
        $measurements = $this->eventUplifts->measure($this->forecaster, $excludedDates, $events->filter(fn ($event): bool => $event->hasEndedBefore($today)), collect($items), $channelRanges);

        // 季節係数：担当者が登録した今の係数を、すべての起点で使う。イベントの倍率も、季節係数を掛けた普段の数と比べて測り直す
        $seasonal = ItemSeasonalFactor::currentFactors(array_map(fn (Item $item): string => $item->item_no, $items));
        $seasonalMeasurements = $seasonal === [] ? $measurements : $this->eventUplifts->measure($this->forecaster, $excludedDates, $events->filter(fn ($event): bool => $event->hasEndedBefore($today)), collect($items), $channelRanges, $seasonal);

        $sums = [];
        foreach ($channelRanges as $range) {
            $channel = $range['channel'];
            // 平均日販はモールごとにそのモールのイベントの日を除いて求め（B-134、K-126）、イベントの日と普段の日はチャネルで分ける（K-125）
            $mallExcluded = EventUpliftEstimator::datesOn($excludedDates, $channel);
            $channelExcluded = $channelEventDates[$channel->value] ?? [];
            $mallDaily = $this->forecaster->channelDailySales($skuIds, $channel, $loadFrom, $lastMonthEnd);
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
                    ...$this->context($range, $origin, $origins, $checkMonthOffset, $channelExcluded),
                    'uplifts' => $this->eventUplifts->candidateUplifts($channel, $origin, $events, $measurements, $items),
                    'seasonal_uplifts' => $this->eventUplifts->candidateUplifts($channel, $origin, $events, $seasonalMeasurements, $items),
                ];
            }

            foreach ($items as $item) {
                foreach ($item->skus as $sku) {
                    if (! DemandForecaster::sellsOn($sku, $channel)) {
                        continue;
                    }
                    $mallSales = $mallDaily[$sku->id] ?? [];
                    $sales = DemandForecaster::sumMalls($mallSales);
                    $monthly = $this->monthlyTotals($sales);
                    foreach ($contexts as $context) {
                        if ($context['horizons'] === []) {
                            continue;
                        }
                        $predictions = $this->predict($channel, $context, $mallSales, $sales, $monthly, $stockoutDates[$sku->id] ?? [], $mallExcluded, $context['uplifts'][$item->item_no] ?? [], $context['seasonal_uplifts'][$item->item_no] ?? [], $seasonal[$item->item_no] ?? null);
                        foreach ($context['horizons'] as $horizon => $spec) {
                            $actual = $spec['actual_months'] === null ? null : (float) array_sum(array_map(fn (string $month): int => $monthly[$month] ?? 0, $spec['actual_months']));
                            $eventActual = (float) array_sum(array_map(fn (string $date): int => $sales[$date] ?? 0, $spec['event_dates']));
                            $forecasts = [];
                            foreach (BacktestMethod::cases() as $method) {
                                $forecasts[$method->value] = $this->splitMonths($predictions[$method->value], $spec['first'], $spec['last'], $context['month_days'], $context['event_days']);
                            }
                            $isEvaluated = $actual !== null && ! in_array(null, $forecasts, true);
                            foreach (BacktestMethod::cases() as $method) {
                                $key = implode('|', [$horizon, $method->value, $item->item_no, $channel->value, $context['origin_key']]);
                                $current = $sums[$key] ?? ['evaluated' => 0, 'skipped' => 0, 'actual' => 0.0, 'forecast' => 0.0, 'abs_error' => 0.0, 'event_actual' => 0.0, 'event_forecast' => 0.0, 'event_abs_error' => 0.0, 'normal_actual' => 0.0, 'normal_forecast' => 0.0, 'normal_abs_error' => 0.0];
                                if ($isEvaluated) {
                                    [$eventForecast, $normalForecast] = $forecasts[$method->value];
                                    $forecast = $eventForecast + $normalForecast;
                                    $normalActual = $actual - $eventActual;
                                    $current = [
                                        'evaluated' => $current['evaluated'] + 1,
                                        'skipped' => $current['skipped'],
                                        'actual' => $current['actual'] + $actual,
                                        'forecast' => $current['forecast'] + $forecast,
                                        'abs_error' => $current['abs_error'] + abs($actual - $forecast),
                                        'event_actual' => $current['event_actual'] + $eventActual,
                                        'event_forecast' => $current['event_forecast'] + $eventForecast,
                                        'event_abs_error' => $current['event_abs_error'] + abs($eventActual - $eventForecast),
                                        'normal_actual' => $current['normal_actual'] + $normalActual,
                                        'normal_forecast' => $current['normal_forecast'] + $normalForecast,
                                        'normal_abs_error' => $current['normal_abs_error'] + abs($normalActual - $normalForecast),
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
     * チャネル・起点ごとの、SKU によらない値。平均日販の期間と日付（今の方式）、直近30日の日付、予測する月の日数とイベントの日数と前年同月、
     * 測る何か月先ごとの答え合わせの月（販売実績を取り込んでいなければ null）とイベントの日。起点時点でそのチャネルの販売実績が無ければ plan は null。
     *
     * @param  array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}  $range
     * @param  array<string, list<CarbonImmutable>>  $origins
     * @param  array<string, true>  $eventDates  そのチャネルのイベントの日
     * @return array{origin: CarbonImmutable, origin_key: string, plan: array<string, mixed>|null, recent_dates: list<string>|null, month_days: list<int>, event_days: list<int>, last_year_months: list<string|null>, horizons: array<string, array{first: int, last: int, actual_months: list<string>|null, event_dates: list<string>}>}
     */
    private function context(array $range, CarbonImmutable $origin, array $origins, int $checkMonthOffset, array $eventDates): array
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

        // 月の位置ごとの、イベントの日
        $monthEventDates = array_map(function (CarbonImmutable $month) use ($eventDates): array {
            $dates = [];
            for ($day = $month; $day->lte($month->endOfMonth()); $day = $day->addDay()) {
                if (isset($eventDates[$day->toDateString()])) {
                    $dates[] = $day->toDateString();
                }
            }

            return $dates;
        }, $monthStarts);

        $horizons = [];
        foreach (BacktestHorizon::cases() as $horizon) {
            if (! in_array($originKey, array_map(fn (CarbonImmutable $item): string => $item->toDateString(), $origins[$horizon->value]), true)) {
                continue;
            }
            [$first, $last] = $horizon->monthOffsets($checkMonthOffset);
            $start = $monthStarts[$first];
            $end = $monthStarts[$last]->endOfMonth()->startOfDay();
            $isCovered = $range['from'] !== null && $range['from']->lte($start) && $range['to']->gte($end);
            $horizons[$horizon->value] = [
                'first' => $first,
                'last' => $last,
                'actual_months' => $isCovered ? array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), array_slice($monthStarts, $first, $last - $first + 1)) : null,
                'event_dates' => array_merge(...array_slice($monthEventDates, $first, $last - $first + 1)),
            ];
        }

        return [
            'origin' => $origin,
            'origin_key' => $originKey,
            'plan' => $hasData ? $this->forecaster->windowPlan($range['from'], $knownTo, $origin) : null,
            'recent_dates' => $recentDates,
            'month_days' => array_map(fn (CarbonImmutable $month): int => $month->daysInMonth, $monthStarts),
            'event_days' => array_map(fn (array $dates): int => count($dates), $monthEventDates),
            // 前年同月の実績は、その月の販売実績を初日から取り込んでいるときだけ使う
            'last_year_months' => array_map(fn (CarbonImmutable $month): ?string => $range['from'] !== null && $range['from']->lte($month->subYearNoOverflow()) ? $month->subYearNoOverflow()->format('Y-m') : null, $monthStarts),
            'horizons' => $horizons,
        ];
    }

    /**
     * 起点時点の、方式ごとの月別の予測（起点の月から判定する月まで）。予測できない方式・月は null。
     *
     * @param  array{origin: CarbonImmutable, plan: array<string, mixed>|null, recent_dates: list<string>|null, month_days: list<int>, last_year_months: list<string|null>}  $context
     * @param  array<string, array<string, int>>  $mallSales  [モールの値 => [Y-m-d => 販売数量]]
     * @param  array<string, int>  $sales  [Y-m-d => 販売数量]（モールの合計）
     * @param  array<string, int>  $monthly  [Y-m => 販売数量]
     * @param  array<string, true>  $stockoutDates
     * @param  array<string, array<string, true>>  $eventDates  平均日販から除くイベントの日 [モールの値 => [Y-m-d => true]]
     * @param  list<EventUplift>  $uplifts  起点以降のイベントの倍率（その品番の分）
     * @param  list<EventUplift>  $seasonalUplifts  季節係数を掛けた普段の数と比べて求めた、起点以降のイベントの倍率
     * @param  array<int, float>|null  $seasonal  登録した季節係数
     * @return array<string, list<array{quantity: float, event: float}|null>> 方式 => 月の位置（起点の月が0） => 予測とそのうちイベントで増やした数
     */
    private function predict(Channel $channel, array $context, array $mallSales, array $sales, array $monthly, array $stockoutDates, array $eventDates, array $uplifts, array $seasonalUplifts, ?array $seasonal): array
    {
        $none = array_fill(0, count($context['month_days']), null);

        // 今の方式（イベント＋季節係数。季節係数を登録していない品番は、イベントの補正だけと同じ）
        $seasonalMonthly = $none;
        if ($context['plan'] !== null) {
            $adjusted = $this->forecaster->forecastChannelWith($channel, $context['plan'], $mallSales, $stockoutDates, $context['origin'], $eventDates, $seasonalUplifts, $seasonal);
            if ($adjusted->isPredictable()) {
                $seasonalMonthly = array_map(fn (int $index): array => ['quantity' => $adjusted->monthly[$index]->quantity, 'event' => $adjusted->monthly[$index]->eventQuantity()], array_keys($context['month_days']));
            }
        }

        // 今の方式（イベントの補正あり）と、補正を入れる前の基準平均日販
        $eventMonthly = $none;
        $baseMonthly = $none;
        if ($context['plan'] !== null) {
            $adjusted = $this->forecaster->forecastChannelWith($channel, $context['plan'], $mallSales, $stockoutDates, $context['origin'], $eventDates, $uplifts);
            if ($adjusted->isPredictable()) {
                $eventMonthly = array_map(fn (int $index): array => ['quantity' => $adjusted->monthly[$index]->quantity, 'event' => $adjusted->monthly[$index]->eventQuantity()], array_keys($context['month_days']));
            }
            $base = $this->forecaster->forecastChannelWith($channel, $context['plan'], $mallSales, $stockoutDates, $context['origin']);
            if ($base->isPredictable()) {
                $baseMonthly = array_map(fn (int $days): array => ['quantity' => $base->baseAverage * $days, 'event' => 0.0], $context['month_days']);
            }
        }

        // 直近30日の平均（欠品日を除かない）
        $recentMonthly = $none;
        if ($context['recent_dates'] !== null) {
            $quantity = 0;
            foreach ($context['recent_dates'] as $date) {
                $quantity += $sales[$date] ?? 0;
            }
            $recentMonthly = array_map(fn (int $days): array => ['quantity' => $quantity / self::RECENT_DAYS * $days, 'event' => 0.0], $context['month_days']);
        }

        // 前年同月の実績
        $lastYearMonthly = array_map(fn (?string $month): ?array => $month === null ? null : ['quantity' => (float) ($monthly[$month] ?? 0), 'event' => 0.0], $context['last_year_months']);

        return [
            BacktestMethod::Seasonal->value => $seasonalMonthly,
            BacktestMethod::EventAdjusted->value => $eventMonthly,
            BacktestMethod::BaseAverage->value => $baseMonthly,
            BacktestMethod::RecentAverage->value => $recentMonthly,
            BacktestMethod::LastYear->value => $lastYearMonthly,
        ];
    }

    /**
     * 月の位置 $first～$last の予測を、イベントの日と普段の日に分けて足す。普段の予測（予測－イベントで増やした数）は日数で日に配り、
     * イベントの日の分にイベントで増やした数を足す。どれかの月を予測できなければ null。
     *
     * @param  list<array{quantity: float, event: float}|null>  $values
     * @param  list<int>  $monthDays
     * @param  list<int>  $eventDays
     * @return array{0: float, 1: float}|null [イベントの日の予測, 普段の日の予測]
     */
    private function splitMonths(array $values, int $first, int $last, array $monthDays, array $eventDays): ?array
    {
        $event = 0.0;
        $normal = 0.0;
        for ($index = $first; $index <= $last; $index++) {
            $value = $values[$index] ?? null;
            if ($value === null) {
                return null;
            }
            $usual = $value['quantity'] - $value['event'];
            $event += $usual * $eventDays[$index] / $monthDays[$index] + $value['event'];
            $normal += $usual * ($monthDays[$index] - $eventDays[$index]) / $monthDays[$index];
        }

        return [$event, $normal];
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
