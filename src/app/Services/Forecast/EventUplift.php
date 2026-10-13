<?php

namespace App\Services\Forecast;

use App\Models\SalesEventPeriod;

/**
 * 品番1つ・チャネル1つの、これからのイベント1つの倍率（B-129、K-112）。担当者が入れた倍率があればそれを、無ければシステムの候補を使う。
 * 候補を出せない（同じイベントの過去の期間が無い）ときは multiplier が null で、倍率を掛けない（イベントの日を普段の日として予測する）。
 */
final readonly class EventUplift
{
    /**
     * @param  list<string>  $dates  倍率を掛ける日（Y-m-d、今日以降）
     */
    public function __construct(
        public SalesEventPeriod $event,
        public ?float $multiplier,
        public bool $isCandidate,
        public array $dates,
    ) {}
}
