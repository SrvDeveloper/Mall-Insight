<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Models\Item;
use App\Models\SalesEventPeriod;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * イベント（B-129、決定記録 K-112・K-114）の期間の、平均日販から除く日と倍率を決める。以下の「イベント」は、イベントの1回の期間を指す。
 *
 * - 平均日販から除く日：登録したすべてのイベントの日。イベントのモールだけで除く（B-134、K-126。楽天市場のイベントの日も、BOSS の
 *   Yahoo!ショッピング・au PAY マーケットの販売実績は平均日販に使う）。
 * - 終わったイベントの倍率：売れた数÷普段なら売れた数（EventMeasurement）。どちらもイベントのモールの分だけを数える。普段なら売れた数は、
 *   イベントの初日時点のイベントのモールの基準平均日販×イベントの日数（季節係数を登録した品番は、日ごとにその月の係数を掛ける、B-106）。
 *   販売実績をイベントの初日から最終日まで取り込んでいるチャネルだけで測る。
 * - これからの期間の倍率の候補（EventCandidate）：同じイベントの、終わった過去の期間の新しい方から3回分を合わせて求める（K-114）。
 *   品番ごとに、売れた数の合計÷普段なら売れた数の合計とし、普段なら売れた数の合計が10個未満の品番は対象品番の全体の倍率を使う。
 *   そのうえで、普段より増える分（倍率－1）を、根拠にした期間の回数 n に応じて n÷(n＋c) 倍に控える（B-135、K-127・K-128。回数が少ないほど、
 *   前の回の倍率をそのまま当てにしない）。c はチャネルごとの SHRINK_PERIODS。
 * - 担当者が期間の品番の倍率を入れていれば、候補より優先する。予測精度の測定（B-130）では、起点の前に終わったイベントの候補だけを使う。
 */
class EventUpliftEstimator
{
    /** 候補に使う過去のイベントの回数（新しい方から）。 */
    public const RECENT_EVENTS = 3;

    /** 品番の倍率を候補にするのに必要な、過去のイベントで普段なら売れた数の合計。これ未満なら全体の倍率を使う。 */
    public const MIN_EXPECTED = 10.0;

    /**
     * チャネルごとの、倍率の候補の増える分を控える強さ（根拠の回数 n に対して n÷(n＋この値)、B-135・K-128）。0なら控えない。
     * Amazon は候補が実際より大きくなりやすく（普段の売れ方が年々伸び、年1回の大型イベントは前年の1回分だけで決まるため）、
     * BOSS は控えると少なめになるため控えない。
     */
    public const SHRINK_PERIODS = ['boss' => 0.0, 'amazon' => 0.5];

    /** 終わった期間を測った結果をキャッシュに残す日数（測るのに使うものが変われば、キーが変わって測り直す）。 */
    private const CACHE_DAYS = 30;

    /** 測り方を変えたら上げる（古いキャッシュを使わないため）。2：イベントのモールの分だけを測る（B-134）。 */
    private const CACHE_VERSION = 2;

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
     * モールごとの、平均日販から除く日（B-134、K-126）。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @return array<string, array<string, true>> モールの値 => [Y-m-d => true]
     */
    public function excludedDates(Collection $events): array
    {
        $dates = [];
        foreach ($events as $event) {
            foreach ($event->salesEvent?->malls ?? [] as $mall) {
                foreach ($event->dates() as $date) {
                    $dates[$mall->value][$date] = true;
                }
            }
        }

        return $dates;
    }

    /**
     * チャネルごとの、いずれかのモールでイベントのある日。季節係数の初期値（K-113）と、予測精度のイベントの日と普段の日の分け方（K-125）に使う。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @return array<string, array<string, true>> チャネルの値 => [Y-m-d => true]
     */
    public function channelEventDates(Collection $events): array
    {
        $dates = [];
        foreach ($this->excludedDates($events) as $mall => $mallDates) {
            $channel = Mall::from($mall)->channel()->value;
            $dates[$channel] = ($dates[$channel] ?? []) + $mallDates;
        }

        return $dates;
    }

