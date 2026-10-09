<?php

namespace App\Services\InventoryTrend;

use App\Models\Sku;
use App\Services\Forecast\SkuForecast;

/**
 * SKU1件の12か月在庫推移と警告。計算できないSKUは months が null。
 * 販売試算で途中の月から計算できないときは、months は計算できた月までで、その後の月の理由は demand の unavailableLabel に持つ。
 */
final readonly class SkuTrend
{
    /**
     * @param  SkuForecast|null  $forecast  需要予測（需要予測にもとづく推移のときだけ）
     * @param  array<string, int>|null  $stockByWarehouse  在庫の区分 => 在庫数。在庫の記録が無ければ null
     * @param  list<MonthTrend>|null  $months
     */
    public function __construct(
        public Sku $sku,
        public TrendScope $scope,
        public ?SkuForecast $forecast,
        public SkuDemand $demand,
        public TrendStatus $status,
        public ?array $stockByWarehouse,
        public ?array $months = null,
        public TrendWarning $warning = TrendWarning::None,
        public ?int $firstShortageIndex = null,
    ) {}

    /**
     * 月初在庫（全体は6区分、モールはそのモールの在庫の区分の合計）。在庫の記録が無ければ null。
     */
    public function openingStock(): ?int
    {
        return $this->stockByWarehouse === null ? null : $this->scope->stockOf($this->stockByWarehouse);
    }

    /**
     * 計算できないときは、その理由。販売目標が無い、モールの需要予測を出せないなど、需要に理由があればそれを示す。
     */
    public function statusLabel(): string
    {
        $isDemandReason = in_array($this->status, [TrendStatus::Unpredictable, TrendStatus::NotSold, TrendStatus::NoSalesTarget], true);

        return $isDemandReason ? ($this->demand->unavailableLabel ?? $this->status->label()) : $this->status->label();
    }

    /**
     * 途中の月から計算できないときの理由（計算できた月の後の月に表示する）。
     */
    public function uncalculatedMonthsLabel(): ?string
    {
        return $this->months !== null && count($this->months) < InventoryTrendCalculator::MONTHS ? $this->demand->unavailableLabel : null;
    }
}
