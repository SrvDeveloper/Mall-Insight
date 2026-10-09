<?php

namespace App\Services\SalesTarget;

use App\Models\Sku;

/**
 * SKU1件の販売目標。年間の数は、手で上書きした数があればそれを、無ければ品番の目標を構成比で割り振った数を使う（K-107）。
 */
final readonly class SkuTargetPlan
{
    /**
     * @param  float  $salesQuantity  構成比に使う直近12か月の販売数
     * @param  float|null  $defaultAnnual  品番の目標を構成比で割り振った数。構成比を出せなければ null
     * @param  int|null  $overrideAnnual  手で上書きした数
     * @param  float|null  $annual  使う年間の数（上書き、または構成比で割り振った数）
     * @param  list<float>|null  $monthly  4月～翌3月の月別の目標。割り振れなければ null
     * @param  float|null  $unitPrice  売上金額の概算に使う単価（税込）。登録が無く販売実績も無ければ null（K-062）
     * @param  float|null  $annualAmount  年間の数×単価
     * @param  list<float>|null  $monthlyAmounts  月別の目標×単価
     */
    public function __construct(
        public Sku $sku,
        public float $salesQuantity,
        public ?float $defaultAnnual,
        public ?int $overrideAnnual,
        public ?float $annual,
        public ?array $monthly,
        public ?float $unitPrice = null,
        public ?PriceBasis $priceBasis = null,
        public ?float $annualAmount = null,
        public ?array $monthlyAmounts = null,
    ) {}
}
