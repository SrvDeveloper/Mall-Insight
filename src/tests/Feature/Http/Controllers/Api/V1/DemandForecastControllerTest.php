<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemandForecastControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());

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
            ->assertJsonPath('meta.channels', [
                ['channel' => 'boss', 'label' => 'BOSS', 'sales_data_from' => '2025-10-01', 'sales_data_to' => '2026-09-30'],
                ['channel' => 'amazon', 'label' => 'Amazon', 'sales_data_from' => null, 'sales_data_to' => null],
            ])
            ->assertJsonCount(1, 'data.0.skus.0.channels')
            ->assertJsonPath('data.0.skus.0.channels.0.channel', 'boss')
            ->assertJsonPath('data.0.skus.0.channels.0.base_average', 1.5)
            ->assertJsonPath('data.0.skus.0.is_partial', false)
            ->assertJsonPath('meta.forecast_from', '2026-10-07')
            ->assertJsonPath('meta.selection.item_count', 1)
            ->assertJsonPath('data.0.item_no', 'fl-01')
            ->assertJsonPath('data.0.skus.0.sku_code', 'fl-01-1-10')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0', ['length' => 30, 'offset' => 0, 'label' => '直近30日', 'from' => '2026-09-01', 'to' => '2026-09-30', 'sales_days' => 30, 'stockout_days' => 0, 'sales_quantity' => 30, 'average' => 1, 'base_weight' => 0.5, 'weight' => 0.5, 'is_excluded' => false])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.1.label', '31〜90日前')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.1.from', '2026-07-03')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.1.to', '2026-08-31')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.1.sales_quantity', 120)
            ->assertJsonPath('data.0.skus.0.channels.0.windows.2.label', '91〜180日前')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.2.from', '2026-04-04')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.2.to', '2026-07-02')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.2.sales_quantity', 180)
            // 30÷30×0.5 + 120÷60×0.3 + 180÷90×0.2 = 0.5 + 0.6 + 0.4
            ->assertJsonPath('data.0.skus.0.base_average', 1.5)
            ->assertJsonPath('data.0.skus.0.channels.0.unpredictable_reason', null)
            ->assertJsonCount(12, 'data.0.skus.0.monthly')
            // 今月（10月）も1か月分（31日）
            ->assertJsonPath('data.0.skus.0.monthly.0', ['month' => '2026-10', 'days' => 31, 'quantity' => 46.5])
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

        $response->assertJsonPath('data.0.skus.0.channels.0.windows.*.sales_days', [30, 40, 0])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.*.from', ['2026-09-07', '2026-07-29', null])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.*.is_excluded', [false, false, true])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.*.weight', [0.625, 0.375, 0])
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

        $response->assertJsonPath('data.0.skus.0.channels.0.windows.*.sales_days', [30, 10, 0])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.*.weight', [1, 0, 0])
            ->assertJsonPath('data.0.skus.0.base_average', 1);
    }

    public function test_leaves_out_days_out_of_stock_in_the_channel_from_both_the_sales_and_the_days(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $this->sell(Sku::factory()->create(), '2026-01-01', 1);
        // 直近30日 = 09-07〜10-06。在庫の記録は 09-16 から（それより前は欠品としない）
        $this->sell($sku, '2026-09-10', 6);
        // 09-18（金）にBOSSの在庫が0（Amazon・フリー在庫・ECストックにはある）。土日（19・20日）も引き継いで欠品、21日（月）に入荷
        $this->stock($sku, '2026-09-16', ['boss_own' => 5, 'free_stock' => 0]);
        $this->stock($sku, '2026-09-17', ['boss_rfc' => 1]);
        $this->stock($sku, '2026-09-18', ['boss_own' => 0, 'boss_rfc' => 0, 'amazon_fba' => 30, 'free_stock' => 50, 'ec_stock' => 10]);
        $this->stock($sku, '2026-09-21', ['boss_own' => 20]);
        $this->sell($sku, '2026-09-18', 1);
        $this->sell($sku, '2026-09-20', 3);
        $this->sell($sku, '2026-09-30', 21);

        $response = $this->getJson('/api/v1/demand-forecasts');

        // 欠品日は 09-18〜20 の3日。販売数は欠品日の4点を除いた27点、販売できた日数は27日
        $response->assertJsonPath('data.0.skus.0.channels.0.windows.0.stockout_days', 3)
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.sales_days', 27)
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.sales_quantity', 27)
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.average', 1)
            ->assertJsonPath('data.0.skus.0.channels.0.windows.1.stockout_days', 0);
    }

    public function test_excludes_a_window_with_stockouts_on_half_of_its_days_and_is_unpredictable_when_none_remain(): void
    {
        $short = $this->targetSku('fl-01', 'fl-01-1-10');
        $long = Sku::factory()->create(['item_id' => $short->item_id, 'sku_code' => 'fl-01-1-15', 'position' => 1]);
        // 販売実績は 08-28〜10-06（直近30日 + 31〜90日前は10日で除外）
        $this->sell($short, '2026-08-28', 1);
        $this->sell($long, '2026-10-06', 15);
        // fl-01-1-10 は 09-22 からBOSSで欠品（直近30日のうち15日 = 半分）
        $this->stock($short, '2026-09-21', ['boss_own' => 3]);
        $this->stock($short, '2026-09-22', ['boss_own' => 0, 'amazon_fba' => 8]);
        $this->stock($long, '2026-09-22', ['boss_rfc' => 0]);
        $this->stock($long, '2026-09-23', ['boss_rfc' => 10]);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.channels.0.windows.0.stockout_days', 15)
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.is_excluded', true)
            ->assertJsonPath('data.0.skus.0.channels.0.unpredictable_reason', 'stockout')
            ->assertJsonPath('data.0.skus.0.channels.0.unpredictable_reason_label', '欠品していた日が多く、平均日販を出せません')
            ->assertJsonPath('data.0.skus.0.unpredictable_reason_label', 'BOSS：欠品していた日が多く、平均日販を出せません')
            ->assertJsonPath('data.0.skus.1.channels.0.windows.0.stockout_days', 1)
            ->assertJsonPath('data.0.skus.1.channels.0.windows.0.sales_days', 29)
            ->assertJsonPath('data.0.skus.1.channels.0.unpredictable_reason', null);
    }

    public function test_forecasts_each_channel_from_its_own_sales_and_stock_and_adds_them_up(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $bossOnly = Sku::factory()->create(['item_id' => $sku->item_id, 'sku_code' => 'fl-01-1-15', 'position' => 1]);
        // BOSS は 2025-10-01〜2026-10-06、Amazon は 2026-04-01〜2026-10-05 まで取り込み済み
        $this->sell(Sku::factory()->create(), '2025-10-01', 1);
        $this->sell($sku, '2026-10-06', 30);
        $this->sell($bossOnly, '2026-10-06', 15);
        $this->sell(Sku::factory()->create(), '2026-04-01', 1, 'amazon_fba');
        $this->sell($sku, '2026-10-05', 54, 'amazon_fba');
        // Amazon は 09-26 から欠品（BOSS の在庫はある）
        $this->stock($sku, '2026-09-25', ['amazon_fba' => 5, 'boss_own' => 10]);
        $this->stock($sku, '2026-09-26', ['amazon_fba' => 0, 'amazon_own' => 0, 'boss_own' => 10]);
        $this->stock($sku, '2026-10-01', ['amazon_fba' => 40, 'boss_own' => 10]);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('meta.channels.1.sales_data_from', '2026-04-01')
            ->assertJsonPath('meta.channels.1.sales_data_to', '2026-10-05')
            ->assertJsonPath('data.0.skus.0.channels.*.channel', ['boss', 'amazon'])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.stockout_days', 0)
            // BOSS は 30÷30×0.5 + 0 + 0
            ->assertJsonPath('data.0.skus.0.channels.0.base_average', 0.5)
            // Amazon の直近30日は 09-06〜10-05。欠品は 09-26〜30 の5日、販売できた日は25日
            ->assertJsonPath('data.0.skus.0.channels.1.windows.0.from', '2026-09-06')
            ->assertJsonPath('data.0.skus.0.channels.1.windows.0.stockout_days', 5)
            ->assertJsonPath('data.0.skus.0.channels.1.windows.0.sales_days', 25)
            // Amazon は 54÷25×0.5 + 0 + 0。合計は 0.5 + 1.08
            ->assertJsonPath('data.0.skus.0.channels.1.base_average', 1.08)
            ->assertJsonPath('data.0.skus.0.base_average', 1.58)
            // 今月（10月）は1か月分：1.58 × 31日
            ->assertJsonPath('data.0.skus.0.monthly.0.quantity', 48.98)
            ->assertJsonPath('data.0.skus.0.is_partial', false)
            // Amazon で売れていない SKU は、BOSS の予測だけを合計に入れ、一部予測不能とする
            ->assertJsonPath('data.0.skus.1.base_average', 0.25)
            ->assertJsonPath('data.0.skus.1.is_partial', true)
            ->assertJsonPath('data.0.skus.1.channels.1.unpredictable_reason', 'no_sales')
            ->assertJsonPath('data.0.skus.1.unpredictable_reason_label', 'Amazon：直近180日に販売実績がありません');
    }

    public function test_does_not_forecast_amazon_for_skus_without_a_child_asin(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $sku->update(['child_asin' => null]);
        $this->sell(Sku::factory()->create(), '2025-10-01', 1);
        $this->sell(Sku::factory()->create(), '2025-10-01', 1, 'amazon_fba');
        $this->sell($sku, '2026-10-06', 30);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.channels.*.channel', ['boss'])
            ->assertJsonPath('data.0.skus.0.base_average', 0.5)
            ->assertJsonPath('data.0.skus.0.is_partial', false)
            ->assertJsonPath('data.0.skus.0.unpredictable_reason_label', null);
    }

    public function test_leaves_out_discontinued_skus_from_the_forecast_and_the_inventory_trend(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $discontinued = Sku::factory()->create(['item_id' => $sku->item_id, 'sku_code' => 'fl-01-1-15', 'position' => 1, 'status' => 'inactive']);
        $this->sell(Sku::factory()->create(), '2025-10-01', 1);
        $this->sell($sku, '2026-10-06', 30);
        $this->sell($discontinued, '2026-10-06', 30);
        Inventory::create(['stock_date' => '2026-10-06', 'sku_id' => $discontinued->id, 'warehouse' => 'boss_own', 'quantity' => 0]);

        $this->getJson('/api/v1/demand-forecasts')->assertJsonPath('data.0.skus.*.sku_code', ['fl-01-1-10']);
        $this->getJson('/api/v1/inventory-trends')->assertJsonPath('data.0.skus.*.sku_code', ['fl-01-1-10']);
    }

    public function test_forecasts_only_the_given_item_even_if_it_is_not_a_target_item(): void
    {
        $this->targetSku('fl-01', 'fl-01-1-10');
        $other = Item::factory()->create(['item_no' => 'other-01']);
        $otherSku = Sku::factory()->create(['item_id' => $other->id, 'sku_code' => 'other-01-1', 'position' => 0]);
        $this->sell(Sku::factory()->create(), '2025-10-01', 1);
        $this->sell($otherSku, '2026-10-06', 30);

        $this->getJson('/api/v1/demand-forecasts?item_no=other-01')
            ->assertOk()
            ->assertJsonPath('data.*.item_no', ['other-01'])
            ->assertJsonPath('data.0.skus.0.base_average', 0.5);
        $this->getJson('/api/v1/demand-forecasts?item_no=unknown')->assertOk()->assertJsonPath('data', []);
    }

    public function test_does_not_count_today_whose_sales_are_not_final_yet(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $this->sell(Sku::factory()->create(), '2025-10-01', 1);
        $this->sell($sku, '2026-10-06', 30);
        $this->sell($sku, '2026-10-07', 99);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('meta.channels.0.sales_data_to', '2026-10-06')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.to', '2026-10-06')
            ->assertJsonPath('data.0.skus.0.channels.0.windows.0.sales_quantity', 30);
    }

    public function test_is_unpredictable_with_fewer_than_14_days_of_sales_data(): void
    {
        $sku = $this->targetSku('fl-01', 'fl-01-1-10');
        $this->sell($sku, '2026-09-25', 5);
        $this->sell($sku, '2026-10-06', 5);

        $response = $this->getJson('/api/v1/demand-forecasts');

        $response->assertJsonPath('data.0.skus.0.channels.0.windows.*.is_excluded', [true, true, true])
            ->assertJsonPath('data.0.skus.0.channels.0.windows.*.weight', [0, 0, 0])
            ->assertJsonPath('data.0.skus.0.base_average', null)
            ->assertJsonPath('data.0.skus.0.monthly', null)
            ->assertJsonPath('data.0.skus.0.channels.0.unpredictable_reason', 'insufficient_days')
            ->assertJsonPath('data.0.skus.0.channels.0.unpredictable_reason_label', '販売実績の日数が足りません（14日未満）')
            ->assertJsonPath('data.0.skus.0.unpredictable_reason_label', 'BOSS：販売実績の日数が足りません（14日未満）');
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
            ->assertJsonPath('data.0.skus.1.channels.0.unpredictable_reason', 'no_sales')
            ->assertJsonPath('data.0.skus.1.channels.0.unpredictable_reason_label', '直近180日に販売実績がありません')
            ->assertJsonPath('data.0.skus.1.unpredictable_reason_label', 'BOSS：直近180日に販売実績がありません');
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

    private function sell(Sku $sku, string $date, int $quantity, string $warehouse = 'boss_own'): void
    {
        static $orderId = 1;

        SalesLine::create([
            'source' => 'boss',
            'source_order_id' => (string) $orderId++,
            'sales_date' => $date,
            'mall' => 'rakuten',
            'sku_id' => $sku->id,
            'warehouse' => $warehouse,
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
