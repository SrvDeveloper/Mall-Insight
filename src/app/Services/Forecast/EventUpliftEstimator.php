<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\Item;
use App\Models\SalesEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * イベント（B-129、決定記録 K-112）の、平均日販から除く日と倍率を決める。
 *
 * - 平均日販から除く日：登録したすべてのイベントの日。イベントのモールのチャネル（楽天市場・Yahoo!ショッピング・au PAY マーケットは BOSS）だけで除く。
 * - 終わったイベントの倍率：売れた数÷普段なら売れた数（EventMeasurement）。普段なら売れた数は、イベントの初日時点の基準平均日販×イベントの日数
 *   （季節係数を登録した品番は、日ごとにその月の係数を掛ける、B-106）。
 *   販売実績をイベントの初日から最終日まで取り込んでいるチャネルだけで測る。
 * - これからのイベントの倍率の候補（EventCandidate）：同じ名前（SalesEvent::nameKey()）の、終わった過去のイベントの新しい方から3回分を合わせて求める。
 *   品番ごとに、売れた数の合計÷普段なら売れた数の合計とし、普段なら売れた数の合計が10個未満の品番は対象品番の全体の倍率を使う。
 * - 担当者が品番の倍率を入れていれば、候補より優先する。予測精度の測定（B-130）では、起点の前に終わったイベントの候補だけを使う。
 */
class EventUpliftEstimator
{
    /** 候補に使う過去のイベントの回数（新しい方から）。 */
    public const RECENT_EVENTS = 3;

    /** 品番の倍率を候補にするのに必要な、過去のイベントで普段なら売れた数の合計。これ未満なら全体の倍率を使う。 */
    public const MIN_EXPECTED = 10.0;

    /**
     * 登録したすべてのイベント（初日の順）。
     *
     * @return Collection<int, SalesEvent>
     */
    public function events(): Collection
    {
        return SalesEvent::query()->with('multipliers')->orderBy('starts_on')->orderBy('id')->get();
    }

    /**
     * チャネルごとの、平均日販から除く日。
     *
     * @param  Collection<int, SalesEvent>  $events
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
        $events ??= $this->events();
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
     * 候補を求めるのに測る、終わったイベント。これからのイベントと同じ名前で、チャネルごとに販売実績の期間に入る新しい方から3回分だけ
     * （候補はそれより古いイベントを使わないため、測る期間を短くして速くする）。
     *
     * @param  Collection<int, SalesEvent>  $events
     * @param  Collection<int, SalesEvent>  $upcoming
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @return Collection<int, SalesEvent>
     */
    public function candidateBasis(Collection $events, Collection $upcoming, CarbonImmutable $day, array $channelRanges): Collection
    {
        $names = $upcoming->map(fn (SalesEvent $event): string => $event->nameKey())->unique()->all();
        $ended = $events->filter(fn (SalesEvent $event): bool => $event->hasEndedBefore($day) && in_array($event->nameKey(), $names, true))->sortByDesc(fn (SalesEvent $event): string => $event->starts_on->toDateString());
        $basis = collect();
        foreach ($channelRanges as $range) {
            if ($range['from'] === null || $range['to'] === null) {
                continue;
            }
            $inRange = $ended->filter(fn (SalesEvent $event): bool => $event->isOn($range['channel']) && $event->starts_on->gte($range['from']) && $event->ends_on->lte($range['to']));
            foreach ($inRange->groupBy(fn (SalesEvent $event): string => $event->nameKey()) as $group) {
                $basis = $basis->merge($group->take(self::RECENT_EVENTS));
            }
        }

        return $basis->unique('id')->values();
    }

    /**
     * $day 時点でこれからのイベント（$day 以降に終わり、$day の月から12か月の間に始まるもの）。
     *
     * @param  Collection<int, SalesEvent>  $events
     * @return Collection<int, SalesEvent>
     */
    public function upcoming(Collection $events, CarbonImmutable $day): Collection
    {
        $horizonEnd = $day->startOfMonth()->addMonthsNoOverflow(DemandForecaster::MONTHS);

        return $events->filter(fn (SalesEvent $event): bool => ! $event->hasEndedBefore($day) && $event->starts_on->lt($horizonEnd))->values();
    }

