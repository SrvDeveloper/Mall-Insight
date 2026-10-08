<?php

namespace App\Services\Forecast;

use App\Enums\ActiveStatus;
use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * 平均日販による需要予測（バックログ B-006、決定記録 K-035）。対象品番（K-030）のSKUについて、
 * 3つの期間の平均日販を重みでまとめた基準平均日販から、12か月分の月別の需要を求める。結果は保存しない。
 *
 * 予測はチャネル（BOSS・Amazon）ごとに行い、SKUの予測は予測できたチャネルの合計とする（K-038）。
 * チャネルは販売実績の出荷倉庫で決め、販売実績を取り込んでいないチャネルは予測しない。
 * 子ASINの無いSKUは Amazon で売っていないものとし、Amazon の予測を出さない（K-042）。
 *
 * 期間はチャネルの販売実績の最新日から重ならずに遡る（直近30日、その前の60日＝31〜90日前、さらに前の90日＝91〜180日前）。
 * そのチャネルの最初の販売日より前の日は数えない。
 * 欠品していた日（そのチャネルの在庫の区分の合計が0以下の日）は、販売数・日数の両方から外す（K-037・K-038）。
 * 販売できた日数が14日未満か、欠品日が期間の半分以上ある期間は除外して重みを割り戻し、
 * 3期間とも除外されたSKUと、使える期間に1点も売れていないSKUは予測不能とする。
 */
class DemandForecaster
{
    /** 期間の日数 => 重み（合計1）。新しい期間から順に、重ならずに遡る。 */
    public const WINDOW_WEIGHTS = [30 => 0.5, 60 => 0.3, 90 => 0.2];

    /** 期間を使うのに必要な販売実績の日数。 */
    public const MIN_SALES_DAYS = 14;

    public const MONTHS = 12;

    /**
     * @param  list<string>|null  $itemNos  予測する品番。null なら対象品番（最新の選定）。入荷予定の自動割り振り（K-054）では、対象品番でない品番も予測する
     */
    public function forecast(CarbonImmutable $now, ?array $itemNos = null): DemandForecast
    {
        $selection = ItemSelection::current()?->load('items');
        $forecastFrom = $now->startOfDay();
        $items = $this->items(collect($itemNos ?? $selection?->items->pluck('item_no')->all() ?? []));
        $skuIds = $items->flatMap(fn (Item $item) => $item->skus)->pluck('id')->all();
        $channelRanges = $this->channelSalesRanges($forecastFrom);

        // チャネルごとに、期間・日別の販売数・欠品日を求める。SKUごとに日付の計算を繰り返すと遅いため、
        // 期間ごとの日付（Y-m-d）の一覧を先に1回だけ作って使い回す
        $channelData = [];
        foreach ($channelRanges as $channelRange) {
            if ($channelRange['to'] === null) {
                continue;
            }
            $channel = $channelRange['channel'];
            $ranges = $this->windowRanges($channelRange['from'], $channelRange['to']);
            $channelData[] = [
                'channel' => $channel,
                'ranges' => $ranges,
                'window_dates' => array_map(fn (array $range): array => $this->dates($range['from'], $range['to']), $ranges),
                'daily_sales' => $this->dailySales($skuIds, $ranges, $channel),
                'stockout_dates' => $this->stockoutDates($skuIds, $ranges, $channel),
            ];
        }

        return new DemandForecast(
            calculatedAt: $now,
            selection: $selection,
            channels: $channelRanges,
            forecastFrom: $forecastFrom,
            items: $items->map(fn (Item $item): array => [
                'item' => $item,
                'skus' => $item->skus->map(fn (Sku $sku): SkuForecast => SkuForecast::combine($sku, array_map(
                    fn (array $data): ChannelForecast => $this->forecastChannel($data['channel'], $data['ranges'], $data['window_dates'], $data['daily_sales'][$sku->id] ?? [], $data['stockout_dates'][$sku->id] ?? [], $forecastFrom),
                    array_values(array_filter($channelData, fn (array $data): bool => $this->sellsOn($sku, $data['channel']))),
                )))->all(),
            ])->values()->all(),
        );
    }

