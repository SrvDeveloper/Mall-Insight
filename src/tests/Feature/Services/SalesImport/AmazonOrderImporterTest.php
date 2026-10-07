<?php

namespace Tests\Feature\Services\SalesImport;

use App\Enums\ImportIssueLevel;
use App\Enums\Mall;
use App\Enums\SyncStatus;
use App\Enums\Warehouse;
use App\Models\SalesImport;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Services\SalesImport\AmazonOrderImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AmazonOrderImporterTest extends TestCase
{
    use RefreshDatabase;

    /** Seller Central の全注文レポートと同じ列。order-item-id の列名の末尾には空白が付くことがある。 */
    private const HEADER = ['amazon-order-id', 'merchant-order-id', 'purchase-date', 'last-updated-date', 'order-status', 'fulfillment-channel', 'sales-channel', 'order-channel', 'url', 'ship-service-level', 'product-name', 'sku', 'asin', 'item-status', 'quantity', 'currency', 'item-price', 'item-tax', 'shipping-price', 'shipping-tax', 'gift-wrap-price', 'gift-wrap-tax', 'item-promotion-discount', 'ship-promotion-discount', 'ship-city', 'ship-state', 'ship-postal-code', 'ship-country', 'promotion-ids', 'order-item-id '];

    protected function setUp(): void
    {
        parent::setUp();

        Sku::factory()->create(['sku_code' => 'fisi-05-1-10', 'child_asin' => 'B0FISI0510']);
        Sku::factory()->create(['sku_code' => 'fisi-05-1-15', 'child_asin' => 'B0FISI0515']);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function row(array $overrides = []): array
    {
        return [
            'amazon-order-id' => '503-0000001-0000001', 'merchant-order-id' => '', 'purchase-date' => '2026-08-26T14:29:25+09:00', 'last-updated-date' => '2026-08-27T10:00:00+09:00',
            'order-status' => 'Shipped', 'fulfillment-channel' => 'Amazon', 'sales-channel' => 'Amazon.co.jp', 'order-channel' => '', 'url' => '', 'ship-service-level' => 'Expedited',
            'product-name' => '老眼鏡 SHIORI', 'sku' => 'FISI05-1 +1.00', 'asin' => 'B0FISI0510', 'item-status' => 'Shipped', 'quantity' => '1', 'currency' => 'JPY',
            'item-price' => '4207.0', 'item-tax' => '382.0', 'shipping-price' => '0.0', 'shipping-tax' => '0.0', 'gift-wrap-price' => '', 'gift-wrap-tax' => '',
            'item-promotion-discount' => '', 'ship-promotion-discount' => '', 'ship-city' => '千代田区', 'ship-state' => '東京都', 'ship-postal-code' => '100-0001', 'ship-country' => 'JP',
            'promotion-ids' => '', 'order-item-id ' => '10000000000001',
            ...$overrides,
        ];
    }

    /**
     * 見出し＋行をタブ区切りのテキストにする（既定は Shift_JIS）。
     *
     * @param  list<array<string, string>>  $rows
     */
    private function report(array $rows, string $encoding = 'SJIS-win', array $header = self::HEADER): string
    {
        $lines = [implode("\t", $header)];
        foreach ($rows as $row) {
            $lines[] = implode("\t", array_map(fn (string $column): string => $row[$column] ?? '', $header));
        }
        $text = implode("\r\n", $lines)."\r\n";

        return $encoding === 'UTF-8' ? $text : mb_convert_encoding($text, $encoding, 'UTF-8');
    }

    private function import(string $contents): SalesImport
    {
        return app(AmazonOrderImporter::class)->import('Amazon全注文レポート.txt', $contents);
    }

    public function test_registers_shipped_lines_by_asin_in_japan_time_with_the_tax_included_price(): void
    {
        $import = $this->import($this->report([
            $this->row(),
            $this->row(['amazon-order-id' => '503-0000002-0000002', 'purchase-date' => '2026-08-26T15:30:00Z', 'fulfillment-channel' => 'Merchant', 'asin' => 'B0FISI0515', 'quantity' => '2', 'item-price' => '8414.0', 'order-item-id ' => '10000000000002']),
        ]));

        $this->assertSame(SyncStatus::Succeeded, $import->status);
        $this->assertSame([2, 2, 0, 0, 0], [$import->row_count, $import->created_line_count, $import->updated_line_count, $import->error_row_count, $import->created_sku_count]);
        $lines = SalesLine::query()->orderBy('source_order_id')->get();
        $this->assertSame(['amazon', '503-0000001-0000001', '2026-08-26', Mall::Amazon, Warehouse::AmazonFba, 1, 4207, 'fisi-05-1-10'], [$lines[0]->source, $lines[0]->source_order_id, $lines[0]->sales_date->toDateString(), $lines[0]->mall, $lines[0]->warehouse, $lines[0]->quantity, $lines[0]->amount, $lines[0]->sku->sku_code]);
        // UTC の 15:30 は日本時間の翌日 0:30
        $this->assertSame(['2026-08-27', Warehouse::AmazonOwn, 2, 8414, 'fisi-05-1-15'], [$lines[1]->sales_date->toDateString(), $lines[1]->warehouse, $lines[1]->quantity, $lines[1]->amount, $lines[1]->sku->sku_code]);
        $this->assertSame(['2026-08-26', '2026-08-27'], [$import->sales_date_from->toDateString(), $import->sales_date_to->toDateString()]);
    }

    public function test_reads_utf8_reports_with_or_without_bom(): void
    {
        $this->assertSame(1, $this->import($this->report([$this->row()], 'UTF-8'))->created_line_count);
        $this->assertSame(1, $this->import("\xEF\xBB\xBF".$this->report([$this->row(['amazon-order-id' => '503-0000009-0000009'])], 'UTF-8'))->created_line_count);
    }

    public function test_skips_unshipped_and_multi_channel_orders_and_removes_orders_that_were_cancelled_later(): void
    {
        $this->import($this->report([$this->row()]));

        $import = $this->import($this->report([
            $this->row(['order-status' => 'Cancelled', 'item-status' => 'Cancelled', 'quantity' => '0', 'item-price' => '']),
            $this->row(['amazon-order-id' => '503-0000002-0000002', 'order-status' => 'Pending', 'item-price' => '']),
            $this->row(['amazon-order-id' => '503-0000003-0000003', 'order-status' => 'Shipping']),
            $this->row(['amazon-order-id' => 'S01-0000004-0000004', 'sales-channel' => 'Non-Amazon', 'item-price' => '']),
        ]));

        $this->assertSame([4, 0, 4, 0], [$import->row_count, $import->created_line_count, $import->skipped_line_count, $import->error_row_count]);
        $this->assertSame(0, SalesLine::count());
    }

    public function test_skips_cancelled_items_in_shipped_orders_without_an_error(): void
    {
        $import = $this->import($this->report([
            $this->row(),
            $this->row(['item-status' => 'Cancelled', 'quantity' => '0', 'item-price' => '', 'item-tax' => '', 'order-item-id ' => '10000000000002']),
            $this->row(['item-status' => 'Canceled', 'asin' => 'B0FISI0515', 'order-item-id ' => '10000000000003']),
        ]));

        $this->assertSame([3, 1, 2, 0], [$import->row_count, $import->created_line_count, $import->skipped_line_count, $import->error_row_count]);
        $this->assertCount(0, $import->issues);
        $this->assertSame(['fisi-05-1-10'], SalesLine::with('sku')->get()->pluck('sku.sku_code')->all());
    }

    public function test_reimporting_the_same_report_overwrites_instead_of_doubling(): void
    {
        $report = $this->report([$this->row(), $this->row(['order-item-id ' => '10000000000002', 'asin' => 'B0FISI0515'])]);
        $this->import($report);

        $import = $this->import($report);

        $this->assertSame([0, 2], [$import->created_line_count, $import->updated_line_count]);
        $this->assertSame(2, SalesLine::count());
    }

    public function test_saves_an_unknown_asin_as_an_unregistered_sku_named_after_the_asin_and_reuses_it(): void
    {
        $import = $this->import($this->report([$this->row(['asin' => 'B0UNKNOWN1'])]));
        $again = $this->import($this->report([$this->row(['amazon-order-id' => '503-0000002-0000002', 'asin' => 'B0UNKNOWN1'])]));

        $this->assertSame([1, 0], [$import->created_sku_count, $again->created_sku_count]);
        $this->assertDatabaseHas('skus', ['sku_code' => 'B0UNKNOWN1', 'child_asin' => 'B0UNKNOWN1', 'item_id' => null, 'status' => null]);
        $this->assertSame(2, Sku::where('sku_code', 'B0UNKNOWN1')->firstOrFail()->salesLines()->count());
    }

    public function test_ignores_promotion_discounts_and_warns_about_zero_yen_lines(): void
    {
        $import = $this->import($this->report([
            $this->row(['item-promotion-discount' => '-500.0']),
            $this->row(['amazon-order-id' => '503-0000002-0000002', 'item-price' => '0.0', 'item-tax' => '0.0']),
        ]));

        $this->assertSame([4207, 0], SalesLine::query()->orderBy('source_order_id')->pluck('amount')->all());
        $this->assertSame(['金額が0円です。プロモーション品・同梱品などでないか確認してください。'], $import->issues->where('level', ImportIssueLevel::Warning)->pluck('message')->all());
    }

    public function test_keeps_only_the_first_of_duplicate_order_items_and_rejects_orders_with_mixed_purchase_dates(): void
    {
        $this->import($this->report([$this->row(['amazon-order-id' => '503-0000002-0000002'])]));

        $import = $this->import($this->report([
            $this->row(),
            $this->row(['quantity' => '3']),
            $this->row(['amazon-order-id' => '503-0000002-0000002', 'item-price' => '9999.0']),
            $this->row(['amazon-order-id' => '503-0000002-0000002', 'purchase-date' => '2026-08-26T15:00:00+09:00', 'order-item-id ' => '10000000000003']),
        ]));

        $this->assertSame([1, 3], [$import->created_line_count, $import->error_row_count]);
        $this->assertSame(1, SalesLine::where('source_order_id', '503-0000001-0000001')->value('quantity'));
        // 購入日時が混ざった注文は登録も更新もしない（前に取り込んだ明細が残る）
        $this->assertSame(4207, SalesLine::where('source_order_id', '503-0000002-0000002')->value('amount'));
        $this->assertCount(2, $import->issues->where('level', ImportIssueLevel::Error));
    }

    public function test_skips_invalid_shipped_rows_with_a_reason(): void
    {
        $import = $this->import($this->report([
            $this->row(['quantity' => '0']),
            $this->row(['amazon-order-id' => '503-0000002-0000002', 'fulfillment-channel' => 'Other']),
            $this->row(['amazon-order-id' => '503-0000003-0000003', 'purchase-date' => '2026/08/26 14:29']),
            $this->row(['amazon-order-id' => '503-0000004-0000004', 'asin' => '']),
            $this->row(['amazon-order-id' => '503-0000005-0000005', 'sales-channel' => '']),
        ]));

        $this->assertSame([1, 4], [$import->created_line_count, $import->error_row_count]);
        $this->assertSame([
            'quantity が1以上の整数ではありません（0）。',
            'fulfillment-channel「Other」から出荷倉庫を判定できません（Amazon か Merchant である必要があります）。',
            'purchase-date「2026/08/26 14:29」を日時として読み取れません。',
            '「asin」が空欄です。',
            'sales-channel が想定外の値です（）。Amazon の販売として扱いました。',
        ], $import->issues->sortBy('row_number')->pluck('message')->values()->all());
    }

    public function test_never_stores_personal_information_or_product_names(): void
    {
        $this->import($this->report([$this->row()]));

        $stored = json_encode([SalesLine::all()->toArray(), SalesImport::with('issues')->get()->toArray()], JSON_UNESCAPED_UNICODE);
        foreach (['千代田区', '東京都', '100-0001', '老眼鏡 SHIORI', 'FISI05-1 +1.00'] as $value) {
            $this->assertStringNotContainsString($value, $stored);
        }
    }

    public function test_rejects_a_report_missing_required_columns(): void
    {
        $header = array_values(array_diff(self::HEADER, ['fulfillment-channel', 'asin']));

        $import = $this->import($this->report([$this->row()], header: $header));

        $this->assertSame(SyncStatus::Failed, $import->status);
        $this->assertSame('必要な列がありません：fulfillment-channel、asin。Amazonの全注文レポートか確認してください。', $import->error_message);
        $this->assertSame(0, SalesLine::count());
    }
}