    /**
     * モールごとの日のうち、チャネルのモールの分。
     *
     * @param  array<string, array<string, true>>  $dates  モールの値 => [Y-m-d => true]
     * @return array<string, array<string, true>>
     */
    public static function datesOn(array $dates, Channel $channel): array
    {
        return array_intersect_key($dates, array_flip(array_map(fn (Mall $mall): string => $mall->value, $channel->malls())));
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
                        ? new EventUplift($event, $override->multiplier, false, $dates, $event->mallsOn($channel))
                        : new EventUplift($event, $candidate->forItem($item->item_no), true, $dates, $event->mallsOn($channel));
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
     * @param  array<string, array<string, true>>  $excluded  モールごとの、平均日販から除く日
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
            $excludedDates = self::datesOn($excluded, $channel);

            // 測った結果は、測るのに使ったもの（期間・除く日・SKU・季節係数・その期間の販売実績と在庫）が同じ間は変わらないため、キャッシュから使う
            $versions ??= DataVersions::current();
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
                $malls = array_map(fn (Mall $mall): string => $mall->value, $event->mallsOn($channel));
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
                    // イベントのモールの分だけ：普段なら売れた数＝基準平均日販×その日の月の季節係数（登録していなければ1）を、欠品していない日について足す
                    $mallBase = array_sum(array_intersect_key($base->mallBases(), array_flip($malls)));
                    $days = 0.0;
                    $actual = 0;
                    foreach ($dates as $date) {
                        if (isset($skuStockouts[$date])) {
                            continue;
                        }
                        $days += $factors === null ? 1.0 : $factors[(int) substr($date, 5, 2)];
                        foreach ($malls as $mall) {
                            $actual += $skuSales[$mall][$date] ?? 0;
                        }
                    }
                    $current = $byItem[$itemNo] ?? ['expected' => 0.0, 'actual' => 0];
                    $byItem[$itemNo] = ['expected' => $current['expected'] + $mallBase * $days, 'actual' => $current['actual'] + $actual];
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
     * 終わった期間をチャネルで測った結果のキャッシュのキー。測るのに使うものをすべて含める：期間の日付、販売実績の最初の日（平均日販の期間に入るとき）、
     * SKUと季節係数、平均日販の期間とイベントの期間に入る除く日、その間の月の販売実績の版、イベントの最終日の月までの在庫の版
     * （欠品は、記録の無い日に直前の記録の状態を引き継ぐため、それより前の在庫も使う）。
     *
     * @param  Collection<int, array{0: string, 1: Sku}>  $skus
     * @param  array<string, array<int, float>>  $seasonal
     * @param  array<string, array<string, true>>  $excludedDates  モールの値 => [Y-m-d => true]
     */
    private function cacheKey(SalesEventPeriod $event, Channel $channel, CarbonImmutable $salesDataFrom, Collection $skus, array $seasonal, array $excludedDates, DataVersions $versions): string
    {
        $windowFrom = $event->starts_on->subDays(self::windowDays())->toDateString();
        $endsOn = $event->ends_on->toDateString();
        $fromMonth = substr($windowFrom, 0, 7);
        $toMonth = substr($endsOn, 0, 7);
        $excludedInWindow = [];
        foreach ($excludedDates as $mall => $dates) {
            $inWindow = array_values(array_filter(array_keys($dates), fn (string $date): bool => $date >= $windowFrom && $date <= $endsOn));
            sort($inWindow);
            $excludedInWindow[$mall] = $inWindow;
        }
        ksort($excludedInWindow);

        return 'event-measurement:'.md5((string) json_encode([
            self::CACHE_VERSION,
            $channel->value,
            $event->starts_on->toDateString(),
            $endsOn,
            $event->salesEvent?->malls->map(fn (Mall $mall): string => $mall->value)->all(),
            max($salesDataFrom->toDateString(), $windowFrom),
            $skus->map(fn (array $entry): array => [$entry[0], $entry[1]->id, $seasonal[$entry[0]] ?? null])->all(),
            $excludedInWindow,
            $versions->salesBetween($fromMonth, $toMonth),
            $versions->inventoriesUntil($toMonth),
        ]));
    }

    /**
     * 過去のある日（$origin）に戻ったときの、品番ごとのこれからのイベントの倍率の候補（$origin より前に終わった同じイベントの期間から求める）。
     * 担当者が入れた倍率は、後から分かったことで決めた値のため使わない。倍率は $origin 以降の日に掛ける。
     * 予測精度の測定（B-130）と、需要予測の画面の予測し直した数（B-131）で使う。
     *
     * @param  Collection<int, SalesEventPeriod>  $events
     * @param  array<int, array<string, EventMeasurement>>  $measurements
     * @param  iterable<Item>  $items
     * @return array<string, list<EventUplift>> 品番 => イベント
     */
    public function candidateUplifts(Channel $channel, CarbonImmutable $origin, Collection $events, array $measurements, iterable $items): array
    {
        $uplifts = [];
        foreach ($this->upcoming($events, $origin) as $event) {
            if (! $event->isOn($channel)) {
                continue;
            }
            $candidate = $this->candidate($event, $channel, $events, $measurements, $origin);
            $dates = array_values(array_filter($event->dates(), fn (string $date): bool => $date >= $origin->toDateString()));
            foreach ($items as $item) {
                $uplifts[$item->item_no][] = new EventUplift($event, $candidate->forItem($item->item_no), true, $dates, $event->mallsOn($channel));
            }
        }

        return $uplifts;
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
        // 増える分を控える割合（根拠の回数が少ないほど小さい）
        $count = count($basis);
        $strength = static::SHRINK_PERIODS[$channel->value] ?? 0.0;
        $shrink = $count + $strength > 0 ? $count / ($count + $strength) : 1.0;
        $rawOverall = $expected > 0 ? $actual / $expected : null;
        $overall = $rawOverall === null ? null : round(1 + ($rawOverall - 1) * $shrink, 2);

        $items = [];
        foreach ($byItem as $itemNo => $values) {
            if ($rawOverall === null) {
                continue;
            }
            $usesOverall = $values['expected'] < self::MIN_EXPECTED;
            $raw = $usesOverall ? $rawOverall : $values['actual'] / $values['expected'];
            $items[$itemNo] = [
                'multiplier' => round(1 + ($raw - 1) * $shrink, 2),
                'raw' => round($raw, 2),
                'expected' => $values['expected'],
                'actual' => $values['actual'],
                'uses_overall' => $usesOverall,
            ];
        }

        return new EventCandidate($channel, $basis, $overall, $expected, $actual, $items, $rawOverall === null ? null : round($rawOverall, 2));
    }
}
