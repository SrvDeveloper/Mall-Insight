<?php

namespace App\Services\InventoryTrend;

use App\Models\InboundPlan;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Services\Forecast\DemandForecast;
use App\Services\Forecast\DemandForecaster;
use App\Services\Forecast\SkuForecast;
use App\Services\SalesTarget\ItemTargetPlan;
use App\Services\SalesTarget\SalesTargetPlanner;
use App\Services\SalesTarget\TargetPlanStatus;
use Carbon\CarbonImmutable;

/**
 * 12か月在庫推移の計算（バックログ B-008・B-122）。結果は保存せず、開くたびに最新の在庫・需要・入荷予定から計算する（原則5）。
 *
 * - 需要は、需要予測（チャネルの合計、K-038）か、販売試算（年間販売目標のSKUの月別の目標、B-110）のどちらか（K-048・K-049③）。
 *   どちらでも、以下の規則は同じ。
 * - 月初在庫は、最新の在庫基準日の6区分の合計（K-028）。以降は前月の月末在庫。
 * - 需要の今月は1か月分のため、今日から月末までの日数分に按分する（K-044・K-052）。
 * - 入荷は入荷済みでない入荷予定（K-043）。未割り振りの分は、その月のSKUの需要の比率で仮に割り振る（需要予測は基準平均日販、
 *   販売試算はその月のSKUの目標、K-110）。入荷予定月を過ぎた入荷予定は、今月の入荷として数える。
 * - 月末在庫＝MAX(0, 月初在庫＋入荷－需要)、未充足需要＝MAX(0, 需要－月初在庫－入荷)（K-026）。
 * - 判定する月（初期値は今月から6か月後。画面から変えられる、K-050）に未充足需要（整数に丸めて1個以上）があれば欠品警告（K-005）。
 * - 販売試算で、販売目標を割り振れない月（翌年度の目標が未登録など）は0とせず、その月から後を計算できないとする（原則2、K-109）。
 *   判定する月を計算できなければ、欠品警告ではなく「判定できない」とする。
 * - 推奨発注数（K-045）・過剰在庫の判定（K-046）はしない。
 */
class InventoryTrendCalculator
{
    public const MONTHS = DemandForecaster::MONTHS;

    public function __construct(private DemandForecaster $forecaster, private SalesTargetPlanner $planner) {}

    public function calculate(CarbonImmutable $now, ?TrendSettings $settings = null, DemandBasis $basis = DemandBasis::Forecast): InventoryTrend
    {
        $settings ??= TrendSettings::current();
        $forecast = $this->forecaster->forecast($now);
        $currentMonth = $forecast->forecastFrom->startOfMonth();
        $months = array_map(function (int $index) use ($currentMonth): array {
            $month = $currentMonth->addMonthsNoOverflow($index);

            return ['month' => $month, 'days' => $month->daysInMonth];
        }, range(0, self::MONTHS - 1));

        [$demands, $limits, $salesTargets] = $basis === DemandBasis::Forecast
            ? [$this->forecastDemands($forecast), [], null]
            : $this->targetDemands($forecast, $months, $now);

        $skus = collect($forecast->items)->flatMap(fn (array $entry) => $entry['skus']);
        $skuIds = $skus->map(fn (SkuForecast $sku): int => $sku->sku->id)->all();
        $stockDate = $this->latestStockDate();
        $stocks = $stockDate === null ? [] : $this->stocks($skuIds, $stockDate);
        [$inbound, $provisional, $unassignable, $overdueCount] = $this->inbound($forecast->items, $demands, $limits, $currentMonth);
        // 今月は今日（予測の開始日）から月末までの日数分だけ数える
        $remainingDays = $currentMonth->daysInMonth - $forecast->forecastFrom->day + 1;

        return new InventoryTrend(
            calculatedAt: $now,
            basis: $basis,
            stockDate: $stockDate,
            forecast: $forecast,
            settings: $settings,
            months: $months,
            items: array_map(fn (array $entry): array => [
                'item' => $entry['item'],
                'skus' => array_map(fn (SkuForecast $sku): SkuTrend => $this->trend(
                    $sku,
                    $basis,
                    $demands[$sku->sku->id],
                    $stocks[$sku->sku->id] ?? null,
                    $inbound[$sku->sku->id] ?? [],
                    $provisional[$sku->sku->id] ?? [],
                    $months,
                    $settings,
                    $remainingDays,
                ), $entry['skus']),
                'unassignableInbound' => $unassignable[$entry['item']->item_no] ?? 0,
            ], $forecast->items),
            overdueInboundCount: $overdueCount,
            salesTargets: $salesTargets,
        );
    }

    /**
     * 需要予測の需要。仮の割り振りの比率は、どの月も基準平均日販。
     *
     * @return array<int, SkuDemand>
     */
    private function forecastDemands(DemandForecast $forecast): array
    {
        $demands = [];
        foreach ($forecast->items as $entry) {
            foreach ($entry['skus'] as $sku) {
                $demands[$sku->sku->id] = $sku->monthly === null || $sku->baseAverage === null
                    ? new SkuDemand([], [], null)
                    : new SkuDemand(
                        array_map(fn ($month): float => $month->quantity, $sku->monthly),
                        array_fill(0, self::MONTHS, $sku->baseAverage),
                        $sku->baseAverage,
                    );
            }
        }

        return $demands;
    }

