<?php

namespace App\Services\SalesImport;

use App\Enums\Warehouse;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * Amazon全注文レポート（タブ区切りのテキスト）を読み取り、行ごとに検証する（バックログ B-101、決定記録 K-040）。
 * 旧設計書：docs/documents/販売実績取込要件/03.Amazon全注文レポート取込要件.md
 *
 * 配送先（ship-city など）や商品名の列は読み取らない。
 */
class AmazonOrderReportParser
{
    /** 必要な列。保存しない列（商品名・出品者SKUなど）は含めない（決定記録 K-024）。 */
    private const REQUIRED_COLUMNS = ['amazon-order-id', 'purchase-date', 'order-status', 'fulfillment-channel', 'sales-channel', 'order-item-id', 'asin', 'quantity', 'item-price'];

    private const OPTIONAL_COLUMNS = ['item-status'];

    /** 出荷済みの注文の中でキャンセルされた商品。金額が空欄でも誤りではなく、販売実績にしない（決定記録 K-041）。 */
    private const CANCELLED_ITEM_STATUSES = ['Cancelled', 'Canceled'];

    /** 出荷済みの注文だけを販売実績にする。 */
    private const SHIPPED_STATUS = 'Shipped';

    /** Amazon 以外のチャネルの注文を FBA から出荷したもの（マルチチャネル出荷）。販売実績にしない。 */
    private const MULTI_CHANNEL = 'Non-Amazon';

    private const AMAZON_CHANNEL = 'Amazon.co.jp';

    /**
     * レポートの1行目（列名）か。取込元の判定に使う。
     */
    public static function looksLikeReport(string $contents): bool
    {
        $firstLine = strtok(ltrim($contents, "\xEF\xBB\xBF"), "\n");

        return $firstLine !== false && str_contains($firstLine, 'amazon-order-id') && str_contains($firstLine, "\t");
    }

