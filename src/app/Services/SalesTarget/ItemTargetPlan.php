<?php

namespace App\Services\SalesTarget;

use App\Models\Item;
use App\Models\RatioCategory;
use App\Models\SalesTarget;

/**
 * 品番1件の販売目標と、月・SKUへの割り振り（K-049①・K-107）。
 */
final readonly class ItemTargetPlan
{
    /**
     * @param  Item|null  $item  CrossWalker から品番が消えていれば null
     * @param  bool  $isCategorySet  区分を登録しているか（false なら CrossWalker のカテゴリーから引いた初期値、K-108）
     * @param  list<float>|null  $itemMonthly  4月～翌3月の品番の月間販売目標。区分の比率が無ければ null
     * @param  list<bool>  $monthOverridden  月ごとに、手で直した値か
     * @param  list<SkuTargetPlan>  $skus  廃番を除くSKU（K-053）
     * @param  float|null  $skuTotal  SKUの年間の数の合計。上書きがあると品番の年間販売目標と違う（K-107）
     * @param  int|null  $registeredUnitPrice  品番に登録した単価（K-062）
     * @param  float|null  $actualUnitPrice  品番全体の直近12か月の平均単価（税込）
     * @param  float|null  $annualAmount  売上金額の概算（SKUの年間の金額の合計）。単価の無いSKUは含めない
     * @param  list<float>|null  $monthlyAmounts  月別の売上金額の概算
     * @param  bool  $hasUnpricedSku  単価を出せず、金額の合計に含めていないSKUがあるか
     */
    public function __construct(
        public string $itemNo,
        public ?Item $item,
        public bool $isTargetItem,
        public ?RatioCategory $category,
        public bool $isCategorySet,
        public ?SalesTarget $target,
        public ?array $itemMonthly,
        public array $monthOverridden,
        public array $skus,
        public ?float $skuTotal,
        public TargetPlanStatus $status,
        public ?int $registeredUnitPrice = null,
        public ?float $actualUnitPrice = null,
        public ?float $annualAmount = null,
        public ?array $monthlyAmounts = null,
        public bool $hasUnpricedSku = false,
    ) {}
}
