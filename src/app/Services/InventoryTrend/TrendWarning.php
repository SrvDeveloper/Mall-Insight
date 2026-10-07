<?php

namespace App\Services\InventoryTrend;

/**
 * 判定する月（K-005）の在庫の状態。
 */
enum TrendWarning: string
{
    case None = 'none';
    /** 判定する月に未充足需要がある */
    case Shortage = 'shortage';

    public function label(): string
    {
        return match ($this) {
            self::None => '警告なし',
            self::Shortage => '欠品警告',
        };
    }
}
