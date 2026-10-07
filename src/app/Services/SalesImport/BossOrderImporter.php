<?php

namespace App\Services\SalesImport;

use App\Enums\ImportIssueLevel;
use App\Enums\SyncStatus;
use App\Models\SalesImport;
use App\Models\SalesLine;
use App\Models\Sku;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BOSS受注実績を販売実績として登録する（バックログ B-004、決定記録 K-022）。
 *
 * - 注文は「取込元＋注文ID」で識別し、取り込み直すとその注文の明細をまるごと置き換える（二重にならない）。
 * - 保存するのは販売日・モール・SKU・出荷倉庫・数量・金額だけ（決定記録 K-024）。
 * - 同じ注文に同じ商品IDの行が複数あれば、最初の行だけを取り込み、残りは警告にする。
 * - 商品合計金額と明細の合計が一致しない注文は登録しない。
 * - CrossWalker に無いSKUは、品番に未登録のSKUとして保存する。
 */
class BossOrderImporter
{
    public const SOURCE = 'boss';

    public function __construct(private BossOrderCsvParser $parser) {}

    public function import(string $fileName, string $contents): SalesImport
    {
        $startedAt = now();

        try {
            $parsed = $this->parser->parse($contents);
        } catch (SalesImportFileException $exception) {
            return SalesImport::create([
                'source' => self::SOURCE,
                'file_name' => $fileName,
                'status' => SyncStatus::Failed,
                'started_at' => $startedAt,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ]);
        }

        return DB::transaction(function () use ($fileName, $parsed, $startedAt): SalesImport {
            $import = SalesImport::create([
                'source' => self::SOURCE,
                'file_name' => $fileName,
                'status' => SyncStatus::Succeeded,
                'started_at' => $startedAt,
                'finished_at' => $startedAt,
                'row_count' => $parsed['row_count'],
            ]);

            $issues = array_map(fn (array $issue): array => [...$issue, 'level' => ImportIssueLevel::Error], $parsed['issues']);
            [$orders, $skippedLineCount, $rejectedRowCount] = $this->groupOrders(collect($parsed['lines']), $issues);
            $counts = $this->store($orders);

            $import->issues()->createMany(array_map(fn (array $issue): array => [...$issue, 'level' => $issue['level']->value], $issues));
            $import->update([
                ...$counts,
                'finished_at' => now(),
                'skipped_line_count' => $skippedLineCount,
                'error_row_count' => count($parsed['issues']) + $rejectedRowCount,
            ]);

            return $import;
        });
    }

    /**
     * 行を注文ごとにまとめ、重複明細と金額不一致の注文を除く。
     *
     * @param  Collection<int, BossOrderLine>  $lines
     * @param  list<array<string, mixed>>  $issues  見つかった指摘を追加する
     * @return array{array<string, list<BossOrderLine>>, int, int} [注文ID => 明細, 重複で除いた行数, 金額不一致で除いた行数]
     */
    private function groupOrders(Collection $lines, array &$issues): array
    {
        $orders = [];
        $skipped = 0;
        $rejectedRows = 0;

        foreach ($lines->groupBy('orderId') as $orderId => $orderLines) {
            $kept = [];
            foreach ($orderLines as $line) {
                if (isset($kept[$line->lineId])) {
                    $skipped++;
                    $issues[] = $this->issue(ImportIssueLevel::Warning, $line, "同じ注文に同じ商品ID（{$line->lineId}）の行が複数あります。最初の行（{$kept[$line->lineId]->rowNumber}行目）だけを取り込み、この行は取り込みませんでした。");

                    continue;
                }
                $kept[$line->lineId] = $line;
            }
            $kept = array_values($kept);

            $itemTotal = $kept[0]->itemTotal;
            $lineTotal = array_sum(array_map(fn (BossOrderLine $line): int => $line->lineAmount, $kept));
            if ($itemTotal !== $lineTotal) {
                $rejectedRows += count($kept);
                $rows = implode('・', array_map(fn (BossOrderLine $line): int => $line->rowNumber, $kept));
                $issues[] = $this->issue(ImportIssueLevel::Error, $kept[0], "商品合計金額（{$itemTotal}円）と明細の合計（{$lineTotal}円）が一致しないため、この注文（{$rows}行目）を登録しませんでした。", sku: false);

                continue;
            }

            foreach ($kept as $line) {
                if ($line->lineAmount === 0) {
                    $issues[] = $this->issue(ImportIssueLevel::Warning, $line, '商品単価が0円です。プレゼント品・同梱品などでないか確認してください。');
                }
            }
            $orders[(string) $orderId] = $kept;
        }

        return [$orders, $skipped, $rejectedRows];
    }

