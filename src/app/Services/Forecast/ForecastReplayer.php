<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Models\SalesEventPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * 過去のある月の1日（起点）に戻って、今の計算方法で需要予測をやり直す（需要予測の画面の予測し直した数、B-131・K-123）。
 * 計算は予測精度（B-130）の今の方式と同じ：起点の前日までの販売実績・在庫だけを使い、登録したイベントの日を平均日販から除き、
 * 起点以降のイベントの日に、起点より前に終わった同じイベントの期間から求めた倍率の候補を掛け、登録した季節係数を掛ける。
 * 担当者が入れたイベントの倍率は、後から分かったことで決めた値のため使わない。
 *
 * 起点・チャネルごとの結果は、使ったもの（SKU、季節係数、イベントの期間、起点の月より前の販売実績と起点の月までの在庫）が
 * 変わらない間はキャッシュから使う。
 */
class ForecastReplayer
{
    /** 予測し直した月別の予測を、起点の月から何か月分残すか（6か月前の予測まで選べるため、起点の月を含めて7か月）。 */
    public const MONTHS = 7;

    /** 結果をキャッシュに残す日数。 */
    private const CACHE_DAYS = 30;

    /** 計算の方法を変えたら上げる（古いキャッシュを使わないため）。2：モールごとに平均日販を求め、倍率をイベントのモールにだけ掛ける（B-134）。3：倍率の候補を控えめにする（B-135）。 */
    private const CACHE_VERSION = 3;

    public function __construct(private DemandForecaster $forecaster, private EventUpliftEstimator $eventUplifts) {}

