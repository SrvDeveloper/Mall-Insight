<?php

namespace App\Enums;

/**
 * イベント（B-129）の、今日から見た状態。始まる前と開催中のイベントは倍率で需要予測を増やし、終わったイベントは倍率を測るのに使う（K-112）。
 */
enum SalesEventStatus: string
{
    case Upcoming = 'upcoming';
    case Ongoing = 'ongoing';
    case Ended = 'ended';

    public function label(): string
    {
        return match ($this) {
            self::Upcoming => 'これから',
            self::Ongoing => '開催中',
            self::Ended => '終わった',
        };
    }
}