    /**
     * @return array{lines: list<AmazonOrderLine>, issues: list<array{level: string, row_number: int, source_order_id: ?string, sku_code: ?string, message: string}>, row_count: int, order_ids: list<string>, skipped_count: int}
     *
     * @throws SalesImportFileException
     */
    public function parse(string $contents): array
    {
        $text = $this->toUtf8($contents);
        $lines = preg_split('/\r\n|\n|\r/', $text);
        $header = array_map('trim', explode("\t", array_shift($lines) ?? ''));
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));
        if ($missing !== []) {
            throw new SalesImportFileException('必要な列がありません：'.implode('、', $missing).'。Amazonの全注文レポートか確認してください。');
        }
        $columnIndex = array_flip($header);

        $result = [];
        $issues = [];
        $orderIds = [];
        $rowCount = 0;
        $skippedCount = 0;
        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }
            $rowCount++;
            $rowNumber = $index + 2;
            $record = explode("\t", $line);
            $values = [];
            foreach ([...self::REQUIRED_COLUMNS, ...self::OPTIONAL_COLUMNS] as $column) {
                $values[$column] = isset($columnIndex[$column]) ? trim($record[$columnIndex[$column]] ?? '') : '';
            }

            if ($values['sales-channel'] === self::MULTI_CHANNEL) {
                $skippedCount++;

                continue;
            }
            if ($values['sales-channel'] !== self::AMAZON_CHANNEL) {
                $issues[] = $this->issue('warning', $rowNumber, $values, "sales-channel が想定外の値です（{$values['sales-channel']}）。Amazon の販売として扱いました。");
            }
            if ($values['amazon-order-id'] === '') {
                $issues[] = $this->issue('error', $rowNumber, $values, '「amazon-order-id」が空欄です。');

                continue;
            }
            // 出荷済みでない注文も、保存済みなら取り消す（キャンセルされた注文を販売実績から外す）ため、注文IDを覚えておく
            $orderIds[$values['amazon-order-id']] = true;
            if ($values['order-status'] !== self::SHIPPED_STATUS || in_array($values['item-status'], self::CANCELLED_ITEM_STATUSES, true)) {
                $skippedCount++;

                continue;
            }

            $error = $this->validate($values);
            if ($error !== null) {
                $issues[] = $this->issue('error', $rowNumber, $values, $error);

                continue;
            }
            $result[] = $this->toLine($rowNumber, $values);
        }

        if ($rowCount === 0) {
            throw new SalesImportFileException('データの行がありません。');
        }

        return ['lines' => $result, 'issues' => $issues, 'row_count' => $rowCount, 'order_ids' => array_map('strval', array_keys($orderIds)), 'skipped_count' => $skippedCount];
    }

    /**
     * UTF-8（BOM付きを含む）として正しければそのまま、そうでなければ Shift_JIS（CP932）として読む。
     */
    private function toUtf8(string $contents): string
    {
        if ($contents === '') {
            throw new SalesImportFileException('ファイルが空です。');
        }
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }
        if (mb_check_encoding($contents, 'UTF-8')) {
            return $contents;
        }
        if (! mb_check_encoding($contents, 'SJIS-win')) {
            throw new SalesImportFileException('ファイルの文字コードを読み取れません。Amazonからダウンロードしたファイルをそのまま取り込んでください。');
        }

        return mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function validate(array $values): ?string
    {
        foreach (['order-item-id', 'asin', 'purchase-date', 'quantity', 'item-price', 'fulfillment-channel'] as $column) {
            if ($values[$column] === '') {
                return "「{$column}」が空欄です。";
            }
        }
        if (preg_match('/^\d+$/', $values['quantity']) !== 1 || (int) $values['quantity'] < 1) {
            return "quantity が1以上の整数ではありません（{$values['quantity']}）。";
        }
        if (! is_numeric($values['item-price'])) {
            return "item-price が数値ではありません（{$values['item-price']}）。";
        }
        if ($this->toWarehouse($values['fulfillment-channel']) === null) {
            return "fulfillment-channel「{$values['fulfillment-channel']}」から出荷倉庫を判定できません（Amazon か Merchant である必要があります）。";
        }
        if ($this->toDateTime($values['purchase-date']) === null) {
            return "purchase-date「{$values['purchase-date']}」を日時として読み取れません。";
        }

        return null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function toLine(int $rowNumber, array $values): AmazonOrderLine
    {
        return new AmazonOrderLine(
            rowNumber: $rowNumber,
            orderId: $values['amazon-order-id'],
            lineId: $values['order-item-id'],
            purchasedAt: $values['purchase-date'],
            salesDate: $this->toDateTime($values['purchase-date'])->setTimezone('Asia/Tokyo')->toDateString(),
            asin: $values['asin'],
            quantity: (int) $values['quantity'],
            // item-price は税込の明細金額（数量分の合計）。値引きは基本的に設定されないため使わない（K-040）
            lineAmount: (int) round((float) $values['item-price']),
            warehouse: $this->toWarehouse($values['fulfillment-channel']),
        );
    }

    private function toWarehouse(string $value): ?Warehouse
    {
        return match (strtoupper($value)) {
            'AMAZON', 'AFN' => Warehouse::AmazonFba,
            'MERCHANT', 'MFN' => Warehouse::AmazonOwn,
            default => null,
        };
    }

    /**
     * 「2026-08-26T14:29:25+09:00」のようなタイムゾーン付きの日時。日本時間に直してから日付を取る。
     */
    private function toDateTime(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:?\d{2})$/', $value) !== 1) {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }

    /**
     * @param  array<string, string>  $values
     * @return array{level: string, row_number: int, source_order_id: ?string, sku_code: ?string, message: string}
     */
    private function issue(string $level, int $rowNumber, array $values, string $message): array
    {
        return ['level' => $level, 'row_number' => $rowNumber, 'source_order_id' => $values['amazon-order-id'] ?: null, 'sku_code' => $values['asin'] ?: null, 'message' => $message];
    }
}
