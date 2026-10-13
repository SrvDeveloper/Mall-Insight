<?php

namespace App\Services\Forecast;

/**
 * ある日時点の予測に使うイベント（B-129、K-112）。チャネルごとの平均日販から除く日と、品番・チャネルごとの、これからのイベントの倍率。
 */
final readonly class EventPlan
{
    /**
     * @param  array<string, array<string, true>>  $excludedDates  チャネルの値 => [Y-m-d => true]
     * @param  array<string, array<string, list<EventUplift>>>  $uplifts  チャネルの値 => 品番 => これからのイベント
     */
    public function __construct(
        public array $excludedDates = [],
        public array $uplifts = [],
    ) {}

    /**
     * @return array<string, true>
     */
    public function excludedDatesOn(string $channel): array
    {
        return $this->excludedDates[$channel] ?? [];
    }

    /**
     * @return list<EventUplift>
     */
    public function upliftsFor(string $channel, string $itemNo): array
    {
        return $this->uplifts[$channel][$itemNo] ?? [];
    }
}
