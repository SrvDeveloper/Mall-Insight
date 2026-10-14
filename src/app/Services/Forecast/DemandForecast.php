<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Models\ForecastAdjustment;
use App\Models\Item;
use App\Models\SalesEventPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 対象品番のSKUの需要予測と、計算に使った条件。
 */
final readonly class DemandForecast
{
    /**
     * @param  list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>  $channels  チャネルごとの販売実績の期間。取り込んでいなければ null
     * @param  list<array{item: Item, skus: list<SkuForecast>}>  $items  対象品番の順（売上順位の高い順）
     * @param  Collection<int, SalesEventPeriod>  $events  登録したイベントの期間（B-129、K-114）。平均日販から除いた日と、倍率を掛けたこれからの期間の根拠
     * @param  array<string, array<int, float>>  $seasonalFactors  品番 => 登録した季節係数（B-106）
     * @param  EventPlan  $eventPlan  平均日販から除いた日と、品番・チャネルごとのこれからのイベントの倍率（日ごとの予測に使う、B-131）
     * @param  array<string, array<string, list<ForecastAdjustment>>>  $adjustments  品番 => チャネルの値 => 掛けた手動での補正（B-109、K-132）
     */
    public function __construct(
        public CarbonImmutable $calculatedAt,
        public array $channels,
        public CarbonImmutable $forecastFrom,
        public array $items,
        public Collection $events = new Collection,
        public array $seasonalFactors = [],
        public EventPlan $eventPlan = new EventPlan,
        public array $adjustments = [],
    ) {}
}
