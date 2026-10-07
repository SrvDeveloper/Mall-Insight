<?php

namespace Tests\Feature\Services\SalesImport;

use App\Enums\ImportIssueLevel;
use App\Enums\Mall;
use App\Enums\SyncStatus;
use App\Enums\Warehouse;
use App\Models\Item;
use App\Models\SalesImport;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Services\SalesImport\BossOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BossOrderImporterTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['注文ID', 'ショップID', 'モール注文番号', 'モール注文日時', '注文ステータス', '注文者氏名', '注文者電話番号', '商品合計金額', '消費税合計', '倉庫', '送付先住所', 'SKUコード', '商品ID', '商品名', '注文個数', '商品単価', '消費税', '税込別'];

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [
            '注文ID' => '223329014', 'ショップID' => '67419', 'モール注文番号' => 'R-0001', 'モール注文日時' => '2026/08/18 2:23:17', '注文ステータス' => '完了',
            '注文者氏名' => '個人 太郎', '注文者電話番号' => '090-0000-0000', '商品合計金額' => '3960', '消費税合計' => '360', '倉庫' => '自社倉庫', '送付先住所' => '東京都千代田区1-1',
            'SKUコード' => 'fll-006-2-20', '商品ID' => '71032-00000580', '商品名' => '老眼鏡 fll-006', '注文個数' => '1', '商品単価' => '3960', '消費税' => '360', '税込別' => '1',
            ...$overrides,
        ];
    }

    /**
     * 見出し＋行を BOSS と同じ Shift_JIS のCSVにする。
     *
     * @param  list<array<string, string>>  $rows
     */
    private function csv(array $rows, array $header = self::HEADER): string
    {
        $lines = [implode(',', $header)];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(fn (string $column): string => '"'.str_replace('"', '""', $row[$column] ?? '').'"', $header));
        }

        return mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'SJIS-win', 'UTF-8');
    }

    private function import(string $contents, string $fileName = 'BOSS受注実績.csv'): SalesImport
    {
        return app(BossOrderImporter::class)->import($fileName, $contents);
    }

    public function test_registers_orders_and_lines_in_japan_time_with_tax_included_amounts(): void
    {
        $item = Item::factory()->create(['item_no' => 'fll-006']);
        $registered = Sku::factory()->for($item)->create(['sku_code' => 'fll-006-2-20']);

        $import = $this->import($this->csv([
            $this->row(),
            $this->row(['注文ID' => '223329015', 'ショップID' => '67572', 'モール注文日時' => '2026/08/25 09:56:23', '商品合計金額' => '8800', '消費税合計' => '800', '倉庫' => 'RFC倉庫', 'SKUコード' => 'afc20', '商品ID' => '71032-00000687', '注文個数' => '2', '商品単価' => '4400', '消費税' => '400']),
        ]));

        $rakuten = SalesLine::query()->where('source_order_id', '223329014')->sole();
        $this->assertSame(['boss', '2026-08-18', Mall::Rakuten, $registered->id, Warehouse::BossOwn, 1, 3960], [$rakuten->source, $rakuten->sales_date->toDateString(), $rakuten->mall, $rakuten->sku_id, $rakuten->warehouse, $rakuten->quantity, $rakuten->amount]);

        $yahoo = SalesLine::query()->where('source_order_id', '223329015')->sole();
        $this->assertSame(['2026-08-25', Mall::Yahoo, Warehouse::BossRfc, 2, 8800], [$yahoo->sales_date->toDateString(), $yahoo->mall, $yahoo->warehouse, $yahoo->quantity, $yahoo->amount]);
        $this->assertDatabaseHas('skus', ['id' => $yahoo->sku_id, 'sku_code' => 'afc20', 'item_id' => null]);

        $this->assertSame(SyncStatus::Succeeded, $import->status);
        $this->assertSame([2, 2, 0, 0, 0, 1, '2026-08-18', '2026-08-25'], [$import->row_count, $import->created_line_count, $import->updated_line_count, $import->skipped_line_count, $import->error_row_count, $import->created_sku_count, $import->sales_date_from->toDateString(), $import->sales_date_to->toDateString()]);
        $this->assertSame(0, $import->issues()->count());
    }

    public function test_reimporting_the_same_file_overwrites_instead_of_doubling(): void
    {
        $contents = $this->csv([$this->row()]);
        $this->import($contents);

        $second = $this->import($contents);

        $this->assertSame(1, SalesLine::query()->count());
        $this->assertSame([0, 1, 0], [$second->created_line_count, $second->updated_line_count, $second->created_sku_count]);
    }

    public function test_adds_tax_to_the_unit_price_when_it_is_not_tax_included(): void
    {
        $this->import($this->csv([$this->row(['税込別' => '0', '商品単価' => '3600', '消費税' => '360'])]));

        $this->assertSame(3960, SalesLine::query()->sole()->amount);
    }

    public function test_skips_invalid_rows_with_a_reason_and_registers_the_rest(): void
    {
        $import = $this->import($this->csv([
            $this->row(['注文ID' => '1', '注文ステータス' => 'キャンセル']),
            $this->row(['注文ID' => '2', 'ショップID' => '99999']),
            $this->row(['注文ID' => '3', '倉庫' => 'FBA倉庫']),
            $this->row(['注文ID' => '4', 'SKUコード' => '']),
            $this->row(['注文ID' => '5', 'モール注文日時' => '2026-08-18']),
            $this->row(['注文ID' => '6']),
        ]));

        $this->assertSame(['6'], SalesLine::query()->pluck('source_order_id')->all());
        $this->assertSame(5, $import->error_row_count);
        $this->assertSame([
            [2, '注文ステータスが「完了」ではありません（キャンセル）。BOSSから「完了」の受注実績だけを出力してください。'],
            [3, 'ショップID「99999」に対応するモールがありません。'],
            [4, '倉庫「FBA倉庫」から出荷倉庫を判定できません（「RFC倉庫」か「自社倉庫」である必要があります）。'],
            [5, '「SKUコード」が空欄です。'],
            [6, 'モール注文日時「2026-08-18」を日時として読み取れません。'],
        ], $import->issues->map(fn ($issue): array => [$issue->row_number, $issue->message])->all());
        $this->assertTrue($import->issues->every(fn ($issue): bool => $issue->level === ImportIssueLevel::Error));
    }

    public function test_keeps_only_the_first_of_duplicate_lines_and_warns(): void
    {
        $import = $this->import($this->csv([
            $this->row(),
            $this->row(['倉庫' => 'RFC倉庫']),
        ]));

        $this->assertSame(Warehouse::BossOwn, SalesLine::query()->sole()->warehouse);
        $this->assertSame(1, $import->skipped_line_count);
        $issue = $import->issues()->sole();
        $this->assertSame([ImportIssueLevel::Warning, 3], [$issue->level, $issue->row_number]);
        $this->assertSame('同じ注文に同じ商品ID（71032-00000580）の行が複数あります。最初の行（2行目）だけを取り込み、この行は取り込みませんでした。', $issue->message);
    }

    public function test_does_not_register_an_order_whose_item_total_differs_from_its_lines(): void
    {
        $import = $this->import($this->csv([
            $this->row(['商品合計金額' => '9999']),
            $this->row(['商品合計金額' => '9999', '商品ID' => '71032-00000581', 'SKUコード' => 'fll-006-2-25']),
            $this->row(['注文ID' => '7']),
        ]));

        $this->assertSame(['7'], SalesLine::query()->pluck('source_order_id')->all());
        $this->assertSame(2, $import->error_row_count);
        $this->assertSame('商品合計金額（9999円）と明細の合計（7920円）が一致しないため、この注文（2・3行目）を登録しませんでした。', $import->issues()->sole()->message);
    }

    public function test_warns_about_zero_yen_lines_but_registers_them(): void
    {
        $import = $this->import($this->csv([$this->row(['商品合計金額' => '0', '消費税合計' => '0', '商品単価' => '0', '消費税' => '0'])]));

        $this->assertSame(1, SalesLine::query()->count());
        $this->assertSame('商品単価が0円です。プレゼント品・同梱品などでないか確認してください。', $import->issues()->sole()->message);
    }

    public function test_registers_rows_whose_unstored_columns_are_blank(): void
    {
        $this->import($this->csv([$this->row(['商品名' => '', 'モール注文番号' => ''])]));

        $this->assertSame(1, SalesLine::query()->count());
    }

    public function test_never_stores_personal_information(): void
    {
        $this->import($this->csv([$this->row()]));

        $stored = json_encode(SalesLine::query()->get()->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['個人 太郎', '090-0000-0000', '東京都千代田区'] as $personal) {
            $this->assertStringNotContainsString($personal, $stored);
        }
    }

    public function test_rejects_a_file_saved_as_utf8(): void
    {
        $utf8 = mb_convert_encoding($this->csv([$this->row()]), 'UTF-8', 'SJIS-win');

        $import = $this->import($utf8);

        $this->assertSame(SyncStatus::Failed, $import->status);
        $this->assertStringStartsWith('ファイルがUTF-8で保存されています。', $import->error_message);
        $this->assertSame(0, SalesLine::query()->count());
    }

    public function test_rejects_a_file_missing_required_columns(): void
    {
        $header = array_values(array_diff(self::HEADER, ['倉庫', '税込別']));

        $import = $this->import($this->csv([$this->row()], $header));

        $this->assertSame('必要な列がありません：税込別、倉庫。BOSS受注実績一覧から出力したCSVか確認してください。', $import->error_message);
    }
}
