<?php

namespace App\Services\SalesImport;

use App\Enums\Mall;
use App\Enums\Warehouse;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

/**
 * BOSS受注実績一覧のCSV（Shift_JIS）を読み取り、行ごとに検証する。
 * 旧設計書：docs/documents/販売実績取込要件/02.BOSS受注実績取込要件.md
 *
 * 個人情報（注文者・送付先の氏名・住所・電話番号、備考など）の列は読み取らない。
 */
class BossOrderCsvParser
{
    /**
     * 必要な列。値が必須かどうかは列ごとに判定する（税込別だけは空欄を許す）。
     * 保存しない列（商品名・モール注文番号など）は、空欄でも取り込みに影響しないため含めない（決定記録 K-024）。
     */
    private const REQUIRED_COLUMNS = ['注文ID', 'ショップID', 'モール注文日時', '注文ステータス', '商品合計金額', '商品ID', 'SKUコード', '注文個数', '商品単価', '消費税', '税込別', '倉庫'];

    private const OPTIONAL_VALUE_COLUMNS = ['税込別'];

    private const COMPLETED_STATUS = '完了';

    /**
     * @return array{lines: list<BossOrderLine>, issues: list<array{row_number: int, source_order_id: ?string, sku_code: ?string, message: string}>, row_count: int}
     *
     * @throws SalesImportFileException
     */
    public function parse(string $contents): array
    {
        $records = $this->readRecords($this->toUtf8($contents));
        $header = array_map(fn (string $name): string => trim($name), array_shift($records) ?? []);

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, $header));
        if ($missing !== []) {
            throw new SalesImportFileException('必要な列がありません：'.implode('、', $missing).'。BOSS受注実績一覧から出力したCSVか確認してください。');
        }
        if ($records === []) {
            throw new SalesImportFileException('データの行がありません。');
        }

        $columnIndex = array_flip($header);
        $lines = [];
        $issues = [];
        foreach ($records as $index => $record) {
            $rowNumber = $index + 2;
            $values = [];
            foreach (self::REQUIRED_COLUMNS as $column) {
                $values[$column] = trim($record[$columnIndex[$column]] ?? '');
            }

            $error = $this->validate($values);
            if ($error !== null) {
                $issues[] = ['row_number' => $rowNumber, 'source_order_id' => $values['注文ID'] ?: null, 'sku_code' => $values['SKUコード'] ?: null, 'message' => $error];

                continue;
            }

            $lines[] = $this->toLine($rowNumber, $values);
        }

        return ['lines' => $lines, 'issues' => $issues, 'row_count' => count($records)];
    }

    private function toUtf8(string $contents): string
    {
        if ($contents === '') {
            throw new SalesImportFileException('ファイルが空です。');
        }
        $hasMultibyte = preg_match('/[\x80-\xFF]/', $contents) === 1;
        if ($hasMultibyte && mb_check_encoding($contents, 'UTF-8')) {
            throw new SalesImportFileException('ファイルがUTF-8で保存されています。BOSSから出力したCSV（Shift_JIS）を、Excelなどで保存し直さずにそのまま取り込んでください。');
        }
        if (! mb_check_encoding($contents, 'SJIS-win')) {
            throw new SalesImportFileException('ファイルの文字コードをShift_JISとして読み取れません。BOSSから出力したCSVをそのまま取り込んでください。');
        }

        return mb_convert_encoding($contents, 'UTF-8', 'SJIS-win');
    }

    /**
     * @return list<list<string>>
     */
    private function readRecords(string $utf8): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $utf8);
        rewind($stream);
        $records = [];
        while (($record = fgetcsv($stream, escape: '')) !== false) {
            if ($record === [null]) {
                continue;
            }
            $records[] = array_map(fn (?string $value): string => (string) $value, $record);
        }
        fclose($stream);

        return $records;
    }

    /**
     * 行を検証し、取り込めない理由を返す。取り込めるなら null。
     *
     * @param  array<string, string>  $values
     */
    private function validate(array $values): ?string
    {
        if ($values['注文ステータス'] !== self::COMPLETED_STATUS) {
            return "注文ステータスが「完了」ではありません（{$values['注文ステータス']}）。BOSSから「完了」の受注実績だけを出力してください。";
        }
        foreach (self::REQUIRED_COLUMNS as $column) {
            if ($values[$column] === '' && ! in_array($column, self::OPTIONAL_VALUE_COLUMNS, true)) {
                return "「{$column}」が空欄です。";
            }
        }
        if (Mall::fromBossShopId($values['ショップID']) === null) {
            return "ショップID「{$values['ショップID']}」に対応するモールがありません。";
        }
        if ($this->toWarehouse($values['倉庫']) === null) {
            return "倉庫「{$values['倉庫']}」から出荷倉庫を判定できません（「RFC倉庫」か「自社倉庫」である必要があります）。";
        }
        foreach (['商品合計金額', '注文個数', '商品単価', '消費税'] as $column) {
            if (preg_match('/^-?\d+$/', $values[$column]) !== 1) {
                return "「{$column}」が整数ではありません（{$values[$column]}）。";
            }
        }
        if ((int) $values['注文個数'] < 1) {
            return "注文個数が1以上ではありません（{$values['注文個数']}）。";
        }
        if ($this->toDateTime($values['モール注文日時']) === null) {
            return "モール注文日時「{$values['モール注文日時']}」を日時として読み取れません。";
        }

        return null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function toLine(int $rowNumber, array $values): BossOrderLine
    {
        $quantity = (int) $values['注文個数'];
        // 税込別が 1 なら商品単価は税込、それ以外（空欄を含む）は税別として1点当たりの消費税を足す
        $unitPrice = $values['税込別'] === '1' ? (int) $values['商品単価'] : (int) $values['商品単価'] + (int) $values['消費税'];

        return new BossOrderLine(
            rowNumber: $rowNumber,
            orderId: $values['注文ID'],
            mall: Mall::fromBossShopId($values['ショップID']),
            orderedAt: $this->toDateTime($values['モール注文日時']),
            itemTotal: (int) $values['商品合計金額'],
            lineId: $values['商品ID'],
            skuCode: $values['SKUコード'],
            quantity: $quantity,
            lineAmount: $unitPrice * $quantity,
            warehouse: $this->toWarehouse($values['倉庫']),
        );
    }

    private function toWarehouse(string $value): ?Warehouse
    {
        return match ($value) {
            'RFC倉庫' => Warehouse::BossRfc,
            '自社倉庫' => Warehouse::BossOwn,
            default => null,
        };
    }

    /**
     * 「2026/08/18 2:23:17」のような日時を日本時間として読み取る（時・月・日はゼロ埋めの有無を問わない）。
     */
    private function toDateTime(string $value): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y/n/j G:i:s', $value, 'Asia/Tokyo') ?: null;
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