    /**
     * 終わったイベントが、チャネルごとに普段の何倍売れたか。販売実績をイベントの期間すべて取り込んでいないチャネルは測らない。
     *
     * @param  array<string, array<string, true>>  $excluded  チャネルごとの、平均日販から除く日
     * @param  Collection<int, SalesEvent>  $targets
     * @param  Collection<int, Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges
     * @param  array<string, array<int, float>>  $seasonal  品番 => 季節係数（B-106）。登録した品番は、普段なら売れた数に季節係数を掛ける
     * @return array<int, array<string, EventMeasurement>> イベントID => チャネルの値 => 測った結果
     */
    public function measure(DemandForecaster $forecaster, array $excluded, Collection $targets, Collection $items, array $channelRanges, array $seasonal = []): array
    {
        $measurements = [];
        foreach ($channelRanges as $range) {
            $channel = $range['channel'];
            if ($range['from'] === null || $range['to'] === null) {
                continue;
            }
            $measurable = $targets->filter(fn (SalesEvent $event): bool => $event->isOn($channel) && $event->starts_on->gte($range['from']) && $event->ends_on->lte($range['to']))->values();
            $skus = $items->flatMap(fn (Item $item) => $item->skus->filter(fn ($sku): bool => DemandForecaster::sellsOn($sku, $channel))->map(fn ($sku): array => [$item->item_no, $sku]))->values();
            if ($measurable->isEmpty() || $skus->isEmpty()) {
                continue;
            }

            // 平均日販の期間（初日の180日前から）とイベントの期間の販売実績・欠品を、1回で読む
            $windowDays = array_sum(array_keys(DemandForecaster::WINDOW_WEIGHTS));
            $loadFrom = $measurable->min(fn (SalesEvent $event): CarbonImmutable => $event->starts_on)->subDays($windowDays)->max($range['from']);
            $loadTo = $measurable->max(fn (SalesEvent $event): CarbonImmutable => $event->ends_on);
            $skuIds = $skus->map(fn (array $entry): int => $entry[1]->id)->all();
            $sales = $forecaster->channelDailySales($skuIds, $channel, $loadFrom, $loadTo);
            $stockouts = $forecaster->channelStockoutDates($skuIds, $channel, $loadFrom, $loadTo);
            $excludedDates = $excluded[$channel->value] ?? [];

            foreach ($measurable as $event) {
                $plan = $forecaster->windowPlan($range['from'], $range['to'], $event->starts_on);
                $dates = $event->dates();
                $byItem = [];
                $skuCount = 0;
                foreach ($skus as [$itemNo, $sku]) {
                    $skuSales = $sales[$sku->id] ?? [];
                    $skuStockouts = $stockouts[$sku->id] ?? [];
                    $factors = $seasonal[$itemNo] ?? null;
                    $base = $forecaster->forecastChannelWith($channel, $plan, $skuSales, $skuStockouts, $event->starts_on, $excludedDates, [], $factors);
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
                $measurements[$event->id][$channel->value] = new EventMeasurement(
                    $event,
                    $channel,
                    $byItem,
                    array_sum(array_column($byItem, 'expected')),
                    array_sum(array_column($byItem, 'actual')),
                    $skuCount,
                );
            }
        }

        return $measurements;
    }

    /**
     * $event の、$asOf 時点の倍率の候補。$asOf より前に終わり、$event より前に始まった同じ名前のイベントのうち、
     * そのチャネルで測れたものを新しい方から3回分使う。倍率は小数2桁に丸める（画面に出す値と計算に使う値をそろえる）。
     *
     * @param  Collection<int, SalesEvent>  $events
     * @param  array<int, array<string, EventMeasurement>>  $measurements
     */
    public function candidate(SalesEvent $event, Channel $channel, Collection $events, array $measurements, CarbonImmutable $asOf): EventCandidate
    {
        $basis = $events
            ->filter(fn (SalesEvent $past): bool => $past->id !== $event->id
                && $past->nameKey() === $event->nameKey()
                && $past->hasEndedBefore($asOf)
                && $past->starts_on->lt($event->starts_on)
                && ($measurements[$past->id][$channel->value] ?? null)?->expected > 0)
            ->sortByDesc(fn (SalesEvent $past): string => $past->starts_on->toDateString())
            ->take(self::RECENT_EVENTS)
            ->map(fn (SalesEvent $past): EventMeasurement => $measurements[$past->id][$channel->value])
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
