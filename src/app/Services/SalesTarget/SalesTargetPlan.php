<?php

namespace App\Services\SalesTarget;

use App\Models\MonthlySalesRatio;
use Carbon\CarbonImmutable;

/**
 * 年度の販売目標の一覧と、計算に使った条件。
 */
final readonly class SalesTargetPlan
{
    /**
     * @param  list<CarbonImmutable>  $months  4月～翌3月の各月の初日
     * @param  array<int, MonthlySalesRatio>  $ratios  区分（ID）ごとの最新の月別販売比率
     * @param  list<ItemTargetPlan>  $items  対象品番（選定の順）と、対象品番でないが目標のある品番
     */
    public function __construct(
        public int $fiscalYear,
        public array $months,
        public array $ratios,
        public CarbonImmutable $compositionFrom,
        public CarbonImmutable $compositionTo,
        public array $items,
    ) {}
}
