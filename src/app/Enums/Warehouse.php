<?php

namespace App\Enums;

/**
 * 在庫の区分。ZeroStockView の日次在庫の6項目に対応する（決定記録 K-007・K-020）。
 */
enum Warehouse: string
{
    case AmazonOwn = 'amazon_own';
    case AmazonFba = 'amazon_fba';
    case BossOwn = 'boss_own';
    case BossRfc = 'boss_rfc';
    case FreeStock = 'free_stock';
    case EcStock = 'ec_stock';

    public function label(): string
    {
        return match ($this) {
            self::AmazonOwn => 'Amazon自社出荷',
            self::AmazonFba => 'Amazon FBA',
            self::BossOwn => 'BOSS自社倉庫',
            self::BossRfc => 'BOSS RFC',
            self::FreeStock => 'フリー在庫',
            self::EcStock => 'ECストック',
        };
    }

    /**
     * ZeroStockView の日次在庫APIでの項目名。
     */
    public function zeroStockViewField(): string
    {
        return match ($this) {
            self::AmazonOwn => 'amazonOwn',
            self::AmazonFba => 'amazonFba',
            self::BossOwn => 'bossOwn',
            self::BossRfc => 'bossRfc',
            self::FreeStock => 'freeStock',
            self::EcStock => 'ecStock',
        };
    }
}
