<?php

namespace App\Services\SalesImport;

use App\Models\SalesLine;

/**
 * 取り込んだ注文の販売実績を、ファイルの明細でまるごと置き換える（決定記録 K-022）。BOSS・Amazon の取込で共通に使う。
 */
class SalesLineReplacer
{
    /**
     * 指定した注文の保存済みの明細を消し、新しい明細を登録する。明細が無い注文（キャンセルされた注文など）は消えるだけになる。
     *
     * @param  list<string>  $orderIds  置き換える注文ID
     * @param  list<array{source_order_id: string, sales_date: string, mall: string, sku_id: int, warehouse: string, quantity: int, amount: int}>  $rows  登録する明細
     * @return array{created_line_count: int, updated_line_count: int, sales_date_from: ?string, sales_date_to: ?string}
     */
    public function replace(string $source, array $orderIds, array $rows): array
    {
        $existingLineCount = 0;
        foreach (array_chunk($orderIds, 1000) as $chunk) {
            $existingLineCount += SalesLine::query()->where('source', $source)->whereIn('source_order_id', $chunk)->delete();
        }

        $rows = array_map(fn (array $row): array => ['source' => $source, ...$row], $rows);
        foreach (array_chunk($rows, 1000) as $chunk) {
            SalesLine::insert($chunk);
        }

        $salesDates = array_column($rows, 'sales_date');

        return [
            'created_line_count' => max(0, count($rows) - $existingLineCount),
            'updated_line_count' => min($existingLineCount, count($rows)),
            'sales_date_from' => $salesDates === [] ? null : min($salesDates),
            'sales_date_to' => $salesDates === [] ? null : max($salesDates),
        ];
    }
}
