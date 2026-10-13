<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\Item;
use App\Models\SalesEventPeriod;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * イベント（B-129、決定記録 K-112・K-114）の期間の、平均日販から除く日と倍率を決める。以下の「イベント」は、イベントの1回の期間を指す。
 *
 * - 平均日販から除く日：登録したすべてのイベントの日。イベントのモールのチャネル（楽天市場・Yahoo!ショッピング・au PAY マーケットは BOSS）だけで除く。
 * - 終わったイベントの倍率：売れた数÷普段なら売れた数（EventMeasurement）。普段なら売れた数は、イベントの初日時点の基準平均日販×イベントの日数
 *   （季節係数を登録した品番は、日ごとにその月の係数を掛ける、B-106）。
 *   販売実績をイベントの初日から最終日まで取り込んでいるチャネルだけで測る。
 * - これからの期間の倍率の候補（EventCandidate）：同じイベントの、終わった過去の期間の新しい方から3回分を合わせて求める（K-114）。
 *   品番ごとに、売れた数の合計÷普段なら売れた数の合計とし、普段なら売れた数の合計が10個未満の品番は対象品番の全体の倍率を使う。
 * - 担当者が期間の品番の倍率を入れていれば、候補より優先する。予測精度の測定（B-130）では、起点の前に終わったイベントの候補だけを使う。
 */
class EventUpliftEstimator
{
    /** 候補に使う過去のイベントの回数（新しい方から）。 */
    public const RECENT_EVENTS = 3;

    /** 品番の倍率を候補にするのに必要な、過去のイベントで普段なら売れた数の合計。これ未満なら全体の倍率を使う。 */
    public const MIN_EXPECTED = 10.0;

    /** 終わった期間を測った結果をキャッシュに残す日数（測るのに使うものが変われば、キーが変わって測り直す）。 */
    private const CACHE_DAYS = 30;

    /** 測り方を変えたら上げる（古いキャッシュを使わないため）。 */
    private const CACHE_VERSION = 1;

    /**
     * 登録したすべてのイベントの期間（初日の順）。イベント（名前・モール）と担当者が入れた倍率を読み込んでおく。
     *
     * @return Collection<int, SalesEventPeriod>
     */
    public function periods(): Collection
    {
        return SalesEventPeriod::query()->with(['salesEvent', 'multipliers'])->orderBy('starts_on')->orderBy('id')->get();
    }

    /**
     * チャネルごとの、平均日販から除く日。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @return array<string, array<string, true>> チャネルの値 => [Y-m-d => true]
     */
    public function excludedDates(Collection $events): array
    {
        $dates = [];
        foreach ($events as $event) {
            foreach ($event->channels() as $channel) {
                foreach ($event->dates() as $date) {
                    $dates[$channel->value][$date] = true;
                }
            }
        }

        return $dates;
    }

    /**
     * 今日時点の需要予測に使うイベント。これからのイベント（今日以降に終わり、予測する12か月に入るもの）の品番ごとの倍率と、平均日販から除く日。
     *
     * @param  Collection<int, Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @param  array<string, array<int, float>>  $seasonal  品番 => 登録した季節係数
     */
    public function plan(DemandForecaster $forecaster, CarbonImmutable $forecastFrom, Collection $items, array $channelRanges, ?Collection $events = null, array $seasonal = []): EventPlan
    {
        $events ??= $this->periods();
        $excluded = $this->excludedDates($events);
        $upcoming = $this->upcoming($events, $forecastFrom);
        if ($upcoming->isEmpty()) {
            return new EventPlan($excluded);
        }
        $measurements = $this->measure($forecaster, $excluded, $this->candidateBasis($events, $upcoming, $forecastFrom, $channelRanges), $items, $channelRanges, $seasonal);

        $uplifts = [];
        foreach ($upcoming as $event) {
            $dates = array_values(array_filter($event->dates(), fn (string $date): bool => $date >= $forecastFrom->toDateString()));
            foreach ($event->channels() as $channel) {
                $candidate = $this->candidate($event, $channel, $events, $measurements, $forecastFrom);
                foreach ($items as $item) {
                    $override = $event->multipliers->first(fn ($multiplier): bool => $multiplier->item_no === $item->item_no && $multiplier->channel === $channel);
                    $uplifts[$channel->value][$item->item_no][] = $override !== null
                        ? new EventUplift($event, $override->multiplier, false, $dates)
                        : new EventUplift($event, $candidate->forItem($item->item_no), true, $dates);
                }
            }
        }

        return new EventPlan($excluded, $uplifts);
    }