    /**
     * そのSKUをチャネルで売っているか。子ASINの無いSKUは Amazon で売っていない（K-042）。
     */
    private function sellsOn(Sku $sku, Channel $channel): bool
    {
        return $channel !== Channel::Amazon || ($sku->child_asin !== null && $sku->child_asin !== '');
    }

    /**
     * チャネルごとの販売実績の最初と最後の販売日。取り込んでいないチャネルは null。
     * 予測の開始日（今日）以降の分は1日が終わっておらず販売数が確定しないため、最後の販売日は前日までとする（K-041）。
     *
     * @return list<array{channel: Channel, from: ?CarbonImmutable, to: ?CarbonImmutable}>
     */
    private function channelSalesRanges(CarbonImmutable $forecastFrom): array
    {
        $byWarehouse = SalesLine::query()
            ->selectRaw('warehouse, min(sales_date) as first_date, max(sales_date) as last_date')
            ->groupBy('warehouse')
            ->toBase()
            ->get()
            ->keyBy('warehouse');

        return array_map(function (Channel $channel) use ($byWarehouse, $forecastFrom): array {
            $rows = $byWarehouse->only(array_map(fn (Warehouse $warehouse): string => $warehouse->value, $channel->warehouses()));

            return [
                'channel' => $channel,
                'from' => $rows->isEmpty() ? null : CarbonImmutable::parse($rows->min('first_date'))->startOfDay(),
                'to' => $rows->isEmpty() ? null : CarbonImmutable::parse($rows->max('last_date'))->startOfDay()->min($forecastFrom->subDay()),
            ];
        }, Channel::cases());
    }

    /**
     * 品番（CrossWalker に残っているもの）を、渡した順（対象品番なら選定の順＝売上順位の高い順）で返す。
     * 無効（廃番）のSKUは今後売らず発注もしないため、予測の対象から外す（K-053）。在庫推移からも外れる。
     *
     * @param  Collection<int, string>  $itemNos
     * @return Collection<int, Item>
     */
    private function items(Collection $itemNos): Collection
    {
        if ($itemNos->isEmpty()) {
            return collect();
        }
        $items = Item::query()
            ->with(['skus' => fn (HasMany $query) => $query->where(fn (Builder $query) => $query->whereNull('status')->orWhere('status', '!=', ActiveStatus::Inactive->value))])
            ->whereIn('item_no', $itemNos)
            ->get()
            ->keyBy('item_no');

        return $itemNos->map(fn (string $itemNo) => $items->get($itemNo))->filter()->values();
    }

    /**
     * 期間ごとの初日・末日と、販売実績のある日数。期間は最新日から重ならずに遡り、最初の販売日より前は含めない。
     * 期間に販売実績の日が1日も無ければ、初日・末日は null。offset は、期間の末日が最新日の何日前か。
     *
     * @return array<int, array{from: ?CarbonImmutable, to: ?CarbonImmutable, days: int, offset: int}>
     */
    private function windowRanges(?CarbonImmutable $salesDataFrom, ?CarbonImmutable $salesDataTo): array
    {
        $ranges = [];
        $offset = 0;
        foreach (array_keys(self::WINDOW_WEIGHTS) as $length) {
            $ranges[$length] = ['from' => null, 'to' => null, 'days' => 0, 'offset' => $offset];
            if ($salesDataFrom !== null && $salesDataTo !== null) {
                $to = $salesDataTo->subDays($offset);
                $from = $to->subDays($length - 1)->max($salesDataFrom);
                if ($from <= $to) {
                    $ranges[$length] = ['from' => $from, 'to' => $to, 'days' => (int) $from->diffInDays($to) + 1, 'offset' => $offset];
                }
            }
            $offset += $length;
        }

        return $ranges;
    }

