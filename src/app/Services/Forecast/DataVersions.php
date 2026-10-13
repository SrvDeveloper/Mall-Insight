<?php

namespace App\Services\Forecast;

use Illuminate\Support\Facades\DB;

/**
 * 販売実績と在庫の、月ごとの版（いちばん大きい行IDと行数）。取込は行を消して入れ直すため、月の中身が変わればどちらかが変わる。
 * 計算した結果をキャッシュに残すとき（イベントの倍率を測った結果 K-121、予測し直した数 K-123）に、使ったデータが変わったかを見る。
 */
final readonly class DataVersions
{
    /**
     * @param  array<string, string>  $sales  年月（Y-m） => 版
     * @param  array<string, string>  $inventories  年月（Y-m） => 版
     */
    public function __construct(public array $sales, public array $inventories) {}

    public static function current(): self
    {
        $byMonth = fn (string $table, string $column): array => DB::table($table)
            ->selectRaw("substr({$column}, 1, 7) as month, max(id) as max_id, count(*) as row_count")
            ->groupByRaw("substr({$column}, 1, 7)")
            ->get()
            ->mapWithKeys(fn (object $row): array => [(string) $row->month => $row->max_id.'/'.$row->row_count])
            ->all();

        return new self($byMonth('sales_lines', 'sales_date'), $byMonth('inventories', 'stock_date'));
    }

    /**
     * $from～$to の月（Y-m）の販売実績の版。
     *
     * @return array<string, string>
     */
    public function salesBetween(string $fromMonth, string $toMonth): array
    {
        return array_filter($this->sales, fn (string $month): bool => $month >= $fromMonth && $month <= $toMonth, ARRAY_FILTER_USE_KEY);
    }

    /**
     * $toMonth までの月の在庫の版（欠品は、記録の無い日に直前の記録の状態を引き継ぐため、それより前の在庫も使う）。
     *
     * @return array<string, string>
     */
    public function inventoriesUntil(string $toMonth): array
    {
        return array_filter($this->inventories, fn (string $month): bool => $month <= $toMonth, ARRAY_FILTER_USE_KEY);
    }
}