    /**
     * 候補を求めるのに測る、終わった期間。これからの期間と同じイベントで、チャネルごとに販売実績の期間に入る新しい方から3回分だけ
     * （候補はそれより古いイベントを使わないため、測る期間を短くして速くする）。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @param  Collection<int, SalesEventPeriod>  $upcoming
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @return Collection<int, SalesEventPeriod>
     */
    public function candidateBasis(Collection $events, Collection $upcoming, CarbonImmutable $day, array $channelRanges): Collection
    {
        $names = $upcoming->map(fn (SalesEventPeriod $event): string => $event->eventKey())->unique()->all();
        $ended = $events->filter(fn (SalesEventPeriod $event): bool => $event->hasEndedBefore($day) && in_array($event->eventKey(), $names, true))->sortByDesc(fn (SalesEventPeriod $event): string => $event->starts_on->toDateString());
        $basis = collect();
        foreach ($channelRanges as $range) {
            if ($range['from'] === null || $range['to'] === null) {
                continue;
            }
            $inRange = $ended->filter(fn (SalesEventPeriod $event): bool => $event->isOn($range['channel']) && $event->starts_on->gte($range['from']) && $event->ends_on->lte($range['to']));
            foreach ($inRange->groupBy(fn (SalesEventPeriod $event): string => $event->eventKey()) as $group) {
                $basis = $basis->merge($group->take(self::RECENT_EVENTS));
            }
        }

        return $basis->unique('id')->values();
    }

    /**
     * $day 時点でこれからのイベント（$day 以降に終わり、$day の月から12か月の間に始まるもの）。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @return Collection<int, SalesEventPeriod>
     */
    public function upcoming(Collection $events, CarbonImmutable $day): Collection
    {
        $horizonEnd = $day->startOfMonth()->addMonthsNoOverflow(DemandForecaster::MONTHS);

        return $events->filter(fn (SalesEventPeriod $event): bool => ! $event->hasEndedBefore($day) && $event->starts_on->lt($horizonEnd))->values();
    }

