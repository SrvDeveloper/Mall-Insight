<?php

namespace App\Services\InventoryTrend;

use App\Models\Item;
use App\Services\Forecast\DemandForecast;
use Carbon\CarbonImmutable;

/**
 * 対象品番のSKUの12か月在庫推移と、計算に使った条件。
 */
final readonly class InventoryTrend
{
    /**
     * @param  list<array{month: CarbonImmutable, days: int}>  $months  推移の12か月（今月から）
     * @param  list<array{item: Item, skus: list<SkuTrend>, unassignableInbound: int}>  $items  対象品番の順。unassignableInbound は、需要の出せるSKUが無く仮に割り振れなかった入荷予定の数
     * @param  int  $overdueInboundCount  入荷予定月を過ぎても入荷済みになっておらず、今月の入荷として数えた入荷予定の件数
     * @param  list<array{fiscalYear: int, from: CarbonImmutable, to: CarbonImmutable, calculatedItemCount: int}>|null  $salesTargets  販売試算のとき、推移の月が入る年度ごとの、販売目標を割り振れた対象品番の数
     */
    public function __construct(
        public CarbonImmutable $calculatedAt,
        public DemandBasis $basis,
        public TrendScope $scope,
        public ?CarbonImmutable $stockDate,
        public DemandForecast $forecast,
        public TrendSettings $settings,
        public array $months,
        public array $items,
        public int $overdueInboundCount,
        public ?array $salesTargets = null,
    ) {}
}
