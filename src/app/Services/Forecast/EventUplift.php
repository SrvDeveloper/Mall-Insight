<?php

namespace App\Services\Forecast;

use App\Enums\Mall;
use App\Models\SalesEventPeriod;

/**
 * 品番1つ・チャネル1つの、これからのイベント1つの倍率（B-129、K-112）。担当者が入れた倍率があればそれを、無ければシステムの候補を使う。
 * 候補を出せない（同じイベントの過去の期間が無い）ときは multiplier が null で、倍率を掛けない（イベントの日を普段の日として予測する）。
 * 倍率は、イベントのモールのうちそのチャネルのもの（malls）の平均日販にだけ掛ける（B-134、K-126）。
 */
final readonly class EventUplift
{
    /**
     * @param  list<string>  $dates  倍率を掛ける日（Y-m-d、今日以降）
     * @param  list<Mall>  $malls  倍率を掛けるモール
     */
    public function __construct(
        public SalesEventPeriod $event,
        public ?float $multiplier,
        public bool $isCandidate,
        public array $dates,
        public array $malls,
    ) {}

    /**
     * 日ごとの倍率の計算（DemandForecaster::eventMultipliers()）に渡す形。
     *
     * @return array{dates: list<string>, multiplier: float|null, malls: list<string>}
     */
    public function toArray(): array
    {
        return ['dates' => $this->dates, 'multiplier' => $this->multiplier, 'malls' => array_map(fn (Mall $mall): string => $mall->value, $this->malls)];
    }
}
