<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Models\SalesEventMultiplier;
use App\Models\SalesEventPeriod;
use App\Services\ItemSelection\TargetItems;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * イベントの画面（B-129、K-112・K-114）に出す、期間ごとの倍率。これからの期間（始まる前・開催中）は倍率の候補とその根拠、
 * 終わった期間は普段の何倍売れたか（測れたチャネルだけ）。対象品番（最新の選定）のSKUで求める。以下の $event はイベントの1回の期間。
 * 季節係数を登録した品番は、普段なら売れた数に季節係数を掛けて比べる（B-106）。
 */
class SalesEventReport
{
    public function __construct(private DemandForecaster $forecaster, private EventUpliftEstimator $estimator) {}

    /**
     * 一覧に添える、期間・チャネルごとの倍率（品番の全体）。$targets の期間だけ求める（画面に出す期間だけ測り、速くする）。
     * 終わった期間はその期間を測り、これからの期間の候補は、需要予測と同じく candidateBasis() の期間から求める。
     *
     * @param  Collection<int, SalesEventPeriod>  $events  登録したすべての期間（イベントを読み込んだもの。平均日販から除く日と候補の根拠に使う）
     * @param  Collection<int, SalesEventPeriod>  $targets  倍率を求める期間（$events に含まれるもの）
     * @return array<int, list<array<string, mixed>>> 期間ID => チャネルごとの倍率
     */
    public function overview(Collection $events, Collection $targets, CarbonImmutable $today): array
    {
        $items = $this->targetItems();
        $ranges = $this->forecaster->channelSalesRanges($today);
        $ended = $targets->filter(fn (SalesEventPeriod $event): bool => $event->hasEndedBefore($today));
        $basis = $this->estimator->candidateBasis($events, $targets->reject(fn (SalesEventPeriod $event): bool => $event->hasEndedBefore($today)), $today, $ranges);
        $measurements = $this->estimator->measure($this->forecaster, $this->estimator->excludedDates($events), $ended->merge($basis)->unique('id')->values(), $items, $ranges, ItemSeasonalFactor::currentFactors($items->pluck('item_no')->all()));
        $basisMeasurements = array_intersect_key($measurements, array_flip($basis->pluck('id')->all()));

        $overview = [];
        foreach ($targets as $event) {
            $overview[$event->id] = array_map(function (Channel $channel) use ($event, $events, $measurements, $basisMeasurements, $today): array {
                if ($event->hasEndedBefore($today)) {
                    $measurement = $measurements[$event->id][$channel->value] ?? null;

                    return [
                        'channel' => $channel->value,
                        'label' => $channel->label(),
                        'kind' => 'measured',
                        'multiplier' => $this->round($measurement?->ratio()),
                        'raw_multiplier' => null,
                        'basis_count' => 0,
                        'override_count' => 0,
                    ];
                }
                $candidate = $this->estimator->candidate($event, $channel, $events, $basisMeasurements, $today);

                return [
                    'channel' => $channel->value,
                    'label' => $channel->label(),
                    'kind' => 'candidate',
                    'multiplier' => $candidate->overall,
                    // 根拠の回数に応じて控える前の倍率（B-135、K-127・K-128）
                    'raw_multiplier' => $candidate->rawOverall,
                    'basis_count' => count($candidate->basis),
                    'override_count' => $event->multipliers->filter(fn (SalesEventMultiplier $multiplier): bool => $multiplier->channel === $channel)->count(),
                ];
            }, $event->channels());
        }

        return $overview;
    }

