<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * 1か月分の需要予測。今月も1か月すべての日数分（K-052）。数量は丸めずに持つ。
 * 数量は「基準平均日販×日数」に、イベント（B-129）の日に倍率で増やした分を足したもの。増やした分の内訳を events に持つ（原則4）。
 * イベントの倍率は今日以降の日にだけ掛ける（K-112）。
 */
final readonly class MonthlyDemand
{
    /**
     * @param  list<MonthlyEventDemand>  $events
     */
    public function __construct(
        public CarbonImmutable $month,
        public int $days,
        public float $quantity,
        public array $events = [],
    ) {}

    /**
     * イベントで増やした数の合計。
     */
    public function eventQuantity(): float
    {
        return array_sum(array_map(fn (MonthlyEventDemand $event): float => $event->quantity, $this->events));
    }

    /**
     * 今月のうち、今日（$remainingDays は今日から月末までの日数）からの需要。基準平均日販の分は日数で按分し、
     * イベントで増やした分は今日以降の日だけに掛けているため、そのまま足す。
     */
    public function remainingQuantity(int $remainingDays): float
    {
        $eventQuantity = $this->eventQuantity();

        return ($this->quantity - $eventQuantity) * $remainingDays / $this->days + $eventQuantity;
    }
}
