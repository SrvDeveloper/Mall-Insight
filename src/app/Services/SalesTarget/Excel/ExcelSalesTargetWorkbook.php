<?php

namespace App\Services\SalesTarget\Excel;

use App\Enums\RatioCategoryCode;
use RuntimeException;

/**
 * 現行の販売試算Excelから、年間販売想定数・SKUの年間の数・品番ごとの比率の区分・月別販売比率を読む（バックログ B-110、K-048・K-049）。
 * セルの位置は [現行販売試算 仕様書](../../../../../docs/現行販売試算_仕様書.md) の 5.2・5.3・7.1 による。
 */
class ExcelSalesTargetWorkbook
{
    /** 主シートの名前の先頭。 */
    private const MAIN_SHEET = '在庫推移 (2026';

    private const RATIO_SHEET = '月別販売比率';

    /** 主シートの品番・SKUの明細が始まる行（5行目は見出し）。 */
    private const FIRST_ROW = 6;

    /** 月別販売比率シートの、4月～翌3月の列。 */
    private const RATIO_COLUMNS = ['C', 'H', 'M', 'R', 'W', 'AB', 'AG', 'AL', 'AQ', 'AV', 'BA', 'BF'];

    /** 月別販売比率シートの行 => 区分（2024年の行と、2025・2026年の行）。 */
    private const RATIO_ROWS = [
        5 => RatioCategoryCode::Reading, 7 => RatioCategoryCode::PcSunglasses, 9 => RatioCategoryCode::PhotochromicReading, 11 => RatioCategoryCode::ReadyMadeMyopia,
        21 => RatioCategoryCode::Reading, 23 => RatioCategoryCode::PcSunglasses, 25 => RatioCategoryCode::PhotochromicReading, 27 => RatioCategoryCode::ReadyMadeMyopia,
    ];

    /** 主シートの今の月（2026年7月～2027年3月）の月別必要数の列。品番の区分は、この列の数式が参照している比率で決める（K-049②）。 */
    private const CURRENT_DEMAND_COLUMNS = ['CI', 'CK', 'CM', 'CO', 'CQ', 'CS', 'CU', 'CW', 'CY'];

    /** 初期値に使う月別販売比率の行（2025・2026年、K-049②）。 */
    private const CURRENT_RATIO_ROWS = [21, 23, 25, 27];

    public function __construct(private XlsxReader $reader) {}

    /**
     * 区分ごとの月別販売比率（4月～翌3月、割合。合計は100%とは限らない）。
     *
     * @return array<string, list<float>> [区分の印 => 比率]
     */
    public function ratios(): array
    {
        $cells = $this->reader->cells(self::RATIO_SHEET);
        $ratios = [];
        foreach (self::CURRENT_RATIO_ROWS as $row) {
            $values = array_map(fn (string $column): float => (float) ($cells[$row][$column]['value'] ?? 0), self::RATIO_COLUMNS);
            if (array_sum($values) <= 0) {
                throw new RuntimeException("月別販売比率シートの{$row}行目に比率がありません。");
            }
            $ratios[self::RATIO_ROWS[$row]->value] = $values;
        }

        return $ratios;
    }

    /**
     * 主シートの品番ごとの年間販売想定数と、SKU（カラー・度数）ごとの年間の数。
     *
     * @return list<ExcelItemTarget>
     */
    public function items(): array
    {
        $cells = $this->reader->cells(self::MAIN_SHEET);
        ksort($cells);
        $items = [];
        $current = null;
        $color = null;
        foreach ($cells as $row => $columns) {
            if ($row < self::FIRST_ROW) {
                continue;
            }
            $value = fn (string $column): ?string => isset($columns[$column]['value']) && trim($columns[$column]['value']) !== '' ? trim($columns[$column]['value']) : null;
            if (str_starts_with($value('C') ?? '', '合計')) {
                break;
            }
            if (is_numeric($value('B')) && $value('C') !== null) {
                if ($current !== null) {
                    $items[] = $current;
                }
                $current = ['item_no' => preg_split('/[\s　＊*（(]/u', $value('C'))[0], 'annual' => is_numeric($value('H')) ? (float) $value('H') : null, 'skus' => [], 'prices' => [], 'references' => $this->ratioReferences($columns)];
                $color = null;

                continue;
            }
            if ($current === null) {
                continue;
            }
            $current['references'] = [...$current['references'], ...$this->ratioReferences($columns)];
            // カラーの欄には「3\n(廃番)」のような注記が付くことがあるため、先頭の語だけを使う
            $color = $value('D') !== null ? preg_split('/[\s　(（]/u', $value('D'))[0] : $color;
            // 単価：年間売上想定額（I列）÷年間販売想定数（H列）、無ければ年間売上額（G列）÷年間販売数（F列）
            foreach ([['I', 'H'], ['G', 'F']] as [$amountColumn, $quantityColumn]) {
                if (is_numeric($value($amountColumn)) && is_numeric($value($quantityColumn)) && (float) $value($quantityColumn) > 0 && (float) $value($amountColumn) > 0) {
                    $current['prices'][] = (int) round((float) $value($amountColumn) / (float) $value($quantityColumn));
                    break;
                }
            }
            $annual = $value('H');
            if ($color === null || $annual === null || ! is_numeric($annual)) {
                continue;
            }
            $current['skus'][] = new ExcelSkuTarget($row, $color, $value('E'), (float) $annual, ($columns['H']['formula'] ?? null) !== null);
        }
        if ($current !== null) {
            $items[] = $current;
        }

        return array_map(fn (array $item): ExcelItemTarget => new ExcelItemTarget($item['item_no'], $item['annual'], $this->mostReferenced($item['references']), $item['skus'], $this->mostCommon($item['prices'])), $items);
    }

    /**
     * 行の今の月の月別必要数の数式が参照している、月別販売比率シートの区分。
     *
     * @param  array<string, array{value: string|null, formula: string|null}>  $columns
     * @return list<RatioCategoryCode>
     */
    private function ratioReferences(array $columns): array
    {
        $references = [];
        foreach (self::CURRENT_DEMAND_COLUMNS as $column) {
            $cell = $columns[$column] ?? null;
            if ($cell === null || $cell['formula'] === null || ! preg_match_all("/月別販売比率'?!\\\$?[A-Z]+\\\$?(\\d+)/u", $cell['formula'], $matches)) {
                continue;
            }
            foreach ($matches[1] as $row) {
                if (isset(self::RATIO_ROWS[(int) $row])) {
                    $references[] = self::RATIO_ROWS[(int) $row];
                }
            }
        }

        return $references;
    }

    /**
     * @param  list<int>  $values
     */
    private function mostCommon(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        $counts = array_count_values($values);
        arsort($counts);

        return (int) array_key_first($counts);
    }

    /**
     * @param  list<RatioCategoryCode>  $references
     */
    private function mostReferenced(array $references): ?RatioCategoryCode
    {
        if ($references === []) {
            return null;
        }
        $counts = array_count_values(array_map(fn (RatioCategoryCode $category): string => $category->value, $references));
        arsort($counts);

        return RatioCategoryCode::from((string) array_key_first($counts));
    }
}