    /**
     * SKUごと・日ごとの、そのチャネルの販売数量（期間に含まれる日だけ）。
     *
     * @param  list<int>  $skuIds
     * @param  array<int, array{from: ?CarbonImmutable, to: ?CarbonImmutable, days: int, offset: int}>  $ranges
     * @return array<int, array<string, int>> [SKU ID => [Y-m-d => 販売数量]]
     */
    private function dailySales(array $skuIds, array $ranges, Channel $channel): array
    {
        [$from, $to] = $this->span($ranges);
        if ($skuIds === [] || $from === null) {
            return [];
        }

        // 行数が多いため、モデルにせず素の値で受け取る
        $sales = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = SalesLine::query()
                ->selectRaw('sku_id, sales_date, sum(quantity) as quantity')
                ->whereIn('sku_id', $chunk)
                ->whereIn('warehouse', $this->warehouseValues($channel))
                ->where('sales_date', '>=', $from->toDateString())
                ->where('sales_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'sales_date')
                ->toBase()
                ->get();
            foreach ($rows as $row) {
                $sales[$row->sku_id][substr((string) $row->sales_date, 0, 10)] = (int) $row->quantity;
            }
        }

        return $sales;
    }

    /**
     * SKUごとの、そのチャネルの欠品日（K-037・K-038）。在庫基準日の、そのチャネルの在庫の区分の合計が0以下なら欠品とする。
     * 在庫基準日の無い日（土日など）は直前の在庫基準日の状態を引き継ぎ、そのSKUの最初の在庫基準日より前は欠品としない。
     *
     * @param  list<int>  $skuIds
     * @param  array<int, array{from: ?CarbonImmutable, to: ?CarbonImmutable, days: int, offset: int}>  $ranges
     * @return array<int, array<string, true>> [SKU ID => [Y-m-d => true]]
     */
    private function stockoutDates(array $skuIds, array $ranges, Channel $channel): array
    {
        [$from, $to] = $this->span($ranges);
        if ($skuIds === [] || $from === null) {
            return [];
        }

        $stockouts = [];
        foreach (array_chunk($skuIds, 500) as $chunk) {
            $rows = Inventory::query()
                ->selectRaw('sku_id, stock_date, sum(quantity) as quantity')
                ->whereIn('sku_id', $chunk)
                ->whereIn('warehouse', $this->warehouseValues($channel))
                ->where('stock_date', '<', $to->addDay()->toDateString())
                ->groupBy('sku_id', 'stock_date')
                ->orderBy('stock_date')
                ->toBase()
                ->get();
            $spanDates = $this->dates($from, $to);
            foreach ($rows->groupBy('sku_id') as $skuId => $surveys) {
                $isOutOfStock = [];
                foreach ($surveys as $survey) {
                    $isOutOfStock[substr((string) $survey->stock_date, 0, 10)] = (int) $survey->quantity <= 0;
                }
                // 期間の初日より前の、最後の在庫基準日の状態から始める
                $state = null;
                foreach ($isOutOfStock as $date => $outOfStock) {
                    if ($date >= $spanDates[0]) {
                        break;
                    }
                    $state = $outOfStock;
                }
                foreach ($spanDates as $date) {
                    $state = $isOutOfStock[$date] ?? $state;
                    if ($state === true) {
                        $stockouts[$skuId][$date] = true;
                    }
                }
            }
        }

        return $stockouts;
    }

    /**
     * @return list<string>
     */
    private function warehouseValues(Channel $channel): array
    {
        return array_map(fn (Warehouse $warehouse): string => $warehouse->value, $channel->warehouses());
    }