    /**
     * @param  array<string, list<BossOrderLine>>  $orders
     * @return array{created_line_count: int, updated_line_count: int, created_sku_count: int, sales_date_from: ?string, sales_date_to: ?string}
     */
    private function store(array $orders): array
    {
        if ($orders === []) {
            return ['created_line_count' => 0, 'updated_line_count' => 0, 'created_sku_count' => 0, 'sales_date_from' => null, 'sales_date_to' => null];
        }

        $allLines = array_merge(...array_values($orders));
        $skuIds = $this->ensureSkus(array_values(array_unique(array_map(fn (BossOrderLine $line): string => $line->skuCode, $allLines))), $createdSkuCount);

        // 取り込み直した注文は、ファイルの明細でまるごと置き換える
        $orderIds = array_map('strval', array_keys($orders));
        $existingLineCount = 0;
        foreach (array_chunk($orderIds, 1000) as $chunk) {
            $existingLineCount += SalesLine::query()->where('source', self::SOURCE)->whereIn('source_order_id', $chunk)->delete();
        }

        $rows = array_map(fn (BossOrderLine $line): array => [
            'source' => self::SOURCE,
            'source_order_id' => $line->orderId,
            'sales_date' => $line->salesDate,
            'mall' => $line->mall->value,
            'sku_id' => $skuIds[$line->skuCode],
            'warehouse' => $line->warehouse->value,
            'quantity' => $line->quantity,
            'amount' => $line->lineAmount,
        ], $allLines);
        foreach (array_chunk($rows, 1000) as $chunk) {
            SalesLine::insert($chunk);
        }

        $salesDates = array_column($rows, 'sales_date');

        return [
            'created_line_count' => max(0, count($rows) - $existingLineCount),
            'updated_line_count' => min($existingLineCount, count($rows)),
            'created_sku_count' => $createdSkuCount,
            'sales_date_from' => min($salesDates),
            'sales_date_to' => max($salesDates),
        ];
    }

    /**
     * SKUコードに対応するSKUを返す。無いものは品番に未登録のSKUとして作る。
     *
     * @param  list<string>  $skuCodes
     * @return Collection<string, int>
     */
    private function ensureSkus(array $skuCodes, ?int &$createdCount): Collection
    {
        $existing = Sku::query()->whereIn('sku_code', $skuCodes)->pluck('id', 'sku_code');
        $newCodes = array_values(array_diff($skuCodes, $existing->keys()->all()));
        $createdCount = count($newCodes);
        if ($newCodes === []) {
            return $existing;
        }
        Sku::insert(array_map(fn (string $code): array => ['sku_code' => $code, 'created_at' => now(), 'updated_at' => now()], $newCodes));

        return Sku::query()->whereIn('sku_code', $skuCodes)->pluck('id', 'sku_code');
    }

    /**
     * @return array{level: ImportIssueLevel, row_number: int, source_order_id: string, sku_code: ?string, message: string}
     */
    private function issue(ImportIssueLevel $level, BossOrderLine $line, string $message, bool $sku = true): array
    {
        return ['level' => $level, 'row_number' => $line->rowNumber, 'source_order_id' => $line->orderId, 'sku_code' => $sku ? $line->skuCode : null, 'message' => $message];
    }
}