    /**
     * 終わったイベントが、チャネルごとに普段の何倍売れたか。販売実績をイベントの期間すべて取り込んでいないチャネルは測らない。
     *
     * @param  array<string, array<string, true>>  $excluded  チャネルごとの、平均日販から除く日
     * @param  Collection<int, SalesEventPeriod>  $targets
     * @param  Collection<int, Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @param  array<string, array<int, float>>  $seasonal  品番 => 季節係数（B-106）。登録した品番は、普段なら売れた数に季節係数を掛ける
     * @return array<int, array<string, EventMeasurement>> イベントID => チャネルの値 => 測った結果
     */
    public function measure(DemandForecaster $forecaster, array $excluded, Collection $targets, Collection $items, array $channelRanges, array $seasonal = []): array
    {
        $measurements = [];
        $versions = null;
        foreach ($channelRanges as $range) {
            $channel = $range['channel'];
            if ($range['from'] === null || $range['to'] === null) {
                continue;
            }
            $measurable = $targets->filter(fn (SalesEventPeriod $event): bool => $event->isOn($channel) && $event->starts_on->gte($range['from']) && $event->ends_on->lte($range['to']))->values();
            $skus = $items->flatMap(fn (Item $item) => $item->skus->filter(fn ($sku): bool => DemandForecaster::sellsOn($sku, $channel))->map(fn ($sku): array => [$item->item_no, $sku]))->values();
            if ($measurable->isEmpty() || $skus->isEmpty()) {
                continue;
            }
            $excludedDates = $excluded[$channel->value] ?? [];

            // 測った結果は、測るのに使ったもの（期間・除く日・SKU・季節係数・その期間の販売実績と在庫）が同じ間は変わらないため、キャッシュから使う
            $versions ??= $this->dataVersions();
            $keys = $measurable->mapWithKeys(fn (SalesEventPeriod $event): array => [$event->id => $this->cacheKey($event, $channel, $range['from'], $skus, $seasonal, $excludedDates, $versions)])->all();
            $cached = Cache::many(array_values($keys));
            $missing = $measurable->filter(fn (SalesEventPeriod $event): bool => ! is_array($cached[$keys[$event->id]] ?? null))->values();
            foreach ($measurable as $event) {
                $values = $cached[$keys[$event->id]] ?? null;
                if (is_array($values)) {
                    $measurements[$event->id][$channel->value] = new EventMeasurement($event, $channel, $values['items'], $values['expected'], $values['actual'], $values['sku_count']);
                }
            }
            if ($missing->isEmpty()) {
                continue;
            }

            // 平均日販の期間（初日の180日前から）とイベントの期間の販売実績・欠品を、1回で読む
            $loadFrom = $missing->min(fn (SalesEventPeriod $event): CarbonImmutable => $event->starts_on)->subDays(self::windowDays())->max($range['from']);
            $loadTo = $missing->max(fn (SalesEventPeriod $event): CarbonImmutable => $event->ends_on);
            $skuIds = $skus->map(fn (array $entry): int => $entry[1]->id)->all();
            $sales = $forecaster->channelDailySales($skuIds, $channel, $loadFrom, $loadTo);
            $stockouts = $forecaster->channelStockoutDates($skuIds, $channel, $loadFrom, $loadTo);

            $computed = [];
            foreach ($missing as $event) {
                $plan = $forecaster->windowPlan($range['from'], $range['to'], $event->starts_on);
                $dates = $event->dates();
                $byItem = [];
                $skuCount = 0;
                foreach ($skus as [$itemNo, $sku]) {
                    $skuSales = $sales[$sku->id] ?? [];
                    $skuStockouts = $stockouts[$sku->id] ?? [];
                    $factors = $seasonal[$itemNo] ?? null;
                    $base = $forecaster->forecastChannelWith($channel, $plan, $skuSales, $skuStockouts, $event->starts_on, $excludedDates, [], $factors, withMonthly: false);
                    if (! $base->isPredictable()) {
                        continue;
                    }
                    // 普段なら売れた数＝基準平均日販×その日の月の季節係数（登録していなければ1）を、欠品していない日について足す
                    $days = 0.0;
                    $actual = 0;
                    foreach ($dates as $date) {
                        if (isset($skuStockouts[$date])) {
                            continue;
                        }
                        $days += $factors === null ? 1.0 : $factors[(int) substr($date, 5, 2)];
                        $actual += $skuSales[$date] ?? 0;
                    }
                    $current = $byItem[$itemNo] ?? ['expected' => 0.0, 'actual' => 0];
                    $byItem[$itemNo] = ['expected' => $current['expected'] + $base->baseAverage * $days, 'actual' => $current['actual'] + $actual];
                    $skuCount++;
                }
                $values = ['items' => $byItem, 'expected' => array_sum(array_column($byItem, 'expected')), 'actual' => array_sum(array_column($byItem, 'actual')), 'sku_count' => $skuCount];
                $computed[$keys[$event->id]] = $values;
                $measurements[$event->id][$channel->value] = new EventMeasurement($event, $channel, $values['items'], $values['expected'], $values['actual'], $values['sku_count']);
            }
            Cache::putMany($computed, now()->addDays(self::CACHE_DAYS));
        }

        return $measurements;
    }

    /** 平均日販を出す期間の日数の合計（初日の何日前から販売実績を使うか）。 */
    private static function windowDays(): int
    {
        return array_sum(array_keys(DemandForecaster::WINDOW_WEIGHTS));
    }

