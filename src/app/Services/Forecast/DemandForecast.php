<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\Item;
use App\Models\ItemSelection;
use Carbon\CarbonImmutable;

/**
 * 対象品番のSKUの需要予測と、計算に使った条件。
 */
final readonly class DemandForecast
{
    /**
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channels  チャネルごとの販売実績の期間。取り込んでいなければ null
     * @param  list<array{item: Item, skus: list<SkuForecast>}>  $items  対象品番の順（売上順位の高い順）
     */
    public function __construct(
        public CarbonImmutable $calculatedAt,
        public ?ItemSelection $selection,
        public array $channels,
        public CarbonImmutable $forecastFrom,
        public array $items,
    ) {}
}
