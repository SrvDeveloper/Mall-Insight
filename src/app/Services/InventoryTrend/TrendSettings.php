<?php

namespace App\Services\InventoryTrend;

use App\Models\InventoryTrendSetting;
use Carbon\CarbonImmutable;

/**
 * 在庫推移の判定の基準（決定記録 K-005・K-027・K-045・K-046・K-050）。画面で変更した最新の値を使い、変更していなければ初期値を使う。
 * 推奨発注数・過剰在庫の判定はしないため、調達リードタイム・安全在庫日数・過剰在庫の基準は持たない（K-045・K-046）。
 */
final readonly class TrendSettings
{
    public function __construct(
        /** 欠品を判定する月（今月から何か月後か） */
        public int $checkMonthOffset = InventoryTrendSetting::DEFAULT_CHECK_MONTH_OFFSET,
        /** 基準を変更した日時。初期値のままなら null */
        public ?CarbonImmutable $changedAt = null,
    ) {}

    public static function current(): self
    {
        $setting = InventoryTrendSetting::current();
        if ($setting === null) {
            return new self;
        }

        return new self($setting->check_month_offset, CarbonImmutable::instance($setting->created_at));
    }

    /**
     * APIで返す形。
     *
     * @return array{check_month_offset: int, changed_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'check_month_offset' => $this->checkMonthOffset,
            'changed_at' => $this->changedAt?->toIso8601String(),
        ];
    }
}