    /**
     * 販売実績と在庫の、月ごとの版（いちばん大きい行IDと行数）。取込は行を消して入れ直すため、月の中身が変わればどちらかが変わる。
     *
     * @return array{sales: array<string, string>, inventories: array<string, string>} 種類 => 年月（Y-m） => 版
     */
    private function dataVersions(): array
    {
        $byMonth = fn (string $table, string $column): array => DB::table($table)
            ->selectRaw("substr({$column}, 1, 7) as month, max(id) as max_id, count(*) as row_count")
            ->groupByRaw("substr({$column}, 1, 7)")
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->month => $row->max_id.'/'.$row->row_count])
            ->all();

        return ['sales' => $byMonth('sales_lines', 'sales_date'), 'inventories' => $byMonth('inventories', 'stock_date')];
    }

    /**
     * 終わった期間をチャネルで測った結果のキャッシュのキー。測るのに使うものをすべて含める：期間の日付、販売実績の最初の日（平均日販の期間に入るとき）、
     * SKUと季節係数、平均日販の期間とイベントの期間に入る除く日、その間の月の販売実績の版、イベントの最終日の月までの在庫の版
     * （欠品は、記録の無い日に直前の記録の状態を引き継ぐため、それより前の在庫も使う）。
     *
     * @param  Collection<int, array{0: string, 1: Sku}>  $skus
     * @param  array<string, array<int, float>>  $seasonal
     * @param  array<string, true>  $excludedDates
     * @param  array{sales: array<string, string>, inventories: array<string, string>}  $versions
     */
    private function cacheKey(SalesEventPeriod $event, Channel $channel, CarbonImmutable $salesDataFrom, Collection $skus, array $seasonal, array $excludedDates, array $versions): string
    {
        $windowFrom = $event->starts_on->subDays(self::windowDays())->toDateString();
        $endsOn = $event->ends_on->toDateString();
        $fromMonth = substr($windowFrom, 0, 7);
        $toMonth = substr($endsOn, 0, 7);
        $excludedInWindow = array_values(array_filter(array_keys($excludedDates), fn (string $date): bool => $date >= $windowFrom && $date <= $endsOn));
        sort($excludedInWindow);

        return 'event-measurement:'.md5((string) json_encode([
            self::CACHE_VERSION,
            $channel->value,
            $event->starts_on->toDateString(),
            $endsOn,
            max($salesDataFrom->toDateString(), $windowFrom),
            $skus->map(fn (array $entry): array => [$entry[0], $entry[1]->id, $seasonal[$entry[0]] ?? null])->all(),
            $excludedInWindow,
            array_filter($versions['sales'], fn (string $month): bool => $month >= $fromMonth && $month <= $toMonth, ARRAY_FILTER_USE_KEY),
            array_filter($versions['inventories'], fn (string $month): bool => $month <= $toMonth, ARRAY_FILTER_USE_KEY),
        ]));
    }

    /**
     * $event の、$asOf 時点の倍率の候補。$asOf より前に終わり、$event より前に始まった同じイベントの期間のうち、
     * そのチャネルで測れたものを新しい方から3回分使う。倍率は小数2桁に丸める（画面に出す値と計算に使う値をそろえる）。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @param  array<int, array<string, EventMeasurement>>  $measurements
     */
    public function candidate(SalesEventPeriod $event, Channel $channel, Collection $events, array $measurements, CarbonImmutable $asOf): EventCandidate
    {
        $basis = $events
            ->filter(fn (SalesEventPeriod $past): bool => $past->id !== $event->id
                && $past->eventKey() === $event->eventKey()
                && $past->hasEndedBefore($asOf)
                && $past->starts_on->lt($event->starts_on)
                && ($measurements[$past->id][$channel->value] ?? null)?->expected > 0)
            ->sortByDesc(fn (SalesEventPeriod $past): string => $past->starts_on->toDateString())
            ->take(self::RECENT_EVENTS)
            ->map(fn (SalesEventPeriod $past): EventMeasurement => $measurements[$past->id][$channel->value])
            ->values()
            ->all();

        $expected = 0.0;
        $actual = 0;
        $byItem = [];
        foreach ($basis as $measurement) {
            $expected += $measurement->expected;
            $actual += $measurement->actual;
            foreach ($measurement->items as $itemNo => $values) {
                $current = $byItem[$itemNo] ?? ['expected' => 0.0, 'actual' => 0];
                $byItem[$itemNo] = ['expected' => $current['expected'] + $values['expected'], 'actual' => $current['actual'] + $values['actual']];
            }
        }
        $overall = $expected > 0 ? round($actual / $expected, 2) : null;

        $items = [];
        foreach ($byItem as $itemNo => $values) {
            $usesOverall = $values['expected'] < self::MIN_EXPECTED;
            $multiplier = $usesOverall ? $overall : round($values['actual'] / $values['expected'], 2);
            if ($multiplier !== null) {
                $items[$itemNo] = ['multiplier' => $multiplier, 'expected' => $values['expected'], 'actual' => $values['actual'], 'uses_overall' => $usesOverall];
            }
        }

        return new EventCandidate($channel, $basis, $overall, $expected, $actual, $items);
    }
}
