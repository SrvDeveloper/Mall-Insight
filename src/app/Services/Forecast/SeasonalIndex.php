<?php

namespace App\Services\Forecast;

/**
 * 品番1つの季節係数（B-106）。月（1〜12）ごとに、普段の月の何倍売れるか。12か月の平均は1。
 * 求められないときは factors が null（季節による増減を掛けない）。
 */
final readonly class SeasonalIndex
{
    public const SOURCE_ITEM = 'item';

    public const SOURCE_CATEGORY = 'category';

    public const SOURCE_OVERALL = 'overall';

    /**
     * @param  array<int, float>|null  $factors  月（1〜12） => 季節係数
     * @param  string|null  $source  どの単位の販売実績から求めたか（品番・カテゴリー・対象品番の全体）
     * @param  string|null  $sourceLabel  カテゴリーの名前など
     * @param  array<int, int>  $years  月（1〜12） => 使った年数
     */
    public function __construct(
        public string $itemNo,
        public ?array $factors,
        public ?string $source = null,
        public ?string $sourceLabel = null,
        public array $years = [],
        public float $monthlyQuantity = 0.0,
    ) {}

    public function factor(int $month): float
    {
        return $this->factors[$month] ?? 1.0;
    }

    public function isAvailable(): bool
    {
        return $this->factors !== null;
    }
}
