<?php

namespace App\Services\InventoryTrend;

/**
 * 在庫推移の需要に何を使うか（バックログ B-122、決定記録 K-048・K-049③）。需要予測と販売目標は混ぜない（原則1）。
 */
enum DemandBasis: string
{
    /** システム需要予測（B-006） */
    case Forecast = 'forecast';
    /** 年間販売目標どおりに売れた場合（販売試算、B-110） */
    case SalesTarget = 'sales_target';

    public function label(): string
    {
        return match ($this) {
            self::Forecast => '需要予測',
            self::SalesTarget => '販売試算',
        };
    }
}
