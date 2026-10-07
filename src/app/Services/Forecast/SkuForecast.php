<?php

namespace App\Services\Forecast;

use App\Models\Sku;

/**
 * SKU1件の需要予測。予測不能のSKUは、基準平均日販と月別の予測が null で、理由を持つ。
 */
final readonly class SkuForecast
{
    /**
     * @param  list<AverageWindow>  $windows
     * @param  list<MonthlyDemand>|null  $monthly
     */
    public function __construct(
        public Sku $sku,
        public array $windows,
        public ?float $baseAverage,
        public ?array $monthly,
        public ?UnpredictableReason $unpredictableReason = null,
    ) {}

    public function isPredictable(): bool
    {
        return $this->baseAverage !== null;
    }
}
