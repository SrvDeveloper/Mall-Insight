<?php

namespace App\Services\SalesImport;

use App\Enums\ImportIssueLevel;
use App\Enums\Mall;
use App\Enums\SyncStatus;
use App\Models\SalesImport;
use App\Models\Sku;
use App\Services\ImportHistoryRetention;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Amazon全注文レポートを販売実績として登録する（バックログ B-101、決定記録 K-040）。
 *
 * - 出荷済み（Shipped）の明細だけを登録する。注文は「取込元＋注文ID」で識別し、ファイルにある注文は明細をまるごと置き換える。
 *   出荷済みでない注文（キャンセルなど）が保存済みなら、その販売実績は消える。
 * - SKUは ASIN（CrossWalker の子ASIN）で照合する。出品者SKUは使わない。見つからない ASIN は、ASIN をSKUコードにした
 *   品番に未登録のSKUとして保存し、CrossWalker に登録されたら取得のときにひも付ける。
 * - 同じ注文に同じ注文明細IDの行が複数あれば最初の行だけを取り込み、残りはエラーにする。
 * - 同じ注文の明細で購入日時が違う注文は登録も更新もしない。
 */
class AmazonOrderImporter
{
    public const SOURCE = 'amazon';

    public function __construct(private AmazonOrderReportParser $parser, private SalesLineReplacer $replacer, private ImportHistoryRetention $historyRetention) {}

    public function import(string $fileName, string $contents): SalesImport
    {
        $import = $this->importFile($fileName, $contents);
        $this->historyRetention->prune(SalesImport::query());

        return $import;
    }

    private function importFile(string $fileName, string $contents): SalesImport
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

            $issues = array_map(fn (array $issue): array => [...$issue, 'level' => ImportIssueLevel::from($issue['level'])], $parsed['issues']);
            [$lines, $rejectedOrderIds, $rejectedRowCount] = $this->checkOrders(collect($parsed['lines']), $issues);
            $skuIds = $this->ensureSkus(array_values(array_unique(array_map(fn (AmazonOrderLine $line): string => $line->asin, $lines))), $createdSkuCount);

            $rows = array_map(fn (AmazonOrderLine $line): array => [
                'source_order_id' => $line->orderId,
                'sales_date' => $line->salesDate,
                'mall' => Mall::Amazon->value,
                'sku_id' => $skuIds[$line->asin],
                'warehouse' => $line->warehouse->value,
                'quantity' => $line->quantity,
                'amount' => $line->lineAmount,
            ], $lines);
            $orderIds = array_values(array_diff($parsed['order_ids'], $rejectedOrderIds));
            $counts = $this->replacer->replace(self::SOURCE, $orderIds, $rows);

            $import->issues()->createMany(array_map(fn (array $issue): array => [...$issue, 'level' => $issue['level']->value], $issues));
            $errorRowCount = count(array_filter($parsed['issues'], fn (array $issue): bool => $issue['level'] === 'error')) + $rejectedRowCount;
            $import->update([
                ...$counts,
                'finished_at' => now(),
                'created_sku_count' => $createdSkuCount,
                'skipped_line_count' => $parsed['skipped_count'],
                'error_row_count' => $errorRowCount,
            ]);

            return $import;
        });
    }

    /**
     * 注文ごとに、重複した明細と、明細の間で購入日時が違う注文を除く。0円の明細は警告にする。
     *
     * @param  Collection<int, AmazonOrderLine>  $lines
     * @param  list<array<string, mixed>>  $issues  見つかった指摘を追加する
     * @return array{list<AmazonOrderLine>, list<string>, int} [登録する明細, 登録も更新もしない注文ID, 除いた行数]
     */
    private function checkOrders(Collection $lines, array &$issues): array
    {
        $kept = [];
        $rejectedOrderIds = [];
        $rejectedRowCount = 0;

        foreach ($lines->groupBy('orderId') as $orderId => $orderLines) {
            $unique = [];
            foreach ($orderLines as $line) {
                if (isset($unique[$line->lineId])) {
                    $rejectedRowCount++;
                    $issues[] = $this->issue(ImportIssueLevel::Error, $line, "同じ注文に同じ order-item-id（{$line->lineId}）の行が複数あります。最初の行（{$unique[$line->lineId]->rowNumber}行目）だけを取り込み、この行は取り込みませんでした。");

                    continue;
                }
                $unique[$line->lineId] = $line;
            }

            if (count(array_unique(array_map(fn (AmazonOrderLine $line): string => $line->purchasedAt, $unique))) > 1) {
                $rejectedOrderIds[] = (string) $orderId;
                $rejectedRowCount += count($unique);
                $rows = implode('・', array_map(fn (AmazonOrderLine $line): int => $line->rowNumber, $unique));
                $issues[] = $this->issue(ImportIssueLevel::Error, array_values($unique)[0], "同じ注文の明細で purchase-date が違うため、この注文（{$rows}行目）を登録・更新しませんでした。");

                continue;
            }

            foreach ($unique as $line) {
                if ($line->lineAmount === 0) {
                    $issues[] = $this->issue(ImportIssueLevel::Warning, $line, '金額が0円です。プロモーション品・同梱品などでないか確認してください。');
                }
                $kept[] = $line;
            }
        }

        return [$kept, $rejectedOrderIds, $rejectedRowCount];
    }

    /**
     * ASIN に対応するSKUを返す。CrossWalker の子ASINに無いものは、ASIN をSKUコードにした品番に未登録のSKUとして作る。
     *
     * @param  list<string>  $asins
     * @return array<string, int> [ASIN => SKU ID]
     */
    private function ensureSkus(array $asins, ?int &$createdCount): array
    {
        $createdCount = 0;
        if ($asins === []) {
            return [];
        }
        $existing = Sku::query()->whereIn('child_asin', $asins)->pluck('id', 'child_asin');
        $newAsins = array_values(array_diff($asins, $existing->keys()->all()));
        // 以前の取込で作った未登録SKU（SKUコード = ASIN）があれば、それを使う
        $placeholders = Sku::query()->whereIn('sku_code', $newAsins)->pluck('id', 'sku_code');
        $toCreate = array_values(array_diff($newAsins, $placeholders->keys()->all()));
        if ($toCreate !== []) {
            Sku::insert(array_map(fn (string $asin): array => ['sku_code' => $asin, 'child_asin' => $asin, 'created_at' => now(), 'updated_at' => now()], $toCreate));
        }
        $createdCount = count($toCreate);

        // 数字だけの ASIN でもキーが振り直されないよう、配列の和で合わせる
        return $existing->all() + Sku::query()->whereIn('sku_code', $newAsins)->pluck('id', 'sku_code')->all();
    }

    /**
     * @return array{level: ImportIssueLevel, row_number: int, source_order_id: string, sku_code: string, message: string}
     */
    private function issue(ImportIssueLevel $level, AmazonOrderLine $line, string $message): array
    {
        return ['level' => $level, 'row_number' => $line->rowNumber, 'source_order_id' => $line->orderId, 'sku_code' => $line->asin, 'message' => $message];
    }
}
