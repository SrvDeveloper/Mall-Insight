<?php

namespace App\Services\InventoryTrend;

use App\Models\InboundPlan;
use App\Models\Inventory;
use App\Models\Item;
use App\Services\Forecast\DemandForecaster;
use App\Services\Forecast\SkuForecast;
use Carbon\CarbonImmutable;

/**
 * 12か月在庫推移の計算（バックログ B-008）。結果は保存せず、開くたびに最新の在庫・需要予測・入荷予定から計算する（原則5）。
 *
 * - 月初在庫は、最新の在庫基準日の6区分の合計（K-028）。以降は前月の月末在庫。
 * - 需要は需要予測（チャネルの合計、K-038）。今月は今日から月末までの日数分。
 * - 入荷は入荷済みでない入荷予定（K-043）。未割り振りの分は、その品番のSKUの基準平均日販の比率で仮に割り振る。
 *   入荷予定月を過ぎた入荷予定は、今月の入荷として数える。
 * - 月末在庫＝MAX(0, 月初在庫＋入荷－需要)、未充足需要＝MAX(0, 需要－月初在庫－入荷)（K-026）。
 * - 判定する月（初期値は今月から6か月後。画面から変えられる、K-050）に未充足需要（整数に丸めて1個以上）があれば欠品警告（K-005）。
 * - 推奨発注数（K-045）・過剰在庫の判定（K-046）はしない。
 */
class InventoryTrendCalculator
{
    public function __construct(private DemandForecaster $forecaster) {}

    public function calculate(CarbonImmutable $now, ?TrendSettings $settings = null): InventoryTrend
    {
        $settings ??= TrendSettings::current();
        $forecast = $this->forecaster->forecast($now);
        $currentMonth = $forecast->forecastFrom->startOfMonth();

        $skus = collect($forecast->items)->flatMap(fn (array $entry) => $entry['skus']);
        $skuIds = $skus->map(fn (SkuForecast $sku): int => $sku->sku->id)->all();
        $stockDate = $this->latestStockDate();
        $stocks = $stockDate === null ? [] : $this->stocks($skuIds, $stockDate);
        [$inbound, $provisional, $unassignable, $overdueCount] = $this->inbound($forecast->items, $currentMonth);

        return new InventoryTrend(
            calculatedAt: $now,
            stockDate: $stockDate,
            forecast: $forecast,
            settings: $settings,
            items: array_map(fn (array $entry): array => [
                'item' => $entry['item'],
                'skus' => array_map(fn (SkuForecast $sku): SkuTrend => $this->trend($sku, $stocks[$sku->sku->id] ?? null, $inbound[$sku->sku->id] ?? [], $provisional[$sku->sku->id] ?? [], $settings), $entry['skus']),
                'unassignableInbound' => $unassignable[$entry['item']->item_no] ?? 0,
            ], $forecast->items),
            overdueInboundCount: $overdueCount,
        );
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
     * SKUごと・月ごとの入荷（割り振り済み）と仮の入荷（未割り振りを需要予測の比率で割り振った分）。
     *
     * @param  list<array{item: Item, skus: list<SkuForecast>}>  $items
     * @return array{array<int, array<int, float>>, array<int, array<int, float>>, array<string, int>, int} [入荷, 仮の入荷, 品番ごとの割り振れなかった数, 予定月を過ぎた件数]
     */
    private function inbound(array $items, CarbonImmutable $currentMonth): array
    {
        $skusByItem = [];
        foreach ($items as $entry) {
            $skusByItem[$entry['item']->item_no] = $entry['skus'];
        }
        $plans = InboundPlan::query()
            ->with('allocations')
            ->whereNull('received_at')
            ->whereIn('item_no', array_keys($skusByItem))
            ->where('arrival_month', '<', $currentMonth->addMonthsNoOverflow(DemandForecaster::MONTHS)->toDateString())
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
            foreach ($skusByItem[$plan->item_no] as $sku) {
                if ($sku->baseAverage !== null && $sku->baseAverage > 0) {
                    $shares[$sku->sku->id] = $sku->baseAverage;
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
     */
    private function trend(SkuForecast $forecast, ?array $stock, array $inbound, array $provisional, TrendSettings $settings): SkuTrend
    {
        if ($stock === null) {
            return new SkuTrend($forecast->sku, $forecast, TrendStatus::NoStock, null);
        }
        if ($forecast->monthly === null || $forecast->baseAverage === null) {
            return new SkuTrend($forecast->sku, $forecast, TrendStatus::Unpredictable, $stock);
        }

        $openingStock = (float) array_sum($stock);
        $months = [];
        $level = $openingStock;
        foreach ($forecast->monthly as $index => $month) {
            $arriving = ($inbound[$index] ?? 0) + ($provisional[$index] ?? 0);
            $ending = max(0.0, $level + $arriving - $month->quantity);
            $shortfall = max(0.0, $month->quantity - $level - $arriving);
            $months[] = new MonthTrend($month->month, $month->days, $level, $month->quantity, $inbound[$index] ?? 0.0, $provisional[$index] ?? 0.0, $ending, $shortfall);
            $level = $ending;
        }
        $checkMonth = $months[min($settings->checkMonthOffset, count($months) - 1)];
        $warning = $this->isShort($checkMonth) ? TrendWarning::Shortage : TrendWarning::None;
        $firstShortage = null;
        foreach ($months as $index => $month) {
            if ($this->isShort($month)) {
                $firstShortage = $index;
                break;
            }
        }

        return new SkuTrend($forecast->sku, $forecast, TrendStatus::Calculated, $stock, $months, $warning, $firstShortage);
    }

    /**
     * 足りない数が、整数に丸めて1個以上か（K-044）。1個未満の不足は画面で0と表示されるため、欠品とはみなさない。
     */
    private function isShort(MonthTrend $month): bool
    {
        return round($month->shortfall) >= 1;
    }
}
