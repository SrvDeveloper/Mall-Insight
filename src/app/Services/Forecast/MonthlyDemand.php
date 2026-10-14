<?php

namespace App\Services\Forecast;

use Carbon\CarbonImmutable;

/**
 * 1か月分の需要予測。今月も1か月すべての日数分（K-052）。数量は丸めずに持つ。
 * 数量は「基準平均日販×日数」に、イベント（B-129）の日に倍率で増やした分を足したもの。増やした分の内訳を events に持つ（原則4）。
 * イベントの倍率は今日以降の日にだけ掛ける（K-112）。季節係数（B-106）を使うときは、基準平均日販にその月の係数を掛ける。
 * 手動での補正（B-109、K-132）を掛けた月は、数量を補正した後の数とし、補正で増減した数（adjustmentQuantity）と倍率を持つ。
 * SKUの合計（チャネルを足した月）は、チャネルごとの月を parts に持つ（チャネルごとに補正の倍率が違うため）。
 */
final readonly class MonthlyDemand
{
    /**
     * @param  list<MonthlyEventDemand>  $events
     * @param  list<MonthlyDemand>  $parts
     */
    public function __construct(
        public CarbonImmutable $month,
        public int $days,
        public float $quantity,
        public array $events = [],
        public ?float $seasonalFactor = null,
        public ?float $adjustmentRate = null,
        public float $adjustmentQuantity = 0.0,
        public array $parts = [],
    ) {}

    /**
     * イベントで増やした数の合計（補正を掛ける前）。
     */
    public function eventQuantity(): float
    {
        return array_sum(array_map(fn (MonthlyEventDemand $event): float => $event->quantity, $this->events));
    }

    /**
     * 補正を掛ける前の数量（システムの予測）。
     */
    public function unadjustedQuantity(): float
    {
        return $this->quantity - $this->adjustmentQuantity;
    }

    /**
     * 補正の倍率を掛けた月（倍率を1にした月も含む）。
     */
    public function withAdjustment(float $rate): self
    {
        return new self($this->month, $this->days, $this->quantity * $rate, $this->events, $this->seasonalFactor, $rate, $this->quantity * ($rate - 1));
    }

    /**
     * 今月のうち、今日（$remainingDays は今日から月末までの日数）からの需要。基準平均日販の分は日数で按分し、
     * イベントで増やした分は今日以降の日だけに掛けているため、そのまま足す。補正はその後に倍率で掛ける。
     */
    public function remainingQuantity(int $remainingDays): float
    {
        if ($this->parts !== []) {
            return array_sum(array_map(fn (MonthlyDemand $part): float => $part->remainingQuantity($remainingDays), $this->parts));
        }
        $eventQuantity = $this->eventQuantity();

        return (($this->unadjustedQuantity() - $eventQuantity) * $remainingDays / $this->days + $eventQuantity) * ($this->adjustmentRate ?? 1.0);
    }
}