    /**
     * 販売試算の需要（SKUの月別の目標）。推移の12か月が入る年度（今年度と翌年度）の販売目標を割り振り、
     * 品番の目標を割り振れない月に来たら、その月から後は計算できないとする（K-109）。仮の割り振りの比率は、その月のSKUの目標（K-110）。
     *
     * @param  list<array{month: CarbonImmutable, days: int}>  $months
     * @return array{0: array<int, SkuDemand>, 1: array<string, int>, 2: list<array{fiscalYear: int, from: CarbonImmutable, to: CarbonImmutable, calculatedItemCount: int}>} [SKUごとの需要, 品番ごとの計算できる月数, 年度ごとの割り振れた品番の数]
     */
    private function targetDemands(DemandForecast $forecast, array $months, CarbonImmutable $now): array
    {
        $plansByYear = [];
        foreach ($months as $month) {
            $fiscalYear = ItemSelection::fiscalYearStart($month['month'])->year;
            $plansByYear[$fiscalYear] ??= collect($this->planner->plan($fiscalYear, $now)->items)->keyBy(fn (ItemTargetPlan $plan): string => $plan->itemNo);
        }

        $demands = [];
        $limits = [];
        foreach ($forecast->items as $entry) {
            $itemNo = $entry['item']->item_no;
            $monthly = [];
            $limit = 0;
            $reason = null;
            foreach ($months as $index => $month) {
                $start = ItemSelection::fiscalYearStart($month['month']);
                $plan = $plansByYear[$start->year]->get($itemNo);
                if ($plan === null || $plan->status !== TargetPlanStatus::Calculated) {
                    $reason = "{$start->year}年度：".($plan?->status ?? TargetPlanStatus::NoTarget)->label();
                    break;
                }
                $monthIndex = (int) $start->diffInMonths($month['month']);
                foreach ($plan->skus as $skuPlan) {
                    // 割り振れた品番で月別の目標が無いのは、年間販売目標が0のときだけ
                    $monthly[$skuPlan->sku->id][$index] = $skuPlan->monthly[$monthIndex] ?? 0.0;
                }
                $limit++;
            }
            $limits[$itemNo] = $limit;
            $days = array_sum(array_map(fn (array $month): int => $month['days'], array_slice($months, 0, $limit)));
            foreach ($entry['skus'] as $sku) {
                $skuMonthly = $monthly[$sku->sku->id] ?? [];
                $demands[$sku->sku->id] = new SkuDemand(
                    $skuMonthly,
                    $skuMonthly,
                    $skuMonthly === [] ? null : array_sum($skuMonthly) / $days,
                    $reason ?? ($limit > 0 && $skuMonthly === [] ? '販売目標が割り振られていません' : null),
                );
            }
        }

        $salesTargets = [];
        foreach ($plansByYear as $fiscalYear => $plans) {
            $inYear = array_values(array_filter($months, fn (array $month): bool => ItemSelection::fiscalYearStart($month['month'])->year === $fiscalYear));
            $salesTargets[] = [
                'fiscalYear' => $fiscalYear,
                'from' => $inYear[0]['month'],
                'to' => end($inYear)['month'],
                'calculatedItemCount' => count(array_filter($forecast->items, fn (array $entry): bool => $plans->get($entry['item']->item_no)?->status === TargetPlanStatus::Calculated)),
            ];
        }

        return [$demands, $limits, $salesTargets];
    }

    private function latestStockDate(): ?CarbonImmutable
    {
        $date = Inventory::query()->max('stock_date');

        return $date === null ? null : CarbonImmutable::parse($date)->startOfDay();
    }

