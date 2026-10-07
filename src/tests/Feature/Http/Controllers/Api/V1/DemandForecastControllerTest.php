<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandForecastControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
    }

    public function test_forecasts_from_three_consecutive_windows_going_back_from_the_latest_sales_date(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $this->sell(Sku::factory()->create(), '2025-10-01', 1);
        // 最新の販売日は 2026-09-30。直近30日 = 09-01〜09-30、31〜90日前 = 07-03〜08-31、91〜180日前 = 04-04〜07-02
        $this->sell($sku, '2026-09-30', 30);
        $this->sell($sku, '2026-08-31', 60);
        $this->sell($sku, '2026-07-03', 60);
        $this->sell($sku, '2026-07-02', 90);
        $this->sell($sku, '2026-04-04', 90);
        $this->sell($sku, '2026-04-03', 999);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertOk()
            ->assertJsonPath('meta.sales_data_from', '2025-10-01')
            ->assertJsonPath('meta.sales_data_to', '2026-09-30')
            ->assertJsonPath('meta.forecast_from', '2026-10-07')
            ->assertJsonPath('meta.selection.item_count', 1)
            ->assertJsonPath('data.0.item_no', 'fl-01')
            ->assertJsonPath('data.0.skus.0.sku_code', 'fl-01-1-10')
            ->assertJsonPath('data.0.skus.0.windows.0', ['length' => 30, 'offset' => 0, 'label' => '直近30日', 'from' => '2026-09-01', 'to' => '2026-09-30', 'sales_days' => 30, 'stockout_days' => 0, 'sales_quantity' => 30, 'average' => 1, 'base_weight' => 0.5, 'weight' => 0.5, 'is_excluded' => false])
            ->assertJsonPath('data.0.skus.0.windows.1.label', '31〜90日前')
            ->assertJsonPath('data.0.skus.0.windows.1.from', '2026-07-03')
            ->assertJsonPath('data.0.skus.0.windows.1.to', '2026-08-31')
            ->assertJsonPath('data.0.skus.0.windows.1.sales_quantity', 120)
            ->assertJsonPath('data.0.skus.0.windows.2.label', '91〜180日前')
            ->assertJsonPath('data.0.skus.0.windows.2.from', '2026-04-04')
            ->assertJsonPath('data.0.skus.0.windows.2.to', '2026-07-02')
            ->assertJsonPath('data.0.skus.0.windows.2.sales_quantity', 180)
            // 30÷30×0.5 + 120÷60×0.3 + 180÷90×0.2 = 0.5 + 0.6 + 0.4
            ->assertJsonPath('data.0.skus.0.base_average', 1.5)
            ->assertJsonPath('data.0.skus.0.unpredictable_reason', null)
            ->assertJsonCount(12, 'data.0.skus.0.monthly')
            ->assertJsonPath('data.0.skus.0.monthly.0', ['month' => '2026-10', 'days' => 25, 'quantity' => 37.5])
            ->assertJsonPath('data.0.skus.0.monthly.1', ['month' => '2026-11', 'days' => 30, 'quantity' => 45])
            ->assertJsonPath('data.0.skus.0.monthly.11', ['month' => '2027-09', 'days' => 30, 'quantity' => 45]);
    }

    public function test_shortens_or_excludes_older_windows_without_enough_sales_data_and_renormalizes_the_weights(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        // 販売実績は 07-29〜10-06 の70日分。31〜90日前は 07-29〜09-06 の40日、91〜180日前は0日で除外
        $this->sell($sku, '2026-07-29', 40);
        $this->sell($sku, '2026-10-06', 60);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.windows.*.sales_days', [30, 40, 0])
            ->assertJsonPath('data.0.skus.0.windows.*.from', ['2026-09-07', '2026-07-29', null])
            ->assertJsonPath('data.0.skus.0.windows.*.is_excluded', [false, false, true])
            ->assertJsonPath('data.0.skus.0.windows.*.weight', [0.625, 0.375, 0])
            // 60÷30×0.625 + 40÷40×0.375 = 1.25 + 0.375
            ->assertJsonPath('data.0.skus.0.base_average', 1.625);
    }

    public function test_excludes_a_window_with_fewer_than_14_days_of_sales_data(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        // 31〜90日前は 08-28〜09-06 の10日しかなく、除外する
        $this->sell($sku, '2026-08-28', 100);
        $this->sell($sku, '2026-10-06', 30);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.windows.*.sales_days', [30, 10, 0])
            ->assertJsonPath('data.0.skus.0.windows.*.weight', [1, 0, 0])
            ->assertJsonPath('data.0.skus.0.base_average', 1);
    }

    public function test_leaves_out_stockout_days_from_both_the_sales_and_the_days(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $this->sell(Sku::factory()->create(), '2026-01-01', 1);
        // 直近30日 = 09-07〜10-06。在庫の記録は 09-16 から（それより前は欠品としない）
        $this->sell($sku, '2026-09-10', 6);
        // 09-18（金）にAmazonとBOSSの在庫が0。土日（19・20日）も引き継いで欠品、21日（月）に入荷
        $this->stock($sku, '2026-09-16', ['amazon_fba' => 5, 'free_stock' => 0]);
        $this->stock($sku, '2026-09-17', ['amazon_fba' => 1]);
        $this->stock($sku, '2026-09-18', ['amazon_fba' => 0, 'boss_own' => 0, 'free_stock' => 50, 'ec_stock' => 10]);
        $this->stock($sku, '2026-09-21', ['boss_own' => 20]);
        $this->sell($sku, '2026-09-18', 1);
        $this->sell($sku, '2026-09-20', 3);
        $this->sell($sku, '2026-09-30', 21);

        $response = $this->getJson('/api/v1/demand-forecasts');

        // 欠品日は 09-18〜20 の3日。販売数は欠品日の4点を除いた27点、販売できた日数は27日
        $response->assertJsonPath('data.0.skus.0.windows.0.stockout_days', 3)
            ->assertJsonPath('data.0.skus.0.windows.0.sales_days', 27)
            ->assertJsonPath('data.0.skus.0.windows.0.sales_quantity', 27)
            ->assertJsonPath('data.0.skus.0.windows.0.average', 1)
            ->assertJsonPath('data.0.skus.0.windows.1.stockout_days', 0);
    }

    public function test_excludes_a_window_with_stockouts_on_half_of_its_days_and_is_unpredictable_when_none_remain(): void
    {
        $short = $this->targetSku('fl-01', 'fl-01-1-10');
        $long = Sku::factory()->create(['item_id' => $short->item_id, 'sku_code' => 'fl-01-1-15', 'position' => 1]);
        // 販売実績は 08-28〜10-06（直近30日 + 31〜90日前は10日で除外）
        $this->sell($short, '2026-08-28', 1);
        $this->sell($long, '2026-10-06', 15);
        // fl-01-1-10 は 09-22 から欠品（直近30日のうち15日 = 半分）
        $this->stock($short, '2026-09-21', ['amazon_fba' => 3]);
        $this->stock($short, '2026-09-22', ['amazon_fba' => 0]);
        $this->stock($long, '2026-09-22', ['amazon_fba' => 0]);
        $this->stock($long, '2026-09-23', ['boss_rfc' => 10]);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.windows.0.stockout_days', 15)
            ->assertJsonPath('data.0.skus.0.windows.0.is_excluded', true)
            ->assertJsonPath('data.0.skus.0.unpredictable_reason', 'stockout')
            ->assertJsonPath('data.0.skus.0.unpredictable_reason_label', '欠品していた日が多く、平均日販を出せません')
            ->assertJsonPath('data.0.skus.1.windows.0.stockout_days', 1)
            ->assertJsonPath('data.0.skus.1.windows.0.sales_days', 29)
            ->assertJsonPath('data.0.skus.1.unpredictable_reason', null);
    }

    public function test_is_unpredictable_with_fewer_than_14_days_of_sales_data(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $this->sell($sku, '2026-09-25', 5);
        $this->sell($sku, '2026-10-06', 5);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.windows.*.is_excluded', [true, true, true])
            ->assertJsonPath('data.0.skus.0.windows.*.weight', [0, 0, 0])
            ->assertJsonPath('data.0.skus.0.base_average', null)
            ->assertJsonPath('data.0.skus.0.monthly', null)
            ->assertJsonPath('data.0.skus.0.unpredictable_reason', 'insufficient_days')
            ->assertJsonPath('data.0.skus.0.unpredictable_reason_label', '販売実績の日数が足りません（14日未満）');
    }

    public function test_is_unpredictable_instead_of_zero_when_the_sku_did_not_sell_in_the_last_180_days(): void
    {
        $sold = $this->targetSku('fl-01', 'fl-01-1-10');
        $unsold = Sku::factory()->create(['item_id' => $sold->item_id, 'sku_code' => 'fl-01-1-15']);
        $this->sell($sold, '2025-10-01', 1);
        $this->sell($unsold, '2026-04-08', 50);
        $this->sell($sold, '2026-10-06', 3);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.1.sku_code', 'fl-01-1-15')
            ->assertJsonPath('data.0.skus.1.base_average', null)
            ->assertJsonPath('data.0.skus.1.unpredictable_reason', 'no_sales')
            ->assertJsonPath('data.0.skus.1.unpredictable_reason_label', '直近180日に販売実績がありません');
    }

    public function test_returns_no_items_before_target_items_are_confirmed(): void
    {
        $this->sell(Sku::factory()->create(), '2026-10-06', 1);

        $this->getJson('/api/v1/demand-forecasts')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.selection', null)
            ->assertJsonPath('meta.min_sales_days', 14)
            ->assertJsonPath('meta.window_weights', [['length' => 30, 'weight' => 0.5], ['length' => 60, 'weight' => 0.3], ['length' => 90, 'weight' => 0.2]]);
    }

    public function test_uses_the_latest_selection_in_sales_rank_order_and_skips_items_removed_from_crosswalker(): void
    {
        $first = Item::factory()->create(['item_no' => 'first-01']);
        $second = Item::factory()->create(['item_no' => 'second-01']);
        $gone = Item::factory()->create(['item_no' => 'gone-01']);
        $this->select(['first-01']);
        $this->travel(1)->minutes();
        $this->select(['second-01', 'gone-01', 'first-01']);
        $gone->delete();

        $this->getJson('/api/v1/demand-forecasts')
            ->assertJsonPath('data.*.item_no', ['second-01', 'first-01'])
            ->assertJsonPath('meta.selection.item_count', 3);
        $this->assertNotNull($first->fresh());
        $this->assertNotNull($second->fresh());
    }

    private function targetSku(string $itemNo, string $skuCode): Sku
    {
        $item = Item::factory()->create(['item_no' => $itemNo]);
        $sku = Sku::factory()->create(['item_id' => $item->id, 'sku_code' => $skuCode, 'position' => 0]);
        $this->select([$itemNo]);

        return $sku;
    }

    /**
     * @param  list<string>  $itemNos
     */
    private function select(array $itemNos): void
    {
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => CarbonImmutable::today()->subYear(), 'ranking_to' => CarbonImmutable::today()]);
        foreach ($itemNos as $index => $itemNo) {
            $selection->items()->create(['item_no' => $itemNo, 'sales_rank' => $index + 1, 'is_candidate' => true]);
        }
    }

    private function sell(Sku $sku, string $date, int $quantity): void
    {
        static $orderId = 1;

        SalesLine::create([
            'source' => 'boss',
            'source_order_id' => (string) $orderId++,
            'sales_date' => $date,
            'mall' => 'rakuten',
            'sku_id' => $sku->id,
            'warehouse' => 'boss_own',
            'quantity' => $quantity,
            'amount' => 1000 * $quantity,
        ]);
    }

    /**
     * @param  array<string, int>  $quantities  [在庫の区分 => 在庫数]
     */
    private function stock(Sku $sku, string $date, array $quantities): void
    {
        foreach ($quantities as $warehouse => $quantity) {
            Inventory::create(['stock_date' => $date, 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity]);
        }
    }
}
