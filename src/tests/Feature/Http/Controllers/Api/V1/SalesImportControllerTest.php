<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\SalesImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class SalesImportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());
    }

    private function bossCsv(string $status = '完了'): string
    {
        $header = '注文ID,ショップID,モール注文番号,モール注文日時,注文ステータス,商品合計金額,消費税合計,倉庫,SKUコード,商品ID,商品名,注文個数,商品単価,消費税,税込別';
        $rows = [
            "1001,67419,R-1,2026/08/18 2:23:17,{$status},3960,360,自社倉庫,fll-006-2-20,P-1,老眼鏡,1,3960,360,1",
            '1002,67419,R-2,2026/08/19 10:00:00,完了,3960,360,火星倉庫,fll-006-2-20,P-1,老眼鏡,1,3960,360,1',
        ];

        return mb_convert_encoding($header."\r\n".implode("\r\n", $rows)."\r\n", 'SJIS-win', 'UTF-8');
    }

    public function test_store_imports_an_uploaded_csv_and_returns_201_with_counts_and_issues(): void
    {
        $file = UploadedFile::fake()->createWithContent('BOSS受注実績.csv', $this->bossCsv());

        $response = $this->post('/api/v1/sales-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.file_name', 'BOSS受注実績.csv')
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.created_line_count', 1)
            ->assertJsonPath('data.error_row_count', 1)
            ->assertJsonPath('data.warning_count', 0)
            ->assertJsonPath('data.issues.0.level', 'error')
            ->assertJsonPath('data.issues.0.row_number', 3)
            ->assertJsonPath('data.issues.0.message', '倉庫「火星倉庫」から出荷倉庫を判定できません（「RFC倉庫」か「自社倉庫」である必要があります）。');
        $this->assertDatabaseHas('sales_lines', ['source_order_id' => '1001']);
    }

    public function test_store_detects_an_amazon_order_report_by_its_columns(): void
    {
        $report = "amazon-order-id\tpurchase-date\torder-status\tfulfillment-channel\tsales-channel\tasin\tquantity\titem-price\torder-item-id \r\n"
            ."503-0000001-0000001\t2026-08-26T14:29:25+09:00\tShipped\tAmazon\tAmazon.co.jp\tB0TEST0001\t1\t4207.0\t10000000000001\r\n";
        $file = UploadedFile::fake()->createWithContent('Amazon全注文レポート_2026年8月.txt', $report);

        $response = $this->post('/api/v1/sales-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.source', 'amazon')
            ->assertJsonPath('data.source_label', 'Amazon')
            ->assertJsonPath('data.created_line_count', 1);
        $this->assertDatabaseHas('sales_lines', ['source' => 'amazon', 'mall' => 'amazon', 'warehouse' => 'amazon_fba', 'amount' => 4207]);
    }

    public function test_store_returns_201_with_the_reason_when_the_file_cannot_be_read(): void
    {
        $file = UploadedFile::fake()->createWithContent('orders.csv', "a,b\r\n1,2\r\n");

        $response = $this->post('/api/v1/sales-imports', ['file' => $file], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.issues', []);
        $this->assertStringStartsWith('必要な列がありません：', $response->json('data.error_message'));
    }

    public function test_store_returns_422_when_no_csv_file_is_attached(): void
    {
        $response = $this->post('/api/v1/sales-imports', ['file' => UploadedFile::fake()->create('orders.xlsx', 10)], ['Accept' => 'application/json']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['file' => 'ファイルには次の拡張子のファイルを指定してください：csv']);
        $this->assertSame(0, SalesImport::query()->count());
    }

    public function test_index_lists_imports_newest_first(): void
    {
        foreach (['first.csv', 'second.csv'] as $name) {
            $this->post('/api/v1/sales-imports', ['file' => UploadedFile::fake()->createWithContent($name, $this->bossCsv())], ['Accept' => 'application/json']);
        }

        $response = $this->getJson('/api/v1/sales-imports');

        $response->assertOk()
            ->assertJsonPath('data.*.file_name', ['second.csv', 'first.csv'])
            ->assertJsonPath('data.0.updated_line_count', 1)
            ->assertJsonMissingPath('data.0.issues');
    }

    public function test_show_returns_an_import_with_its_issues(): void
    {
        $this->post('/api/v1/sales-imports', ['file' => UploadedFile::fake()->createWithContent('a.csv', $this->bossCsv('キャンセル'))], ['Accept' => 'application/json']);
        $import = SalesImport::query()->sole();

        $response = $this->getJson("/api/v1/sales-imports/{$import->id}");

        $response->assertOk()->assertJsonCount(2, 'data.issues')->assertJsonPath('data.issues.0.row_number', 2);
    }
}
