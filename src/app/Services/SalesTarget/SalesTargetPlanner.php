<?php

namespace App\Services\SalesTarget;

use App\Enums\ActiveStatus;
use App\Enums\RatioCategoryCode;
use App\Models\Item;
use App\Models\ItemRatioCategory;
use App\Models\ItemSelection;
use App\Models\MonthlySalesRatio;
use App\Models\RatioCategory;
use App\Models\SalesTarget;
use App\Models\Sku;
use App\Services\Pricing\UnitPriceCatalog;
use Carbon\CarbonImmutable;

/**
 * 年間販売目標を月・SKUへ割り振る（バックログ B-110、決定記録 K-049①・K-106～K-108）。結果は保存せず、開くたびに計算する。
 *
 * - 品番の月間販売目標＝年間販売目標×月別販売比率。手で直した月はその値を使う。
 * - SKUの年間の数は、手で上書きした数があればそれを、無ければ品番の年間販売目標を直近12か月の販売数の構成比で割り振る。
 *   上書きしたSKUがあると、SKUの合計は品番の年間販売目標と違ってよい（SKUの値を正とする、K-107）。廃番のSKUは除く（K-053）。
 * - 品番の区分は、登録があればそれを、無ければ CrossWalker のカテゴリーから引く（K-058）。
 * - SKUの月別の目標＝品番の月間販売目標×（SKUの年間の数÷品番の年間販売目標）。月を直していなければ、SKUの年間の数×月別販売比率と同じ。
 * - 売上金額の概算＝SKUの目標×単価（税込）。単価は単価の画面で登録した品番の全体の単価、無ければSKUの直近12か月の平均単価、
 *   そのSKUに販売実績が無ければ品番全体の平均単価を使う。どれも無いSKUは金額の合計に含めず、そのことを示す（K-062・K-063）。
 */
class SalesTargetPlanner
{
    public const MONTHS = 12;

    /**
     * 年度（4月始まり）の各月の初日。
     *
     * @return list<CarbonImmutable>
     */
    public static function monthsOf(int $fiscalYear): array
    {
        $april = CarbonImmutable::create($fiscalYear, 4, 1);

        return array_map(fn (int $index): CarbonImmutable => $april->addMonthsNoOverflow($index), range(0, self::MONTHS - 1));
    }

    public function plan(int $fiscalYear, CarbonImmutable $today): SalesTargetPlan
    {
        $to = $today->startOfDay();
        $from = $to->subYear()->addDay();
        $ratios = MonthlySalesRatio::currentByCategory();
        $targets = SalesTarget::currentFor($fiscalYear);

        $selectedItemNos = ItemSelection::current()?->items->pluck('item_no')->all() ?? [];
        $otherItemNos = $targets->keys()->diff($selectedItemNos)->sort()->values()->all();
        $itemNos = [...$selectedItemNos, ...$otherItemNos];

        $items = Item::query()->with('skus')->whereIn('item_no', $itemNos)->get()->keyBy('item_no');
        $prices = UnitPriceCatalog::build($items->values(), $today);
        $ratioCategories = RatioCategory::ordered()->keyBy('id');
        $categoriesByCode = $ratioCategories->filter(fn (RatioCategory $category): bool => $category->code !== null)->keyBy(fn (RatioCategory $category): string => $category->code->value);
        $settings = ItemRatioCategory::query()->whereIn('item_no', $itemNos)->get()->keyBy('item_no');

        $plans = [];
        foreach ($itemNos as $itemNo) {
            $item = $items->get($itemNo);
            $setCategory = $ratioCategories->get($settings->get($itemNo)?->ratio_category_id);
            $code = RatioCategoryCode::fromCrossWalkerCategory($item?->category);
            $category = $setCategory ?? ($code === null ? null : $categoriesByCode->get($code->value));
            $plans[] = $this->planItem(
                $itemNo,
                $item,
                in_array($itemNo, $selectedItemNos, true),
                $category,
                $setCategory !== null,
                $targets->get($itemNo),
                $category === null ? null : ($ratios[$category->id] ?? null),
                $prices,
            );
        }

        return new SalesTargetPlan($fiscalYear, self::monthsOf($fiscalYear), $ratios, $from, $to, $plans);
    }

