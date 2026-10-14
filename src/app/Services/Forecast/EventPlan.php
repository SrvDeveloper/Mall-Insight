<?php

namespace App\Services\Forecast;

use App\Enums\Channel;

/**
 * ある日時点の予測に使うイベント（B-129、K-112）。モールごとの平均日販から除く日（B-134、K-126）と、品番・チャネルごとの、これからのイベントの倍率。
 */
final readonly class EventPlan
{
    /**
     * @param  array<string, array<string, true>>  $excludedDates  モールの値 => [Y-m-d => true]
     * @param  array<string, array<string, list<EventUplift>>>  $uplifts  チャネルの値 => 品番 => これからのイベント
     */
    public function __construct(
        public array $excludedDates = [],
        public array $uplifts = [],
    ) {}

    /**
     * チャネルのモールごとの、平均日販から除く日。
     *
     * @return array<string, array<string, true>> モールの値 => [Y-m-d => true]
     */
    public function excludedDatesOn(Channel $channel): array
    {
        return EventUpliftEstimator::datesOn($this->excludedDates, $channel);
    }

    /**
     * @return list<EventUplift>
     */
    public function upliftsFor(string $channel, string $itemNo): array
    {
        return $this->uplifts[$channel][$itemNo] ?? [];
    }
}
