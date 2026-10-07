<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * 期間別平均日販（3つの期間のどれか1つ）。販売数・販売できた日数は欠品日を除いた数（K-037）。
 * 販売できた日数が足りない期間と、欠品日が半分以上ある期間は除外し、平均日販は null。
 * offset は、期間の末日が販売実績の最新日の何日前か（直近30日は0、その前の60日は30、さらに前の90日は90）。
 */
final readonly class AverageWindow
{
    public function __construct(
        public int $length,
        public int $offset,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
        public int $salesDays,
        public int $stockoutDays,
        public int $salesQuantity,
        public ?float $average,
        public float $baseWeight,
        public float $weight,
    ) {}

    /**
     * 「直近30日」「31〜90日前」のような期間の名前。
     */
    public function label(): string
    {
        return $this->offset === 0 ? "直近{$this->length}日" : ($this->offset + 1).'〜'.($this->offset + $this->length).'日前';
    }

    public function isExcluded(): bool
    {
        return $this->average === null;
    }
}
