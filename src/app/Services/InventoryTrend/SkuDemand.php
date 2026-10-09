<?php

namespace App\Services\InventoryTrend;

/**
 * SKU1件の、在庫推移に使う月ごとの需要。今月も1か月すべての日数分で持ち、今日からの日数分への按分は在庫推移で行う。
 * 計算できる月は今月から続く月だけで、ある月を計算できなければ、その月より後の在庫も計算できない（月初在庫が決まらないため）。
 */
final readonly class SkuDemand
{
    /**
     * @param  list<float>  $monthly  今月から計算できる月までの需要。1か月も計算できなければ空
     * @param  list<float|null>  $shares  月ごとの、未割り振りの入荷予定を仮に割り振る比率（K-043・K-110）。割り振らない月は null
     * @param  float|null  $averageDaily  平均日販（在庫が持つ月数の目安に使う）
     * @param  string|null  $unavailableLabel  計算できない月の理由。すべての月を計算できれば null
     * @param  TrendStatus|null  $unavailableStatus  1か月も計算できないときの状態。null なら需要の種類で決める（需要予測は予測不能、販売試算は販売目標なし）
     */
    public function __construct(
        public array $monthly,
        public array $shares,
        public ?float $averageDaily,
        public ?string $unavailableLabel = null,
        public ?TrendStatus $unavailableStatus = null,
    ) {}

    public function shareAt(int $index): ?float
    {
        return $this->shares[$index] ?? null;
    }
}
