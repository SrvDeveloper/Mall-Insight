<?php

namespace App\Services\SalesTarget;

/**
 * 売上金額の概算に使った単価の出どころ（決定記録 K-062）。
 */
enum PriceBasis: string
{
    /** 品番に登録した単価 */
    case Registered = 'registered';
    /** そのSKUの直近12か月の平均単価 */
    case SkuActual = 'sku_actual';
    /** そのSKUに販売実績が無いため、品番全体の直近12か月の平均単価 */
    case ItemActual = 'item_actual';

    public function label(): string
    {
        return match ($this) {
            self::Registered => '登録した単価',
            self::SkuActual => 'SKUの平均単価',
            self::ItemActual => '品番の平均単価',
        };
    }
}