    /**
     * 初日から末日までの日付（Y-m-d）の一覧。初日が null なら空。
     *
     * @return list<string>
     */
    private function dates(?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $dates = [];
        for ($day = $from; $day !== null && $day <= $to; $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /**
     * 3つの期間を合わせた初日と末日。販売実績が無ければ [null, null]。
     *
     * @param  array<int, array{from: ?CarbonImmutable, to: ?CarbonImmutable, days: int, offset: int}>  $ranges
     * @return array{?CarbonImmutable, ?CarbonImmutable}
     */
    private function span(array $ranges): array
    {
        $used = array_filter($ranges, fn (array $range): bool => $range['days'] > 0);
        if ($used === []) {
            return [null, null];
        }

        return [min(array_column($used, 'from')), max(array_column($used, 'to'))];
    }

    /**
     * @param  array<int, array{from: ?CarbonImmutable, to: ?CarbonImmutable, days: int, offset: int}>  $ranges
     * @param  array<int, list<string>>  $windowDates  [期間の日数 => 期間の日付（Y-m-d）]
     * @param  array<string, int>  $dailySales  [Y-m-d => 販売数量]
     * @param  array<string, true>  $stockoutDates  [Y-m-d => true]
     */
    private function forecastChannel(Channel $channel, array $ranges, array $windowDates, array $dailySales, array $stockoutDates, CarbonImmutable $forecastFrom): ChannelForecast
    {
        // 期間ごとに、欠品日を除いた販売数と販売できた日数を数える
        $counts = [];
        foreach ($ranges as $length => $range) {
            $quantity = 0;
            $stockoutDays = 0;
            foreach ($windowDates[$length] as $date) {
                if (isset($stockoutDates[$date])) {
                    $stockoutDays++;

                    continue;
                }
                $quantity += $dailySales[$date] ?? 0;
            }
            $salesDays = $range['days'] - $stockoutDays;
            $isUsable = $salesDays >= self::MIN_SALES_DAYS && $stockoutDays * 2 < $range['days'];
            $counts[$length] = ['quantity' => $quantity, 'stockout_days' => $stockoutDays, 'sales_days' => $salesDays, 'usable' => $isUsable];
        }

        $usableWeight = array_sum(array_map(fn (int $length): float => $counts[$length]['usable'] ? self::WINDOW_WEIGHTS[$length] : 0.0, array_keys(self::WINDOW_WEIGHTS)));
        $windows = [];
        $baseAverage = null;
        foreach (self::WINDOW_WEIGHTS as $length => $baseWeight) {
            $range = $ranges[$length];
            $count = $counts[$length];
            $average = $count['usable'] ? $count['quantity'] / $count['sales_days'] : null;
            // 除外した期間の重みを0とし、残る期間の重みの合計が1になるよう割り戻す
            $weight = $count['usable'] ? $baseWeight / $usableWeight : 0.0;
            if ($average !== null) {
                $baseAverage = ($baseAverage ?? 0.0) + $average * $weight;
            }
            $windows[] = new AverageWindow($length, $range['offset'], $range['from'], $range['to'], $count['sales_days'], $count['stockout_days'], $count['quantity'], $average, $baseWeight, $weight);
        }

        if ($baseAverage === null) {
            $hasStockout = array_sum(array_column($counts, 'stockout_days')) > 0;

            return new ChannelForecast($channel, $windows, null, null, $hasStockout ? UnpredictableReason::Stockout : UnpredictableReason::InsufficientDays);
        }
        // 使える期間に1点も売れていないSKUは、在庫の記録が無い期間に欠品していた可能性があるため0と予測しない
        if (array_sum(array_map(fn (AverageWindow $window): int => $window->isExcluded() ? 0 : $window->salesQuantity, $windows)) === 0) {
            return new ChannelForecast($channel, $windows, null, null, UnpredictableReason::NoSales);
        }

        return new ChannelForecast($channel, $windows, $baseAverage, $this->monthly($baseAverage, $forecastFrom));
    }

    /**
     * 予測の開始日の月から12か月分。初月（今月）も、ほかの月と同じく1か月すべての日数分とする（K-052）。
     *
     * @return list<MonthlyDemand>
     */
    private function monthly(float $baseAverage, CarbonImmutable $forecastFrom): array
    {
        $months = [];
        for ($index = 0; $index < self::MONTHS; $index++) {
            $month = $forecastFrom->startOfMonth()->addMonthsNoOverflow($index);
            $months[] = new MonthlyDemand($month, $month->daysInMonth, $baseAverage * $month->daysInMonth);
        }

        return $months;
    }
}
