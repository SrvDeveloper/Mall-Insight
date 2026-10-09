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
    /** 判定する月の在庫を計算できない（販売試算で、翌年度の販売目標が無いときなど、K-109） */
    case Undetermined = 'undetermined';

    public function label(): string
    {
        return match ($this) {
            self::None => '警告なし',
            self::Shortage => '欠品警告',
            self::Undetermined => '判定できない',
        };
    }
}
