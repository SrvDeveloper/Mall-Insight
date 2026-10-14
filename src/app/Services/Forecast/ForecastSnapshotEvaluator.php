<?php

namespace App\Services\Forecast;

use App\Enums\Channel;
use App\Enums\Warehouse;
use App\Models\ForecastSnapshot;
use App\Models\ForecastSnapshotValue;
use App\Models\SalesLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * 保存した需要予測の版（B-108、K-136）の答え合わせ。版の予測（実際に出していた予測）を、その後の販売実績と比べる。
 *
 * - 何か月先は予測精度（B-130）と同じく、1か月目（版を保存した月）・1～3か月目の合計・判定する月（保存したときの設定）。
 * - チャネルごとに、保存したときのそのチャネルの対象品番のSKUで、答え合わせの月をすべて予測できていたものだけを比べる。
 *   答え合わせできるのは、今月より前で、そのチャネルの販売実績がその月末まで取り込まれている月。全体は両チャネルとも答え合わせできるときだけ出す。
 * - 外れ率（WAPE）・品番の合計で比べた外れ率・偏りを、補正の後（実際に出した予測）と補正の前（手動での補正 B-109 を掛ける前）で出す。
 *   補正で当たるようになったかを確かめるため。
 */
class ForecastSnapshotEvaluator
{
    public const SCOPES = ['total', 'boss', 'amazon'];

    public function __construct(private DemandForecaster $forecaster) {}

    /**
     * @param  Collection<int, ForecastSnapshot>  $snapshots
     * @return array<int, array<string, array{months: list<string>, available_from: string, channels: array<string, array<string, mixed>|null>}>> 版のID => 何か月先 => 答え合わせの月・答え合わせできるようになる月・全体／チャネルごとの結果（まだなら null）
     */
    public function evaluate(Collection $snapshots, CarbonImmutable $today): array
    {
        if ($snapshots->isEmpty()) {
            return [];
        }
        $currentMonth = $today->startOfMonth();
        // チャネルごとの、答え合わせできる最後の月（販売実績がその月末まで取り込まれていて、今月より前）
        $lastMonths = [];
        foreach ($this->forecaster->channelSalesRanges($today) as $range) {
            $to = $range['to'];
            $lastMonths[$range['channel']->value] = $to === null ? null : ($to->equalTo($to->endOfMonth()->startOfDay()) ? $to->startOfMonth() : $to->startOfMonth()->subMonthNoOverflow())->min($currentMonth->subMonthNoOverflow());
        }

        $plans = [];
        $needed = [];
        foreach ($snapshots as $snapshot) {
            $first = $snapshot->forecast_from->startOfMonth();
            foreach (BacktestHorizon::cases() as $horizon) {
                [$from, $to] = $horizon->monthOffsets($snapshot->check_month_offset);
                $months = array_map(fn (int $offset): string => $first->addMonthsNoOverflow($offset)->format('Y-m'), range($from, $to));
                $plans[$snapshot->id][$horizon->value] = $months;
                foreach ($lastMonths as $channel => $last) {
                    if ($last !== null && end($months) <= $last->format('Y-m')) {
                        foreach ($months as $month) {
                            $needed[$month] = true;
                        }
                    }
                }
            }
        }
        $actuals = $needed === [] ? [] : $this->monthlyActuals(array_keys($needed));

        $results = [];
        foreach ($snapshots as $snapshot) {
            $values = null;
            foreach ($plans[$snapshot->id] as $horizon => $months) {
                $channels = [];
                foreach ([Channel::Boss, Channel::Amazon] as $channel) {
                    $last = $lastMonths[$channel->value] ?? null;
                    if ($last === null || end($months) > $last->format('Y-m')) {
                        $channels[$channel->value] = null;

                        continue;
                    }
                    $values ??= $snapshot->values()->whereNotNull('quantity')->get(['item_no', 'sku_id', 'channel', 'month', 'quantity', 'unadjusted_quantity', 'adjustment_rate']);
                    $channels[$channel->value] = $this->measure($values->where('channel', $channel->value), $months, $snapshot->target_items[$channel->value] ?? [], $actuals[$channel->value] ?? []);
                }
                $channels['total'] = in_array(null, $channels, true) ? null : $this->sum($channels);
                $results[$snapshot->id][$horizon] = [
                    'months' => $months,
                    // 答え合わせできるようになる月（最後の月の翌月）
                    'available_from' => CarbonImmutable::createFromFormat('Y-m-d', end($months).'-01')->addMonthNoOverflow()->format('Y-m'),
                    'channels' => array_map(fn (?array $sums): ?array => $sums === null ? null : $this->metrics($sums), array_merge(['total' => null, 'boss' => null, 'amazon' => null], $channels)),
                ];
            }
        }

        return $results;
    }

