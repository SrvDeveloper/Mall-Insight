<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\InboundPlan;
use App\Models\Inventory;
use App\Models\InventoryTrendSetting;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryTrendControllerTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());

        // 今日は 2026-10-07。今月（10月）は残り25日、判定する月は6か月後の 2027-04
        $this->travelTo('2026-10-07 09:00:00');
        $this->item = Item::factory()->create(['item_no' => 'fl-01']);
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']);
        $selection->items()->create(['item_no' => 'fl-01', 'sales_rank' => 1, 'is_candidate' => true]);
        // 販売実績は 2025-10-01 から（3つの期間とも使える）
        $this->sell(Sku::factory()->create(['item_id' => null]), '2025-10-01', 1);
    }

    /**
     * 直近30日に 60×$averageDaily 点売れた SKU（基準平均日販 = $averageDaily）。
     */
    private function sku(string $code, float $averageDaily, int $position = 0): Sku
    {
        $sku = Sku::factory()->create(['item_id' => $this->item->id, 'sku_code' => $code, 'position' => $position]);
        if ($averageDaily > 0) {
            $this->sell($sku, '2026-10-06', (int) round(60 * $averageDaily));
        }

        return $sku;
    }

    private function sell(Sku $sku, string $date, int $quantity): void
    {
        static $orderId = 1;

        SalesLine::create(['source' => 'boss', 'source_order_id' => (string) $orderId++, 'sales_date' => $date, 'mall' => 'rakuten', 'sku_id' => $sku->id, 'warehouse' => 'boss_own', 'quantity' => $quantity, 'amount' => 1000 * $quantity]);
    }

    /**
     * @param  array<string, int>  $quantities
     */
    private function stock(Sku $sku, array $quantities, string $date = '2026-10-07'): void
    {
        foreach ($quantities as $warehouse => $quantity) {
            Inventory::create(['stock_date' => $date, 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity]);
        }
    }

    /**
     * @param  array<int, int>  $allocations  SKU ID => 数
     */
    private function plan(string $month, int $quantity, array $allocations = [], bool $received = false): InboundPlan
    {
        $plan = InboundPlan::create(['item_no' => 'fl-01', 'arrival_month' => "{$month}-01", 'quantity' => $quantity, 'received_at' => $received ? now() : null]);
        foreach ($allocations as $skuId => $allocated) {
            $plan->allocations()->create(['sku_id' => $skuId, 'quantity' => $allocated]);
        }

        return $plan;
    }

    public function test_projects_the_total_stock_of_all_six_warehouses_month_by_month_and_warns_about_a_shortage_six_months_ahead(): void
    {
        $sku = $this->sku('fl-01-1-10', 1.0);
        $this->stock($sku, ['amazon_fba' => 50, 'boss_own' => 30, 'free_stock' => 20, 'ec_stock' => 0]);
        $this->stock($sku, ['amazon_fba' => 999], '2026-10-06');

        $response = $this->getJson('/api/v1/inventory-trends');

        $response->assertOk()
            ->assertJsonPath('meta.stock_date', '2026-10-07')
            ->assertJsonPath('meta.check_month', '2027-04')
            ->assertJsonPath('meta.settings', ['check_month_offset' => 6, 'changed_at' => null])
            ->assertJsonPath('data.0.skus.0.status', 'calculated')
            ->assertJsonPath('data.0.skus.0.opening_stock', 100)
            ->assertJsonPath('data.0.skus.0.stock_by_warehouse.1', ['warehouse' => 'amazon_fba', 'label' => 'Amazon FBA', 'quantity' => 50])
            ->assertJsonPath('data.0.skus.0.stock_by_warehouse.0.quantity', null)
            ->assertJsonPath('data.0.skus.0.average_daily', 1)
            // 10月 25 → 75、11月 30 → 45、12月 31 → 14、1月 31 → 0（17 足りない）
            ->assertJsonPath('data.0.skus.0.months.*.ending_stock', [75, 45, 14, 0, 0, 0, 0, 0, 0, 0, 0, 0])
            ->assertJsonPath('data.0.skus.0.months.3.shortfall', 17)
            ->assertJsonPath('data.0.skus.0.months.6.shortfall', 30)
            ->assertJsonPath('data.0.skus.0.first_shortage_month', '2027-01')
            ->assertJsonPath('data.0.skus.0.warning', 'shortage')
            ->assertJsonPath('data.0.skus.0.warning_label', '欠品警告')
            ->assertJsonMissingPath('data.0.skus.0.recommended_order');
    }

    public function test_adds_allocated_inbound_and_provisionally_shares_the_rest_by_forecast(): void
    {
        $fast = $this->sku('fl-01-1-10', 1.0);
        $slow = $this->sku('fl-01-1-15', 0.5, 1);
        $this->stock($fast, ['boss_rfc' => 100]);
        $this->stock($slow, ['boss_rfc' => 500]);
        // 12月に200入荷。fl-01-1-10 に50割り振り済み、残り150を 1.0 : 0.5 で仮に割り振る（100 と 50）
        $this->plan('2026-12', 200, [$fast->id => 50]);
        $this->plan('2026-11', 999, received: true);

        $response = $this->getJson('/api/v1/inventory-trends');

        $response->assertJsonPath('data.0.skus.0.months.2.inbound', 50)
            ->assertJsonPath('data.0.skus.0.months.2.provisional_inbound', 100)
            ->assertJsonPath('data.0.skus.1.months.2.provisional_inbound', 50)
            ->assertJsonPath('data.0.skus.0.months.1.inbound', 0)
            // 12月：45 + 150 − 31 = 164、4月：164 − 31 − 28 − 31 − 30 = 44
            ->assertJsonPath('data.0.skus.0.months.2.ending_stock', 164)
            ->assertJsonPath('data.0.skus.0.months.6.ending_stock', 44)
            ->assertJsonPath('data.0.skus.0.warning', 'none')
            // 在庫が多くても過剰在庫の警告は出さない（K-046）
            ->assertJsonPath('data.0.skus.1.warning', 'none')
            ->assertJsonPath('data.0.unassignable_inbound', 0);
    }

    public function test_counts_overdue_inbound_in_the_current_month(): void
    {
        $sku = $this->sku('fl-01-1-10', 1.0);
        $this->stock($sku, ['boss_own' => 10]);
        $this->plan('2026-08', 5, [$sku->id => 5]);

        $response = $this->getJson('/api/v1/inventory-trends');

        $response->assertJsonPath('meta.overdue_inbound_count', 1)
            ->assertJsonPath('data.0.skus.0.months.0.inbound', 5)
            ->assertJsonPath('data.0.skus.0.months.0.shortfall', 10);
    }

    public function test_does_not_treat_a_shortfall_under_half_a_unit_as_a_shortage(): void
    {
        // 直近30日に20点（基準平均日販 1/3）。2月末までの需要は 25/3+10+31/3+31/3+28/3 ≒ 48.33 なので、在庫48では2月に0.33足りない
        $sku = Sku::factory()->create(['item_id' => $this->item->id, 'sku_code' => 'fl-01-1-10']);
        $this->sell($sku, '2026-10-06', 20);
        $this->stock($sku, ['boss_own' => 48]);

        $response = $this->getJson('/api/v1/inventory-trends');

        $response->assertJsonPath('data.0.skus.0.months.4.shortfall', 0.33)
            ->assertJsonPath('data.0.skus.0.months.5.shortfall', 10.33)
            // 1個未満の不足（2月）は欠品とみなさず、欠品する月は3月から
            ->assertJsonPath('data.0.skus.0.first_shortage_month', '2027-03');
    }

    public function test_does_not_calculate_skus_without_stock_records_or_forecasts_and_reports_inbound_it_cannot_share(): void
    {
        $noStock = $this->sku('fl-01-1-10', 1.0);
        $unpredictable = $this->sku('fl-01-1-15', 0, 1);
        $this->stock($unpredictable, ['boss_own' => 40]);
        $this->stock(Sku::factory()->create(['item_id' => null]), ['boss_own' => 1]);
        $this->plan('2026-11', 30);

        $response = $this->getJson('/api/v1/inventory-trends');

        $response->assertJsonPath('data.0.skus.0.sku_code', 'fl-01-1-10')
            ->assertJsonPath('data.0.skus.0.status', 'no_stock')
            ->assertJsonPath('data.0.skus.0.opening_stock', null)
            ->assertJsonPath('data.0.skus.0.months', null)
            ->assertJsonPath('data.0.skus.1.status', 'unpredictable')
            ->assertJsonPath('data.0.skus.1.opening_stock', 40)
            ->assertJsonPath('data.0.skus.1.forecast_reason_label', 'BOSS：直近180日に販売実績がありません')
            // 予測の出せる SKU（fl-01-1-10）があるので仮に割り振れる
            ->assertJsonPath('data.0.unassignable_inbound', 0);
        $this->assertNotNull($noStock);
    }

    public function test_reports_inbound_that_cannot_be_shared_when_no_sku_of_the_item_has_a_forecast(): void
    {
        $sku = $this->sku('fl-01-1-10', 0);
        $this->stock($sku, ['boss_own' => 40]);
        $this->plan('2026-11', 30);

        $this->getJson('/api/v1/inventory-trends')->assertJsonPath('data.0.unassignable_inbound', 30);
    }

    public function test_judges_shortages_in_the_month_set_on_the_screen(): void
    {
        // 1月から足りなくなる SKU（1つ目のテストと同じ）
        $sku = $this->sku('fl-01-1-10', 1.0);
        $this->stock($sku, ['boss_own' => 100]);
        InventoryTrendSetting::create(['check_month_offset' => 6]);
        $this->travel(1)->minutes();
        InventoryTrendSetting::create(['check_month_offset' => 2]);

        $this->getJson('/api/v1/inventory-trends')
            ->assertOk()
            ->assertJsonPath('meta.settings', ['check_month_offset' => 2, 'changed_at' => '2026-10-07T09:01:00+09:00'])
            ->assertJsonPath('meta.check_month', '2026-12')
            ->assertJsonPath('data.0.skus.0.warning', 'none')
            // 欠品する月は判定する月によらない
            ->assertJsonPath('data.0.skus.0.first_shortage_month', '2027-01');
    }
}
