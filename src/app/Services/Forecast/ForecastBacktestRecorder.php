<?php

namespace App\Services\Forecast;

use App\Models\ForecastBacktest;
use App\Models\User;
use App\Services\SyncAlreadyRunningException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 需要予測の当たり具合を測り、結果を残す（B-130、K-111）。同時に2回は測らない。最新20件を残し、それより古い記録は結果ごと消す。
 */
class ForecastBacktestRecorder
{
    private const LOCK_KEY = 'forecast-backtest';

    private const LOCK_SECONDS = 300;

    public function __construct(private ForecastBacktester $backtester) {}

    /**
     * @throws SyncAlreadyRunningException 別の測定が実行中のとき
     */
    public function record(CarbonImmutable $now, ?User $user): ForecastBacktest
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);
        if (! $lock->get()) {
            throw new SyncAlreadyRunningException('予測精度を測っています。終わるまでお待ちください。');
        }

        try {
            $startedAt = microtime(true);
            $result = $this->backtester->run($now);

            return DB::transaction(function () use ($result, $user, $startedAt): ForecastBacktest {
                $backtest = ForecastBacktest::create([
                    'measured_at' => $result['measured_at'],
                    'measured_by_user_id' => $user?->id,
                    'check_month_offset' => $result['check_month_offset'],
                    'settings' => [
                        'window_weights' => DemandForecaster::WINDOW_WEIGHTS,
                        'min_sales_days' => DemandForecaster::MIN_SALES_DAYS,
                        'recent_days' => ForecastBacktester::RECENT_DAYS,
                        // イベントの補正（B-129、K-112）：倍率の候補の求め方と、測ったときに登録してあったイベント
                        'event_recent_events' => EventUpliftEstimator::RECENT_EVENTS,
                        'event_min_expected' => EventUpliftEstimator::MIN_EXPECTED,
                        'event_shrink_periods' => EventUpliftEstimator::SHRINK_PERIODS,
                        'events' => $result['events'],
                        // 季節係数（B-106、K-113）：測ったときに登録してあった品番ごとの係数
                        'seasonal_factors' => $result['seasonal_factors'],
                        'target_items' => $result['target_items'],
                        'origins' => array_map(fn (array $origins): array => array_map(fn (CarbonImmutable $origin): string => $origin->toDateString(), $origins), $result['origins']),
                    ],
                    'item_count' => $result['item_count'],
                    'sku_count' => $result['sku_count'],
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);
                foreach (array_chunk($result['rows'], 500) as $chunk) {
                    $backtest->results()->insert(array_map(fn (array $row): array => ['forecast_backtest_id' => $backtest->id, ...$row], $chunk));
                }
                $this->prune();

                return $backtest;
            });
        } finally {
            $lock->release();
        }
    }

    private function prune(): void
    {
        $oldestRetainedId = ForecastBacktest::query()->orderByDesc('id')->offset(ForecastBacktest::RETAINED - 1)->value('id');
        if ($oldestRetainedId !== null) {
            ForecastBacktest::query()->where('id', '<', $oldestRetainedId)->delete();
        }
    }
}
