<?php

namespace App\Services\InventoryTrend;

/**
 * 在庫推移の判定の基準（決定記録 K-005・K-027・K-045・K-046）。初期値は破棄前の実装と同じ。画面から変える仕組みは B-011 で作る。
 * 推奨発注数・過剰在庫の判定はしないため、調達リードタイム・安全在庫日数・過剰在庫の基準は持たない（K-045・K-046）。
 */
final readonly class TrendSettings
{
    public function __construct(
        /** 欠品を判定する月（今月から何か月後か） */
        public int $checkMonthOffset = 6,
    ) {}

    public static function current(): self
    {
        return new self;
    }
}
