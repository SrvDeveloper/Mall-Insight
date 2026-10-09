<?php

namespace App\Services\SalesTarget\Excel;

/**
 * 現行Excelの主シートのSKU（カラー・度数）1行。
 */
final readonly class ExcelSkuTarget
{
    /**
     * @param  string|null  $power  度数（「+1.00」など）。度数の無い品番は null
     * @param  bool  $isFormula  年間の数（H列）が数式か。数式でなければ手で入れた値
     */
    public function __construct(
        public int $row,
        public string $color,
        public ?string $power,
        public float $annual,
        public bool $isFormula,
    ) {}
}
