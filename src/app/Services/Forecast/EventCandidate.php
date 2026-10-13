<?php

namespace App\Services\Forecast;

use App\Enums\Channel;

/**
 * これからのイベント1つ・チャネル1つの、倍率の候補（B-129、K-112）。同じ名前の過去のイベント（新しい方から3回まで）の
 * 売れた数の合計÷普段なら売れた数の合計を、品番ごとと、対象品番の全体で求める。
 * 普段なら売れた数が少ない品番は倍率がぶれやすいため、全体の倍率を候補にする。過去のイベントが無ければ候補は無い（overall が null）。
 */
final readonly class EventCandidate
{
    /**
     * @param  list<EventMeasurement>  $basis  候補を求めた過去のイベント（新しい順）
     * @param  array<string, array{multiplier: float, expected: float, actual: int, uses_overall: bool}>  $items  過去のイベントで測れた品番
     */
    public function __construct(
        public Channel $channel,
        public array $basis,
        public ?float $overall,
        public float $expected,
        public int $actual,
        public array $items,
    ) {}

    /**
     * 品番の倍率の候補。過去のイベントで測れなかった品番は全体の倍率。
     */
    public function forItem(string $itemNo): ?float
    {
        return $this->items[$itemNo]['multiplier'] ?? $this->overall;
    }

    public function usesOverall(string $itemNo): bool
    {
        return $this->items[$itemNo]['uses_overall'] ?? true;
    }
}
