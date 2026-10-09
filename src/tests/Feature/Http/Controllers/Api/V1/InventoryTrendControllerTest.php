<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Enums\RatioCategoryCode;
use App\Models\InboundPlan;
use App\Models\Inventory;
use App\Models\InventoryTrendSetting;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\MonthlySalesRatio;
use App\Models\RatioCategory;
use App\Models\SalesLine;
use App\Models\SalesTarget;
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

    private function sell(Sku $sku, string $date, int $quantity, string $warehouse = 'boss_own'): void
    {
        static $orderId = 1;

        $mall = str_starts_with($warehouse, 'amazon') ? 'amazon' : 'rakuten';
        SalesLine::create(['source' => $mall === 'amazon' ? 'amazon' : 'boss', 'source_order_id' => (string) $orderId++, 'sales_date' => $date, 'mall' => $mall, 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity, 'amount' => 1000 * $quantity]);
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
            ->assertJsonPath('meta.basis', 'forecast')
            ->assertJsonPath('meta.months.0', ['month' => '2026-10', 'days' => 31])
            ->assertJsonPath('meta.months.11', ['month' => '2027-09', 'days' => 30])
            ->assertJsonPath('meta.sales_targets', null)
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
            ->assertJsonPath('data.0.skus.0.uncalculated_months_label', null)
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

    /**
     * 販売試算の前提。老眼の比率は4月～9月が5%・5%・5%・5%・10%・10%、10月～3月が各10%。fl-01 は老眼鏡（老眼の比率、K-058）。
     * 2026年度の年間販売目標 1,240 なら、10月～3月の品番の月間販売目標は各124。
     */
    private function salesTargets(int ...$annualByYear): void
    {
        $this->item->update(['category' => '老眼鏡']);
        MonthlySalesRatio::create([
            'ratio_category_id' => RatioCategory::forCode(RatioCategoryCode::Reading)->id,
            'ratios' => [500, 500, 500, 500, 1000, 1000, 1000, 1000, 1000, 1000, 1000, 1000],
            'source' => ChangeSource::Screen,
        ]);
        foreach ($annualByYear as $offset => $annual) {
            SalesTarget::create(['fiscal_year' => 2026 + $offset, 'item_no' => 'fl-01', 'annual_quantity' => $annual, 'source' => ChangeSource::Screen]);
        }
    }

    public function test_projects_the_stock_by_the_sales_target_and_does_not_calculate_months_of_the_next_fiscal_year_without_a_target(): void
    {
        // 直近12か月の販売数は 90 : 30 なので、品番の月間販売目標124を 93 と 31 に割り振る
        $fast = $this->sku('fl-01-1-10', 1.5);
        $slow = $this->sku('fl-01-1-15', 0.5, 1);
        $this->stock($fast, ['boss_own' => 200]);
        $this->stock($slow, ['boss_own' => 10]);
        $this->salesTargets(1240);
        // 11月の40は、11月のSKUの目標 93 : 31 で仮に割り振る（30 と 10）。2027年5月は計算できない月なので数えない
        $this->plan('2026-11', 40);
        $this->plan('2027-05', 50);

        $response = $this->getJson('/api/v1/inventory-trends?basis=sales_target');

        $response->assertOk()
            ->assertJsonPath('meta.basis', 'sales_target')
            ->assertJsonPath('meta.basis_label', '販売試算')
            ->assertJsonPath('meta.check_month', '2027-04')
            ->assertJsonPath('meta.sales_targets', [
                ['fiscal_year' => 2026, 'from' => '2026-10', 'to' => '2027-03', 'calculated_item_count' => 1],
                ['fiscal_year' => 2027, 'from' => '2027-04', 'to' => '2027-09', 'calculated_item_count' => 0],
            ])
            ->assertJsonPath('data.0.skus.0.status', 'calculated')
            // 今月は25日分（93×25/31＝75）
            ->assertJsonPath('data.0.skus.0.months.0.demand', 75)
            ->assertJsonPath('data.0.skus.0.months.1.demand', 93)
            ->assertJsonPath('data.0.skus.0.months.1.provisional_inbound', 30)
            ->assertJsonPath('data.0.skus.1.months.1.provisional_inbound', 10)
            // 10月 200 − 75 = 125、11月 125 + 30 − 93 = 62、12月 62 − 93 → 31 足りない
            ->assertJsonPath('data.0.skus.0.months.*.ending_stock', [125, 62, 0, 0, 0, 0])
            ->assertJsonPath('data.0.skus.0.months.2.shortfall', 31)
            ->assertJsonPath('data.0.skus.0.first_shortage_month', '2026-12')
            ->assertJsonPath('data.0.skus.0.uncalculated_months_label', '2027年度：年間販売目標が登録されていません')
            // 判定する月（2027年4月）は計算できないため、欠品警告ではなく判定できない
            ->assertJsonPath('data.0.skus.0.warning', 'undetermined')
            ->assertJsonPath('data.0.skus.0.warning_label', '判定できない')
            ->assertJsonPath('data.0.skus.0.is_partial_forecast', false)
            ->assertJsonPath('data.0.skus.0.forecast_reason_label', null)
            ->assertJsonPath('data.0.unassignable_inbound', 0);
    }

    public function test_projects_twelve_months_when_the_next_fiscal_year_also_has_a_target(): void
    {
        $fast = $this->sku('fl-01-1-10', 1.5);
        $this->sku('fl-01-1-15', 0.5, 1);
        $this->stock($fast, ['boss_own' => 200]);
        // 2027年度の4月は 1,240 × 5% ＝ 62 を 46.5 と 15.5 に割り振る
        $this->salesTargets(1240, 1240);

        $response = $this->getJson('/api/v1/inventory-trends?basis=sales_target');

        $response->assertJsonCount(12, 'data.0.skus.0.months')
            ->assertJsonPath('meta.sales_targets.1.calculated_item_count', 1)
            ->assertJsonPath('data.0.skus.0.months.6.month', '2027-04')
            ->assertJsonPath('data.0.skus.0.months.6.demand', 46.5)
            ->assertJsonPath('data.0.skus.0.months.6.shortfall', 46.5)
            ->assertJsonPath('data.0.skus.0.warning', 'shortage')
            ->assertJsonPath('data.0.skus.0.uncalculated_months_label', null)
            // 需要予測の在庫推移は販売目標を使わない（原則1）
            ->assertJsonPath('data.0.skus.1.status', 'no_stock');
        $this->getJson('/api/v1/inventory-trends')->assertJsonPath('data.0.skus.0.months.1.demand', 45);
    }

    public function test_does_not_calculate_items_without_a_sales_target_this_fiscal_year(): void
    {
        $sku = $this->sku('fl-01-1-10', 1.0);
        $this->stock($sku, ['boss_own' => 40]);
        $this->salesTargets();
        $this->plan('2026-11', 30);

        $this->getJson('/api/v1/inventory-trends?basis=sales_target')
            ->assertOk()
            ->assertJsonPath('meta.sales_targets.0.calculated_item_count', 0)
            ->assertJsonPath('data.0.skus.0.status', 'no_sales_target')
            ->assertJsonPath('data.0.skus.0.status_label', '2026年度：年間販売目標が登録されていません')
            ->assertJsonPath('data.0.skus.0.opening_stock', 40)
            ->assertJsonPath('data.0.skus.0.months', null)
            ->assertJsonPath('data.0.skus.0.average_daily', null)
            ->assertJsonPath('data.0.skus.0.warning', 'none')
            // 推移を計算できない品番の入荷予定は、割り振れない数として出さない
            ->assertJsonPath('data.0.unassignable_inbound', 0);
    }

    public function test_rejects_an_unknown_basis(): void
    {
        $this->getJson('/api/v1/inventory-trends?basis=excel')->assertUnprocessable()->assertJsonValidationErrors('basis');
    }

    public function test_projects_amazon_and_boss_from_their_own_stock_and_forecast_without_inbound(): void
    {
        // Amazon の販売実績も 2025-10-01 から。fl-01-1-10 は BOSS 1.0・Amazon 0.5 の基準平均日販
        $this->sell(Sku::factory()->create(['item_id' => null]), '2025-10-01', 1, 'amazon_fba');
        $sku = $this->sku('fl-01-1-10', 1.0);
        $this->sell($sku, '2026-10-06', 30, 'amazon_fba');
        $this->stock($sku, ['amazon_own' => 10, 'amazon_fba' => 50, 'boss_own' => 30, 'boss_rfc' => 5, 'free_stock' => 100, 'ec_stock' => 20]);
        // 入荷予定は社内在庫に入るものとし、モールの推移には入れない
        $this->plan('2026-11', 999);
        $this->plan('2026-08', 5, [$sku->id => 5]);

        $this->getJson('/api/v1/inventory-trends?scope=amazon')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'amazon')
            ->assertJsonPath('meta.scope_label', 'Amazon')
            ->assertJsonPath('meta.scope_warehouses.*.label', ['Amazon自社出荷', 'Amazon FBA'])
            ->assertJsonPath('meta.overdue_inbound_count', 0)
            ->assertJsonPath('data.0.unassignable_inbound', 0)
            ->assertJsonPath('data.0.skus.0.opening_stock', 60)
            // フリー在庫・ECストックは推移に含めず、内訳に参考として出す
            ->assertJsonPath('data.0.skus.0.stock_by_warehouse.4.quantity', 100)
            ->assertJsonPath('data.0.skus.0.average_daily', 0.5)
            // 10月 25日分 12.5 → 47.5、11月 15 → 32.5、12月 15.5 → 17、1月 15.5 → 1.5、2月 14 → 12.5 足りない
            ->assertJsonPath('data.0.skus.0.months.*.ending_stock', [47.5, 32.5, 17, 1.5, 0, 0, 0, 0, 0, 0, 0, 0])
            ->assertJsonPath('data.0.skus.0.months.1.inbound', 0)
            ->assertJsonPath('data.0.skus.0.months.1.provisional_inbound', 0)
            ->assertJsonPath('data.0.skus.0.months.4.shortfall', 12.5)
            ->assertJsonPath('data.0.skus.0.first_shortage_month', '2027-02')
            ->assertJsonPath('data.0.skus.0.warning', 'shortage');

        $this->getJson('/api/v1/inventory-trends?scope=boss')
            ->assertJsonPath('meta.scope_warehouses.*.label', ['BOSS自社倉庫', 'BOSS RFC'])
            ->assertJsonPath('data.0.skus.0.opening_stock', 35)
            ->assertJsonPath('data.0.skus.0.average_daily', 1)
            // 10月 25 → 10、11月 30 → 20 足りない
            ->assertJsonPath('data.0.skus.0.months.0.ending_stock', 10)
            ->assertJsonPath('data.0.skus.0.months.1.shortfall', 20)
            ->assertJsonPath('data.0.skus.0.first_shortage_month', '2026-11');

        // 全体は今までどおり（6区分の合計、入荷予定を足す）
        $this->getJson('/api/v1/inventory-trends')
            ->assertJsonPath('meta.scope', 'total')
            ->assertJsonPath('meta.overdue_inbound_count', 1)
            ->assertJsonPath('data.0.skus.0.opening_stock', 215)
            ->assertJsonPath('data.0.skus.0.average_daily', 1.5)
            ->assertJsonPath('data.0.skus.0.months.1.provisional_inbound', 999);
    }

    public function test_explains_skus_that_are_not_sold_or_cannot_be_forecast_on_the_mall(): void
    {
        // Amazon の販売実績は 2025-10-01 から 2026-10-06 まで（対象品番でないSKU）
        $other = Sku::factory()->create(['item_id' => null]);
        $this->sell($other, '2025-10-01', 1, 'amazon_fba');
        $this->sell($other, '2026-10-06', 1, 'amazon_fba');
        // 子ASINの無いSKUは Amazon で売っていない（K-042）。もう1つは Amazon で売っているが直近180日に売れていない
        $noAsin = Sku::factory()->create(['item_id' => $this->item->id, 'sku_code' => 'fl-01-1-10', 'child_asin' => null]);
        $this->sell($noAsin, '2026-10-06', 60);
        $unsold = $this->sku('fl-01-1-15', 1.0, 1);
        $this->stock($noAsin, ['amazon_fba' => 0, 'boss_own' => 10]);
        $this->stock($unsold, ['amazon_fba' => 5, 'boss_own' => 10]);

        $this->getJson('/api/v1/inventory-trends?scope=amazon')
            ->assertJsonPath('data.0.skus.0.status', 'not_sold')
            ->assertJsonPath('data.0.skus.0.status_label', 'Amazonでは販売していません')
            ->assertJsonPath('data.0.skus.0.months', null)
            ->assertJsonPath('data.0.skus.1.status', 'unpredictable')
            ->assertJsonPath('data.0.skus.1.status_label', 'Amazon：直近180日に販売実績がありません');
        $this->getJson('/api/v1/inventory-trends?scope=boss')->assertJsonPath('data.0.skus.0.status', 'calculated');
    }

    public function test_splits_the_sales_target_into_malls_by_the_sales_of_the_last_twelve_months(): void
    {
        // 直近12か月の販売数は BOSS 60・Amazon 20 なので、Amazon は目標の 1/4
        $sku = $this->sku('fl-01-1-10', 1.0);
        $this->sell($sku, '2026-09-01', 20, 'amazon_fba');
        $this->stock($sku, ['amazon_fba' => 100, 'boss_own' => 100]);
        $this->salesTargets(1240);

        $this->getJson('/api/v1/inventory-trends?basis=sales_target&scope=amazon')
            ->assertOk()
            // 10月の目標 124 の 1/4 ＝ 31、今月は25日分で 25
            ->assertJsonPath('data.0.skus.0.months.0.demand', 25)
            ->assertJsonPath('data.0.skus.0.months.1.demand', 31)
            ->assertJsonPath('data.0.skus.0.months.1.ending_stock', 44);
        $this->getJson('/api/v1/inventory-trends?basis=sales_target&scope=boss')
            ->assertJsonPath('data.0.skus.0.months.1.demand', 93);
    }

    public function test_does_not_split_the_sales_target_into_malls_without_any_sales(): void
    {
        $sku = Sku::factory()->create(['item_id' => $this->item->id, 'sku_code' => 'fl-01-1-10']);
        $this->stock($sku, ['amazon_fba' => 10]);
        $this->salesTargets();
        SalesTarget::create(['fiscal_year' => 2026, 'item_no' => 'fl-01', 'annual_quantity' => 1200, 'sku_quantities' => [$sku->id => 1200], 'source' => ChangeSource::Screen]);

        $this->getJson('/api/v1/inventory-trends?basis=sales_target&scope=amazon')
            ->assertJsonPath('data.0.skus.0.status', 'no_sales_target')
            ->assertJsonPath('data.0.skus.0.status_label', '直近12か月の販売実績が無く、販売目標をモールに分けられません');
        $this->getJson('/api/v1/inventory-trends?basis=sales_target')->assertJsonPath('data.0.skus.0.status', 'calculated');
    }

    public function test_rejects_an_unknown_scope(): void
    {
        $this->getJson('/api/v1/inventory-trends?scope=rakuten')->assertUnprocessable()->assertJsonValidationErrors('scope');
    }
}