    /**
     * チャネルの、答え合わせの月の予測（補正の後・前）と販売実績の合計。SKUごとに月をすべて予測できていたものだけを比べる。
     *
     * @param  Collection<int, ForecastSnapshotValue>  $values
     * @param  list<string>  $months
     * @param  list<string>  $targetItemNos
     * @param  array<int, array<string, int>>  $actuals  SKU ID => 月（Y-m） => 販売数
     * @return array{evaluated: int, adjusted: int, actual: float, forecast: float, abs_error: float, item_abs_error: float, unadjusted_forecast: float, unadjusted_abs_error: float, unadjusted_item_abs_error: float}
     */
    private function measure(Collection $values, array $months, array $targetItemNos, array $actuals): array
    {
        $targets = array_flip($targetItemNos);
        $sums = ['evaluated' => 0, 'adjusted' => 0, 'actual' => 0.0, 'forecast' => 0.0, 'abs_error' => 0.0, 'item_abs_error' => 0.0, 'unadjusted_forecast' => 0.0, 'unadjusted_abs_error' => 0.0, 'unadjusted_item_abs_error' => 0.0];
        $items = [];
        foreach ($values->filter(fn ($value): bool => isset($targets[$value->item_no]) && in_array($value->month->format('Y-m'), $months, true))->groupBy('sku_id') as $skuId => $rows) {
            if ($rows->count() !== count($months)) {
                continue;
            }
            $actual = (float) array_sum(array_map(fn (string $month): int => $actuals[$skuId][$month] ?? 0, $months));
            $forecast = (float) $rows->sum('quantity');
            $unadjusted = (float) $rows->sum('unadjusted_quantity');
            $itemNo = $rows->first()->item_no;
            $sums['evaluated']++;
            $sums['adjusted'] += $rows->contains(fn ($row): bool => $row->adjustment_rate !== null) ? 1 : 0;
            $sums['actual'] += $actual;
            $sums['forecast'] += $forecast;
            $sums['abs_error'] += abs($forecast - $actual);
            $sums['unadjusted_forecast'] += $unadjusted;
            $sums['unadjusted_abs_error'] += abs($unadjusted - $actual);
            $items[$itemNo] = [($items[$itemNo][0] ?? 0.0) + $actual, ($items[$itemNo][1] ?? 0.0) + $forecast, ($items[$itemNo][2] ?? 0.0) + $unadjusted];
        }
        foreach ($items as [$actual, $forecast, $unadjusted]) {
            $sums['item_abs_error'] += abs($forecast - $actual);
            $sums['unadjusted_item_abs_error'] += abs($unadjusted - $actual);
        }

        return $sums;
    }

    /**
     * チャネルの合計を足す。
     *
     * @param  array<string, array<string, int|float>>  $channels
     * @return array<string, int|float>
     */
    private function sum(array $channels): array
    {
        $total = [];
        foreach ($channels as $sums) {
            foreach ($sums as $key => $value) {
                $total[$key] = ($total[$key] ?? 0) + $value;
            }
        }

        return $total;
    }

    /**
     * 合計から、外れ率・品番の合計で比べた外れ率・偏りを求める（販売実績が0なら null）。
     *
     * @param  array<string, int|float>  $sums
     * @return array<string, int|float|null>
     */
    private function metrics(array $sums): array
    {
        $actual = (float) $sums['actual'];
        $rate = fn (float $value): ?float => $actual > 0 ? round($value / $actual, 4) : null;

        return [
            'evaluated' => (int) $sums['evaluated'],
            'adjusted' => (int) $sums['adjusted'],
            'actual' => round($actual, 2),
            'forecast' => round((float) $sums['forecast'], 2),
            'wape' => $rate((float) $sums['abs_error']),
            'item_wape' => $rate((float) $sums['item_abs_error']),
            'bias' => $rate((float) $sums['forecast'] - $actual),
            'unadjusted_forecast' => round((float) $sums['unadjusted_forecast'], 2),
            'unadjusted_wape' => $rate((float) $sums['unadjusted_abs_error']),
            'unadjusted_item_wape' => $rate((float) $sums['unadjusted_item_abs_error']),
            'unadjusted_bias' => $rate((float) $sums['unadjusted_forecast'] - $actual),
        ];
    }

    /**
     * チャネル・SKU・月ごとの販売数。
     *
     * @param  list<string>  $months  Y-m
     * @return array<string, array<int, array<string, int>>> チャネルの値 => SKU ID => 月 => 販売数
     */
    private function monthlyActuals(array $months): array
    {
        sort($months);
        $from = $months[0].'-01';
        $to = CarbonImmutable::createFromFormat('Y-m-d', end($months).'-01')->addMonthNoOverflow()->toDateString();
        $actuals = [];
        $rows = SalesLine::query()
            ->selectRaw('sku_id, warehouse, substr(sales_date, 1, 7) as month, sum(quantity) as quantity')
            ->where('sales_date', '>=', $from)
            ->where('sales_date', '<', $to)
            ->groupByRaw('sku_id, warehouse, substr(sales_date, 1, 7)')
            ->toBase()
            ->get();
        foreach ($rows as $row) {
            $channel = Warehouse::tryFrom((string) $row->warehouse)?->channel();
            if ($channel === null || $row->sku_id === null) {
                continue;
            }
            $actuals[$channel->value][(int) $row->sku_id][(string) $row->month] = ($actuals[$channel->value][(int) $row->sku_id][(string) $row->month] ?? 0) + (int) $row->quantity;
        }

        return $actuals;
    }
}