    /**
     * @param  list<Item>  $items  有効なSKUを読み込んだ品番
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channelRanges  今日時点の販売実績の期間
     * @param  list<CarbonImmutable>  $origins  起点（月の1日、今日より前）
     * @return array<string, array<string, array{skus: array<int, array{base: float|null, bases: array<string, float>, monthly: list<float>|null}>, uplifts: array<string, list<array{dates: list<string>, multiplier: float, malls: list<string>}>>}>>
     *                                                                                                                                                                                                                                                  起点（Y-m-d） => チャネルの値 => SKU ID => 基準平均日販・モールごとの基準平均日販と起点の月からの月別の予測（予測できなければ null）、品番 => 倍率を掛けるイベントの日とモール
     */
    public function replay(array $items, array $channelRanges, array $origins, CarbonImmutable $today): array
    {
        $origins = collect($origins)->unique(fn (CarbonImmutable $origin): string => $origin->toDateString())->sortBy(fn (CarbonImmutable $origin): string => $origin->toDateString())->values()->all();
        if ($items === [] || $origins === []) {
            return [];
        }
        $events = $this->eventUplifts->periods();
        $seasonal = ItemSeasonalFactor::currentFactors(array_map(fn (Item $item): string => $item->item_no, $items));
        $versions = DataVersions::current();
        $eventsKey = $events->map(fn (SalesEventPeriod $event): array => [$event->id, $event->sales_event_id, $event->starts_on->toDateString(), $event->ends_on->toDateString(), $event->salesEvent->malls->map(fn ($mall): string => $mall->value)->all()])->all();
        $measurements = null;

        $results = [];
        foreach ($channelRanges as $range) {
            $channel = $range['channel'];
            $skus = [];
            foreach ($items as $item) {
                foreach ($item->skus as $sku) {
                    if (DemandForecaster::sellsOn($sku, $channel)) {
                        $skus[] = [$item, $sku];
                    }
                }
            }
            $skuKey = array_map(fn (array $entry): array => [$entry[0]->item_no, $entry[1]->id, $seasonal[$entry[0]->item_no] ?? null], $skus);

            $keys = [];
            foreach ($origins as $origin) {
                $originMonth = $origin->format('Y-m');
                $keys[$origin->toDateString()] = 'forecast-replay:'.md5((string) json_encode([
                    self::CACHE_VERSION,
                    // 倍率の候補の控え方を変えたら予測し直す
                    EventUpliftEstimator::SHRINK_PERIODS,
                    $channel->value,
                    $origin->toDateString(),
                    $range['from']?->toDateString(),
                    $range['to']?->min($origin->subDay())->toDateString(),
                    $skuKey,
                    $eventsKey,
                    // 起点の前日までのデータだけを使う
                    $versions->salesBetween('0000-00', $origin->subMonthNoOverflow()->format('Y-m')),
                    $versions->inventoriesUntil($originMonth),
                ]));
            }
            $cached = Cache::many(array_values($keys));
            $missing = array_values(array_filter($origins, fn (CarbonImmutable $origin): bool => ! is_array($cached[$keys[$origin->toDateString()]] ?? null)));
            foreach ($origins as $origin) {
                $values = $cached[$keys[$origin->toDateString()]] ?? null;
                if (is_array($values)) {
                    $results[$origin->toDateString()][$channel->value] = $values;
                }
            }
            if ($missing === [] || $skus === []) {
                foreach ($missing as $origin) {
                    $results[$origin->toDateString()][$channel->value] = ['skus' => [], 'uplifts' => []];
                }

                continue;
            }

            // 起点ごとの候補は、起点より前に終わったイベントの期間を測った結果から求める（測った結果はキャッシュから使う）
            $measurements ??= $this->eventUplifts->measure($this->forecaster, $this->eventUplifts->excludedDates($events), $events->filter(fn (SalesEventPeriod $event): bool => $event->hasEndedBefore($today)), collect($items), $channelRanges, $seasonal);
            $excludedDates = EventUpliftEstimator::datesOn($this->eventUplifts->excludedDates($events), $channel);

            // 平均日販の期間（最初の起点の180日前から）の販売実績・欠品を、1回で読む
            $windowDays = array_sum(array_keys(DemandForecaster::WINDOW_WEIGHTS));
            $first = $missing[0];
            $last = $missing[count($missing) - 1];
            $skuIds = array_map(fn (array $entry): int => $entry[1]->id, $skus);
            $sales = [];
            $stockouts = [];
            if ($range['from'] !== null && $range['from']->lt($last)) {
                $loadFrom = $first->subDays($windowDays)->max($range['from']);
                $loadTo = $last->subDay()->min($range['to']);
                $sales = $this->forecaster->channelDailySales($skuIds, $channel, $loadFrom, $loadTo);
                $stockouts = $this->forecaster->channelStockoutDates($skuIds, $channel, $loadFrom, $loadTo);
            }

            $computed = [];
            foreach ($missing as $origin) {
                $knownTo = $range['to']?->min($origin->subDay());
                $plan = $range['from'] !== null && $knownTo !== null && $range['from']->lte($knownTo) ? $this->forecaster->windowPlan($range['from'], $knownTo, $origin) : null;
                $uplifts = $plan === null ? [] : $this->eventUplifts->candidateUplifts($channel, $origin, $events, $measurements, $items);
                $values = ['skus' => [], 'uplifts' => []];
                foreach ($skus as [$item, $sku]) {
                    if ($plan === null) {
                        $values['skus'][$sku->id] = ['base' => null, 'bases' => [], 'monthly' => null];

                        continue;
                    }
                    $forecast = $this->forecaster->forecastChannelWith($channel, $plan, $sales[$sku->id] ?? [], $stockouts[$sku->id] ?? [], $origin, $excludedDates, $uplifts[$item->item_no] ?? [], $seasonal[$item->item_no] ?? null);
                    $values['skus'][$sku->id] = $forecast->isPredictable()
                        ? ['base' => $forecast->baseAverage, 'bases' => $forecast->mallBases(), 'monthly' => array_map(fn (MonthlyDemand $month): float => $month->quantity, array_slice($forecast->monthly ?? [], 0, self::MONTHS))]
                        : ['base' => null, 'bases' => [], 'monthly' => null];
                }
                foreach ($uplifts as $itemNo => $itemUplifts) {
                    foreach ($itemUplifts as $uplift) {
                        if ($uplift->multiplier !== null) {
                            $values['uplifts'][$itemNo][] = $uplift->toArray();
                        }
                    }
                }
                $computed[$keys[$origin->toDateString()]] = $values;
                $results[$origin->toDateString()][$channel->value] = $values;
            }
            Cache::putMany($computed, now()->addDays(self::CACHE_DAYS));
        }

        return $results;
    }
}