    /**
     * 品番の単価と直近12か月の販売実績は $prices から引く。構成比と平均単価は、廃番を除くSKUで出す。
     */
    private function planItem(string $itemNo, ?Item $item, bool $isTargetItem, ?RatioCategory $category, bool $isCategorySet, ?SalesTarget $target, ?MonthlySalesRatio $ratio, UnitPriceCatalog $prices): ItemTargetPlan
    {
        $skus = $item?->skus->filter(fn (Sku $sku): bool => $sku->status !== ActiveStatus::Inactive)->values()->all() ?? [];
        $noOverride = array_fill(0, self::MONTHS, false);
        $itemQuantity = array_sum(array_map(fn (Sku $sku): float => $prices->skuTotal($sku->id)->quantity, $skus));
        $actualUnitPrice = $prices->itemTotal($itemNo)->averagePrice();
        $registeredUnitPrice = $prices->registered($itemNo)?->unit_price;

        if ($target === null) {
            $skuPlans = array_map(fn (Sku $sku): SkuTargetPlan => new SkuTargetPlan($sku, $prices->skuTotal($sku->id)->quantity, null, null, null, null), $skus);

            return new ItemTargetPlan($itemNo, $item, $isTargetItem, $category, $isCategorySet, null, null, $noOverride, $skuPlans, null, TargetPlanStatus::NoTarget, $registeredUnitPrice, $actualUnitPrice);
        }

        $annual = $target->annual_quantity;
        $overrides = $target->monthlyOverrides();
        $itemMonthly = null;
        if ($ratio !== null) {
            $itemMonthly = array_map(fn (int $index): float => $overrides[$index] ?? $annual * $ratio->ratios[$index] / MonthlySalesRatio::TOTAL, range(0, self::MONTHS - 1));
        }
        $monthOverridden = array_map(fn (?int $value): bool => $value !== null, $overrides);

        $skuOverrides = $target->skuOverrides();
        $totalSales = $itemQuantity;
        $skuPlans = [];
        $isComposable = true;
        foreach ($skus as $sku) {
            $salesQuantity = $prices->skuTotal($sku->id)->quantity;
            $default = $totalSales > 0 ? $annual * $salesQuantity / $totalSales : null;
            $override = $skuOverrides[$sku->id] ?? null;
            $skuAnnual = $override ?? $default;
            $isComposable = $isComposable && $skuAnnual !== null;
            $monthly = $skuAnnual === null || $itemMonthly === null || $annual === 0
                ? null
                : array_map(fn (float $quantity): float => $quantity * $skuAnnual / $annual, $itemMonthly);
            [$unitPrice, $basis] = $prices->overallPrice($sku, $itemNo);
            $skuPlans[] = new SkuTargetPlan(
                $sku,
                $salesQuantity,
                $default,
                $override,
                $skuAnnual,
                $monthly,
                $unitPrice,
                $basis,
                $unitPrice === null || $skuAnnual === null ? null : $skuAnnual * $unitPrice,
                $unitPrice === null || $monthly === null ? null : array_map(fn (float $quantity): float => $quantity * $unitPrice, $monthly),
            );
        }
        $skuTotal = $isComposable ? array_sum(array_map(fn (SkuTargetPlan $plan): float => $plan->annual, $skuPlans)) : null;
        [$annualAmount, $monthlyAmounts, $hasUnpricedSku] = $this->amounts($skuPlans);

        $status = match (true) {
            $category === null => TargetPlanStatus::NoCategory,
            $ratio === null => TargetPlanStatus::NoRatio,
            ! $isComposable => TargetPlanStatus::NoComposition,
            default => TargetPlanStatus::Calculated,
        };

        return new ItemTargetPlan($itemNo, $item, $isTargetItem, $category, $isCategorySet, $target, $itemMonthly, $monthOverridden, $skuPlans, $skuTotal, $status, $registeredUnitPrice, $actualUnitPrice, $annualAmount, $monthlyAmounts, $hasUnpricedSku);
    }

    /**
     * 品番の売上金額の概算（SKUの金額の合計）。単価の無いSKUは含めない。
     *
     * @param  list<SkuTargetPlan>  $skuPlans
     * @return array{0: float|null, 1: list<float>|null, 2: bool} [年間, 月別, 単価の無いSKUがあるか]
     */
    private function amounts(array $skuPlans): array
    {
        $priced = array_filter($skuPlans, fn (SkuTargetPlan $plan): bool => $plan->annualAmount !== null);
        $hasUnpriced = count(array_filter($skuPlans, fn (SkuTargetPlan $plan): bool => $plan->annual !== null && $plan->unitPrice === null)) > 0;
        if ($priced === []) {
            return [null, null, $hasUnpriced];
        }
        $annual = array_sum(array_map(fn (SkuTargetPlan $plan): float => $plan->annualAmount, $priced));
        $withMonthly = array_filter($priced, fn (SkuTargetPlan $plan): bool => $plan->monthlyAmounts !== null);
        $monthly = $withMonthly === [] ? null : array_map(
            fn (int $index): float => array_sum(array_map(fn (SkuTargetPlan $plan): float => $plan->monthlyAmounts[$index], $withMonthly)),
            range(0, self::MONTHS - 1),
        );

        return [$annual, $monthly, $hasUnpriced];
    }
}
