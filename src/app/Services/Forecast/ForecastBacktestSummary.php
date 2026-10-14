<?php

namespace App\Services\Forecast;

use App\Models\ForecastBacktest;
use App\Models\ForecastBacktestResult;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * 測った結果を、何か月先とチャネル（全体・BOSS・Amazon）で絞り、方式ごとに集計する（B-130、K-111）。
 *
 * - WAPE＝Σ|予測－販売実績| ÷ Σ販売実績（SKU・チャネル・起点ごとの差の絶対値を足すため、SKU どうし・チャネルどうしで打ち消し合わない）。
 * - 偏り＝(Σ予測－Σ販売実績) ÷ Σ販売実績（＋なら多めに、－なら少なめに予測している）。
 * - 販売実績の合計が0なら、WAPE・偏りは出さない（null）。
 * - 全体は BOSS と Amazon の結果を足す（チャネルごとの差の絶対値の合計なので、チャネルどうしで打ち消し合わない）。
 * - 品番の合計で比べた外れ率（item_wape）＝Σ|品番・チャネル・起点ごとの予測の合計－販売実績の合計| ÷ Σ販売実績。SKUどうしの偶然のぶれが
 *   打ち消し合うため、予測の方法の良し悪しを見るのに使う（K-124）。
 * - イベントの日（event）と普段の日（normal）に分けた値も同じように求める（B-133、K-125）。分ける前に測った記録では null。
 */
class ForecastBacktestSummary
{
    /**
     * @return array{methods: list<array<string, mixed>>, items: list<array<string, mixed>>, origins: list<array<string, mixed>>}
     */
    public function summarize(ForecastBacktest $backtest, BacktestHorizon $horizon, ?string $channel): array
    {
        $results = ForecastBacktestResult::query()
            ->where('forecast_backtest_id', $backtest->id)
            ->where('horizon', $horizon->value)
            ->when($channel !== null, fn ($query) => $query->where('channel', $channel))
            ->get();
        $items = Item::query()->whereIn('item_no', $results->pluck('item_no')->unique())->get()->keyBy('item_no');

        $methods = array_map(fn (BacktestMethod $method): array => ['method' => $method->value, 'label' => $method->label(), ...$this->metrics($results->where('method', $method->value))], BacktestMethod::cases());

        $itemRows = $results->groupBy('item_no')->map(function (Collection $rows, string $itemNo) use ($items): array {
            $item = $items->get($itemNo);

            return [
                'item_no' => $itemNo,
                'brand' => $item?->brand,
                'category' => $item?->category,
                'methods' => $this->byMethod($rows),
            ];
        })->sortByDesc(fn (array $row): float => max(array_column($row['methods'], 'actual')))->values()->all();

        $originRows = $results->groupBy(fn (ForecastBacktestResult $result): string => $result->origin->toDateString())
            ->map(fn (Collection $rows, string $origin): array => ['origin' => $origin, 'methods' => $this->byMethod($rows)])
            ->sortBy('origin')->values()->all();

        return ['methods' => $methods, 'items' => $itemRows, 'origins' => $originRows];
    }

    /**
     * @param  Collection<int, ForecastBacktestResult>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function byMethod(Collection $rows): array
    {
        $byMethod = [];
        foreach (BacktestMethod::cases() as $method) {
            $byMethod[$method->value] = $this->metrics($rows->where('method', $method->value));
        }

        return $byMethod;
    }

    /**
     * @param  Collection<int, ForecastBacktestResult>  $rows
     * @return array{evaluated: int, skipped: int, actual: float, forecast: float, abs_error: float, wape: float|null, bias: float|null, item_wape: float|null, event: array<string, float|null>|null, normal: array<string, float|null>|null}
     */
    private function metrics(Collection $rows): array
    {
        $isSplit = $rows->isNotEmpty() && $rows->every(fn (ForecastBacktestResult $row): bool => $row->event_actual !== null);

        return [
            'evaluated' => (int) $rows->sum('evaluated'),
            'skipped' => (int) $rows->sum('skipped'),
            ...$this->errors($rows, 'actual', 'forecast', 'abs_error'),
            'event' => $isSplit ? $this->errors($rows, 'event_actual', 'event_forecast', 'event_abs_error') : null,
            'normal' => $isSplit ? $this->errors($rows, 'normal_actual', 'normal_forecast', 'normal_abs_error') : null,
        ];
    }

    /**
     * @param  Collection<int, ForecastBacktestResult>  $rows
     * @return array{actual: float, forecast: float, abs_error: float, wape: float|null, bias: float|null, item_wape: float|null}
     */
    private function errors(Collection $rows, string $actualColumn, string $forecastColumn, string $absErrorColumn): array
    {
        $actual = (float) $rows->sum($actualColumn);
        $forecast = (float) $rows->sum($forecastColumn);
        $absError = (float) $rows->sum($absErrorColumn);
        $itemError = (float) $rows->sum(fn (ForecastBacktestResult $row): float => abs($row->{$forecastColumn} - $row->{$actualColumn}));

        return [
            'actual' => $actual,
            'forecast' => round($forecast, 2),
            'abs_error' => round($absError, 2),
            'wape' => $actual > 0 ? round($absError / $actual, 4) : null,
            'bias' => $actual > 0 ? round(($forecast - $actual) / $actual, 4) : null,
            'item_wape' => $actual > 0 ? round($itemError / $actual, 4) : null,
        ];
    }
}