    /**
     * 1つの期間の、チャネル・品番ごとの倍率。これからの期間では、今の需要予測の基準平均日販から、
     * 期間（今日以降）の普段の予測と、倍率で増やした数を添える。
     *
     * @return array{remaining_days: int, channels: list<array<string, mixed>>}
     */
    public function detail(SalesEventPeriod $event, CarbonImmutable $today): array
    {
        $events = $this->estimator->periods();
        $event = $events->firstWhere('id', $event->id) ?? $event->load(['salesEvent', 'multipliers']);
        $items = $this->targetItems();
        $ranges = $this->forecaster->channelSalesRanges($today);
        $excluded = $this->estimator->excludedDates($events);
        $remainingDays = count(array_filter($event->dates(), fn (string $date): bool => $date >= $today->toDateString()));

        if ($event->hasEndedBefore($today)) {
            $measurements = $this->estimator->measure($this->forecaster, $excluded, collect([$event]), $items, $ranges, ItemSeasonalFactor::currentFactors($items->pluck('item_no')->all()));

            return [
                'remaining_days' => 0,
                'channels' => array_map(fn (Channel $channel): array => $this->measuredChannel($channel, $measurements[$event->id][$channel->value] ?? null, $items, $ranges), $event->channels()),
            ];
        }

        $measurements = $this->estimator->measure($this->forecaster, $excluded, $this->estimator->candidateBasis($events, collect([$event]), $today, $ranges), $items, $ranges, ItemSeasonalFactor::currentFactors($items->pluck('item_no')->all()));
        $forecast = $this->forecaster->forecast($today, $items->pluck('item_no')->all());

        return [
            'remaining_days' => $remainingDays,
            'channels' => array_map(fn (Channel $channel): array => $this->candidateChannel($event, $channel, $this->estimator->candidate($event, $channel, $events, $measurements, $today), $forecast, $today), $event->channels()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function candidateChannel(SalesEventPeriod $event, Channel $channel, EventCandidate $candidate, DemandForecast $forecast, CarbonImmutable $today): array
    {
        $remainingDates = array_values(array_filter($event->dates(), fn (string $date): bool => $date >= $today->toDateString()));
        // 期間の普段の予測は、イベントのモールの基準平均日販から求める（倍率はそのモールの分にだけ掛けるため、B-134・K-126）
        $malls = array_flip(array_map(fn (Mall $mall): string => $mall->value, $event->mallsOn($channel)));
        $rows = [];
        foreach ($forecast->items as $entry) {
            /** @var Item $item */
            $item = $entry['item'];
            $baseAverage = null;
            $extra = 0.0;
            foreach ($entry['skus'] as $sku) {
                foreach ($sku->channels as $channelForecast) {
                    if ($channelForecast->channel !== $channel || ! $channelForecast->isPredictable()) {
                        continue;
                    }
                    $baseAverage = ($baseAverage ?? 0.0) + array_sum(array_intersect_key($channelForecast->mallBases(), $malls));
                    foreach ($channelForecast->monthly as $month) {
                        foreach ($month->events as $monthEvent) {
                            if ($monthEvent->periodId === $event->id) {
                                $extra += $monthEvent->quantity;
                            }
                        }
                    }
                }
            }
            $override = $event->multipliers->first(fn (SalesEventMultiplier $multiplier): bool => $multiplier->item_no === $item->item_no && $multiplier->channel === $channel);
            $past = $candidate->items[$item->item_no] ?? null;
            $candidateValue = $candidate->forItem($item->item_no);
            $rows[] = [
                'item_no' => $item->item_no,
                'brand' => $item->brand,
                'category' => $item->category,
                'candidate' => $candidateValue,
                'candidate_raw' => $candidateValue === null ? null : ($past['raw'] ?? $candidate->rawOverall),
                'uses_overall' => $candidateValue !== null && $candidate->usesOverall($item->item_no),
                'past_expected' => $past === null ? null : round($past['expected'], 2),
                'past_actual' => $past['actual'] ?? null,
                'override' => $override?->multiplier,
                'multiplier' => $override?->multiplier ?? $candidateValue,
                'base_average' => $baseAverage === null ? null : round($baseAverage, 4),
                'usual' => $baseAverage === null ? null : round($baseAverage * $this->seasonalDays($remainingDates, $forecast->seasonalFactors[$item->item_no] ?? null), 2),
                'extra' => $baseAverage === null ? null : round($extra, 2),
            ];
        }

        return [
            'channel' => $channel->value,
            'label' => $channel->label(),
            'kind' => 'candidate',
            'overall' => $candidate->overall,
            'overall_raw' => $candidate->rawOverall,
            'expected' => round($candidate->expected, 2),
            'actual' => $candidate->actual,
            'basis' => array_map(fn (EventMeasurement $measurement): array => [
                'id' => $measurement->event->id,
                'name' => $measurement->event->name,
                'note' => $measurement->event->note,
                'starts_on' => $measurement->event->starts_on->toDateString(),
                'ends_on' => $measurement->event->ends_on->toDateString(),
                'expected' => round($measurement->expected, 2),
                'actual' => $measurement->actual,
                'ratio' => $this->round($measurement->ratio()),
            ], $candidate->basis),
            'note' => null,
            'items' => $rows,
        ];
    }

    /**
     * @param  Collection<int, Item>  $items
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $ranges
     * @return array<string, mixed>
     */
    private function measuredChannel(Channel $channel, ?EventMeasurement $measurement, Collection $items, array $ranges): array
    {
        $range = collect($ranges)->first(fn (array $range): bool => $range['channel'] === $channel);
        $note = null;
        if ($measurement === null) {
            $note = $range === null || $range['from'] === null
                ? "{$channel->label()}の販売実績がまだ取り込まれていないため、測れません。"
                : "{$channel->label()}の販売実績を、イベントの期間すべては取り込んでいないため、測れません。";
        }

        return [
            'channel' => $channel->value,
            'label' => $channel->label(),
            'kind' => 'measured',
            'overall' => $this->round($measurement?->ratio()),
            'expected' => $measurement === null ? null : round($measurement->expected, 2),
            'actual' => $measurement?->actual,
            'basis' => [],
            'note' => $note,
            'items' => $measurement === null ? [] : $items->map(function (Item $item) use ($measurement): array {
                $values = $measurement->items[$item->item_no] ?? null;

                return [
                    'item_no' => $item->item_no,
                    'brand' => $item->brand,
                    'category' => $item->category,
                    'expected' => $values === null ? null : round($values['expected'], 2),
                    'actual' => $values['actual'] ?? null,
                    'ratio' => $values === null || $values['expected'] <= 0 ? null : $this->round($values['actual'] / $values['expected']),
                ];
            })->values()->all(),
        ];
    }

    /**
     * 日数（季節係数を登録した品番は、日ごとにその月の係数を掛けて足す）。
     *
     * @param  list<string>  $dates
     * @param  array<int, float>|null  $factors
     */
    private function seasonalDays(array $dates, ?array $factors): float
    {
        return array_sum(array_map(fn (string $date): float => $factors === null ? 1.0 : $factors[(int) substr($date, 5, 2)], $dates));
    }

    /**
     * 全体・Amazon・BOSS のどれかに入っている対象品番（B-136、K-129）。
     *
     * @return Collection<int, Item>
     */
    private function targetItems(): Collection
    {
        return $this->forecaster->items(collect(app(TargetItems::class)->all()));
    }

    private function round(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
