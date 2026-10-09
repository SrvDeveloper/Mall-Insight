<?php

namespace App\Services\SalesTarget\Excel;

use App\Enums\RatioCategoryCode;

/**
 * 現行Excelの主シートの品番1件。
 */
final readonly class ExcelItemTarget
{
    /**
     * @param  float|null  $annual  年間販売想定数（H列）。空欄なら null
     * @param  RatioCategoryCode|null  $category  SKUの月別必要数の数式が参照している比率の区分
     * @param  list<ExcelSkuTarget>  $skus
     * @param  int|null  $unitPrice  年間売上想定額÷年間販売想定数（SKUの行で最も多い値）。品番の単価の初期値に使う（K-062）
     */
    public function __construct(
        public string $itemNo,
        public ?float $annual,
        public ?RatioCategoryCode $category,
        public array $skus,
        public ?int $unitPrice = null,
    ) {}
}
