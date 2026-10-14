<?php

namespace App\Services\Forecast;

use App\Enums\SnapshotTrigger;
use App\Models\ForecastAdjustment;
use App\Models\ForecastSnapshot;
use App\Services\InventoryTrend\TrendSettings;
use App\Services\ItemSelection\TargetItems;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 需要予測の版を保存する（B-108、決定記録 K-136）。そのときの需要予測（DemandForecaster::forecast()、手動での補正を掛けた後）を、
 * SKU・チャネル・12か月ごとに、補正の前の数・イベントで増やした数・補正の倍率・基準平均日販とともに残す。
 * 計算の条件として、チャネルごとの販売実績の期間・対象品番・掛けた補正・季節係数を登録していた品番・欠品を判定する月も残す。
 */
class ForecastSnapshotTaker
{
    public function __construct(private DemandForecaster $forecaster, private TargetItems $targets) {}

    public function take(CarbonImmutable $now, SnapshotTrigger $trigger, ?int $userId = null): ForecastSnapshot
    {
        $forecast = $this->forecaster->forecast($now);

        return DB::transaction(function () use ($forecast, $now, $trigger, $userId): ForecastSnapshot {
            $skuCount = array_sum(array_map(fn (array $entry): int => count($entry['skus']), $forecast->items));
            $snapshot = ForecastSnapshot::create([
                'taken_at' => $now,
                'forecast_from' => $forecast->forecastFrom,
                'trigger' => $trigger,
                'created_by_user_id' => $userId,
                'check_month_offset' => TrendSettings::current()->checkMonthOffset,
                'channels' => array_map(fn (array $range): array => [
                    'channel' => $range['channel']->value,
                    'from' => $range['from']?->toDateString(),
                    'to' => $range['to']?->toDateString(),
                ], $forecast->channels),
                'target_items' => $this->targets->lists(),
                'adjustments' => array_values(array_map(fn (ForecastAdjustment $adjustment): array => [
                    'id' => $adjustment->id,
                    'item_no' => $adjustment->item_no,
                    'channel' => $adjustment->channel->value,
                    'starts_on' => $adjustment->starts_on->format('Y-m'),
                    'ends_on' => $adjustment->ends_on?->format('Y-m'),
                    'rate_percent' => $adjustment->rate_percent,
                ], array_merge(...array_values(array_map(fn (array $channels): array => array_merge(...array_values($channels)), $forecast->adjustments))))),
                'seasonal_item_nos' => array_keys($forecast->seasonalFactors),
                'item_count' => count($forecast->items),
                'sku_count' => $skuCount,
            ]);

            $rows = [];
            foreach ($forecast->items as $entry) {
                foreach ($entry['skus'] as $skuForecast) {
                    foreach ($skuForecast->channels as $channelForecast) {
                        array_push($rows, ...$this->rows($snapshot->id, $entry['item']->item_no, $skuForecast, $channelForecast, $forecast->forecastFrom));
                    }
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('forecast_snapshot_values')->insert($chunk);
            }

            return $snapshot;
        });
    }

    /**
     * その月（予測の開始日の月）に、毎月1日の自動保存の版がもうあるか。
     */
    public static function hasScheduled(CarbonImmutable $today): bool
    {
        return ForecastSnapshot::query()
            ->where('trigger', SnapshotTrigger::Schedule->value)
            ->whereBetween('forecast_from', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
            ->exists();
    }

    /**
     * SKU・チャネルの12か月分の行。予測できなければ、数量を null にして理由を残す。
     *
     * @return list<array<string, mixed>>
     */
    private function rows(int $snapshotId, string $itemNo, SkuForecast $skuForecast, ChannelForecast $channelForecast, CarbonImmutable $forecastFrom): array
    {
        $base = [
            'forecast_snapshot_id' => $snapshotId,
            'item_no' => $itemNo,
            'sku_id' => $skuForecast->sku->id,
            'sku_code' => $skuForecast->sku->sku_code,
            'channel' => $channelForecast->channel->value,
        ];
        if (! $channelForecast->isPredictable()) {
            return array_map(fn (int $index): array => [
                ...$base,
                'month' => $forecastFrom->startOfMonth()->addMonthsNoOverflow($index)->toDateString(),
                'quantity' => null,
                'unadjusted_quantity' => null,
                'event_quantity' => null,
                'adjustment_rate' => null,
                'base_average' => null,
                'unpredictable_reason' => $channelForecast->unpredictableReason?->value,
            ], range(0, DemandForecaster::MONTHS - 1));
        }

        return array_map(fn (MonthlyDemand $month): array => [
            ...$base,
            'month' => $month->month->toDateString(),
            'quantity' => round($month->quantity, 4),
            'unadjusted_quantity' => round($month->unadjustedQuantity(), 4),
            'event_quantity' => round($month->eventQuantity(), 4),
            'adjustment_rate' => $month->adjustmentRate,
            'base_average' => round($channelForecast->baseAverage, 4),
            'unpredictable_reason' => null,
        ], $channelForecast->monthly);
    }
}