    /**
     * 在庫基準日の、SKUごと・在庫の区分ごとの在庫数。
     *
     * @param  list<int>  $skuIds
     * @return array<int, array<string, int>>
     */
    private function stocks(array $skuIds, CarbonImmutable $stockDate): array
    {
        $stocks = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = Inventory::query()
                ->select(['sku_id', 'warehouse', 'quantity'])
                ->whereIn('sku_id', $chunk)
                ->where('stock_date', '>=', $stockDate->toDateString())
                ->where('stock_date', '<', $stockDate->addDay()->toDateString())
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $stocks[$row->sku_id][$row->warehouse] = (int) $row->quantity;
            }
        }

        return $stocks;
    }

    /**
     * SKUごと・月ごとの入荷（割り振り済み）と仮の入荷（未割り振りを需要の比率で割り振った分）。
     * 販売試算で計算できない月の入荷予定は、推移に出ないため数えない。
     *
     * @param  list<array{item: Item, skus: list<SkuForecast>}>  $items
     * @param  array<int, SkuDemand>  $demands
     * @param  array<string, int>  $limits  品番ごとの計算できる月数（販売試算のときだけ）
     * @return array{array<int, array<int, float>>, array<int, array<int, float>>, array<string, int>, int} [入荷, 仮の入荷, 品番ごとの割り振れなかった数, 予定月を過ぎた件数]
     */
    private function inbound(array $items, array $demands, array $limits, CarbonImmutable $currentMonth): array
    {
        $skusByItem = [];
        foreach ($items as $entry) {
            $skusByItem[$entry['item']->item_no] = $entry['skus'];
        }
        $plans = InboundPlan::query()
            ->with('allocations')
            ->whereNull('received_at')
            ->whereIn('item_no', array_keys($skusByItem))
            ->where('arrival_month', '<', $currentMonth->addMonthsNoOverflow(self::MONTHS)->toDateString())
            ->get();

        $inbound = [];
        $provisional = [];
        $unassignable = [];
        $overdueCount = 0;
        foreach ($plans as $plan) {
            $monthsAhead = (int) $currentMonth->diffInMonths($plan->arrival_month->startOfMonth(), false);
            if ($monthsAhead < 0) {
                $overdueCount++;
            }
            $index = max(0, $monthsAhead);
            if (isset($limits[$plan->item_no]) && $index >= $limits[$plan->item_no]) {
                continue;
            }
            $targetSkuIds = array_map(fn (SkuForecast $sku): int => $sku->sku->id, $skusByItem[$plan->item_no]);
            foreach ($plan->allocations as $allocation) {
                if (in_array($allocation->sku_id, $targetSkuIds, true)) {
                    $inbound[$allocation->sku_id][$index] = ($inbound[$allocation->sku_id][$index] ?? 0) + $allocation->quantity;
                }
            }

            $unallocated = $plan->quantity - $plan->allocatedQuantity();
            if ($unallocated <= 0) {
                continue;
            }
            $shares = [];
            foreach ($targetSkuIds as $skuId) {
                $share = $demands[$skuId]->shareAt($index);
                if ($share !== null && $share > 0) {
                    $shares[$skuId] = $share;
                }
            }
            $totalShare = array_sum($shares);
            if ($totalShare <= 0) {
                $unassignable[$plan->item_no] = ($unassignable[$plan->item_no] ?? 0) + $unallocated;

                continue;
            }
            foreach ($shares as $skuId => $share) {
                $provisional[$skuId][$index] = ($provisional[$skuId][$index] ?? 0) + $unallocated * $share / $totalShare;
            }
        }

        return [$inbound, $provisional, $unassignable, $overdueCount];
    }

    /**
     * @param  array<string, int>|null  $stock
     * @param  array<int, float>  $inbound  [月の位置 => 入荷数]
     * @param  array<int, float>  $provisional  [月の位置 => 仮の入荷数]
     * @param  list<array{month: CarbonImmutable, days: int}>  $months
     * @param  int  $remainingDays  今月の、今日から月末までの日数
     */
    private function trend(SkuForecast $forecast, DemandBasis $basis, SkuDemand $demand, ?array $stock, array $inbound, array $provisional, array $months, TrendSettings $settings, int $remainingDays): SkuTrend
    {
        $skuForecast = $basis === DemandBasis::Forecast ? $forecast : null;
        if ($stock === null) {
            return new SkuTrend($forecast->sku, $skuForecast, $demand, TrendStatus::NoStock, null);
        }
        if ($demand->monthly === []) {
            return new SkuTrend($forecast->sku, $skuForecast, $demand, $basis === DemandBasis::Forecast ? TrendStatus::Unpredictable : TrendStatus::NoSalesTarget, $stock);
        }

        $trends = [];
        $level = (float) array_sum($stock);
        foreach ($demand->monthly as $index => $quantity) {
            $days = $months[$index]['days'];
            [$days, $quantity] = $index === 0 ? [$remainingDays, $quantity * $remainingDays / $days] : [$days, $quantity];
            $arriving = ($inbound[$index] ?? 0) + ($provisional[$index] ?? 0);
            $ending = max(0.0, $level + $arriving - $quantity);
            $shortfall = max(0.0, $quantity - $level - $arriving);
            $trends[] = new MonthTrend($months[$index]['month'], $days, $level, $quantity, $inbound[$index] ?? 0.0, $provisional[$index] ?? 0.0, $ending, $shortfall);
            $level = $ending;
        }
        $checkMonth = $trends[$settings->checkMonthOffset] ?? null;
        $warning = match (true) {
            $checkMonth === null => TrendWarning::Undetermined,
            $this->isShort($checkMonth) => TrendWarning::Shortage,
            default => TrendWarning::None,
        };
        $firstShortage = null;
        foreach ($trends as $index => $month) {
            if ($this->isShort($month)) {
                $firstShortage = $index;
                break;
            }
        }

        return new SkuTrend($forecast->sku, $skuForecast, $demand, TrendStatus::Calculated, $stock, $trends, $warning, $firstShortage);
    }

    /**
     * 足りない数が、整数に丸めて1個以上か（K-044）。1個未満の不足は画面で0と表示されるため、欠品とはみなさない。
     */
    private function isShort(MonthTrend $month): bool
    {
        return round($month->shortfall) >= 1;
    }
}
