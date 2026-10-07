<?php

namespace App\Services\InventoryTrend;

use App\Models\Sku;
use App\Services\Forecast\SkuForecast;

/**
 * SKU1件の12か月在庫推移と警告。計算できないSKUは months が null。
 */
final readonly class SkuTrend
{
    /**
     * @param  array<string, int>|null  $stockByWarehouse  在庫の区分 => 在庫数。在庫の記録が無ければ null
     * @param  list<MonthTrend>|null  $months
     */
    public function __construct(
        public Sku $sku,
        public SkuForecast $forecast,
        public TrendStatus $status,
        public ?array $stockByWarehouse,
        public ?array $months = null,
        public TrendWarning $warning = TrendWarning::None,
        public ?int $firstShortageIndex = null,
    ) {}

    public function openingStock(): ?int
    {
        return $this->stockByWarehouse === null ? null : array_sum($this->stockByWarehouse);
    }
}
