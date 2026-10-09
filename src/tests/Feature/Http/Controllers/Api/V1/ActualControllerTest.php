<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActualControllerTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());

        // 今日は 2026-10-07。販売実績は 2025年10月～2026年10月の13か月、在庫は 2026-09-07～2026-10-07 の31日
        $this->travelTo('2026-10-07 09:00:00');
        $this->item = Item::factory()->create(['item_no' => 'fl-01']);
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-08', 'ranking_to' => '2026-10-07']);
        $selection->items()->create(['item_no' => 'fl-01', 'sales_rank' => 1, 'is_candidate' => true]);
    }

    private function sku(string $code, int $position = 0, array $attributes = []): Sku
    {
        return Sku::factory()->create(['item_id' => $this->item->id, 'sku_code' => $code, 'position' => $position, ...$attributes]);
    }

    private function sell(Sku $sku, string $date, int $quantity, string $warehouse = 'boss_own'): void
    {
        static $orderId = 1;

        $isAmazon = str_starts_with($warehouse, 'amazon');
        SalesLine::create(['source' => $isAmazon ? 'amazon' : 'boss', 'source_order_id' => (string) $orderId++, 'sales_date' => $date, 'mall' => $isAmazon ? 'amazon' : 'rakuten', 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity, 'amount' => 1000 * $quantity]);
    }

    /**
     * @param  array<string, int>  $quantities
     */
    private function stock(Sku $sku, string $date, array $quantities): void
    {
        foreach ($quantities as $warehouse => $quantity) {
            Inventory::create(['stock_date' => $date, 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity]);
        }
    }

    public function test_sums_sales_by_month_and_mall_and_leaves_months_without_sales_data_empty(): void
    {
        // BOSS の販売実績は 2025-11-15 から、Amazon は 2026-03-01 から（どちらも 2026-10-06 まで）
        $other = Sku::factory()->create(['item_id' => null]);
        $this->sell($other, '2025-11-15', 1);
        $this->sell($other, '2026-03-01', 1, 'amazon_fba');
        $this->sell($other, '2026-10-06', 1);
        $this->sell($other, '2026-10-06', 1, 'amazon_fba');
        $sku = $this->sku('fl-01-1-10');
        $noAsin = $this->sku('fl-01-1-15', 1, ['child_asin' => null]);
        $discontinued = $this->sku('fl-01-1-20', 2, ['status' => 'inactive']);
        $this->sell($sku, '2026-01-10', 5);
        $this->sell($sku, '2026-09-30', 3);
        $this->sell($sku, '2026-09-15', 2, 'amazon_fba');
        $this->sell($sku, '2026-10-02', 1, 'amazon_fba');
        $this->sell($discontinued, '2026-04-01', 4);

        $this->getJson('/api/v1/actuals/sales')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'total')
            ->assertJsonCount(13, 'meta.months')
            // 2025年10月はどちらも取り込んでいない、11月は BOSS が途中から、1月は Amazon がまだ、4月はそろう、今月は途中まで
            ->assertJsonPath('meta.months.0', ['month' => '2025-10', 'coverage' => 'none'])
            ->assertJsonPath('meta.months.1.coverage', 'partial')
            ->assertJsonPath('meta.months.3.coverage', 'partial')
            ->assertJsonPath('meta.months.6', ['month' => '2026-04', 'coverage' => 'full'])
            ->assertJsonPath('meta.months.12', ['month' => '2026-10', 'coverage' => 'partial'])
            ->assertJsonPath('meta.channels.0', ['channel' => 'boss', 'label' => 'BOSS', 'sales_data_from' => '2025-11-15', 'sales_data_to' => '2026-10-06'])
            ->assertJsonPath('meta.selection.item_count', 1)
            ->assertJsonPath('data.0.item_no', 'fl-01')
            ->assertJsonPath('data.0.skus.0.quantities', [null, 0, 0, 5, 0, 0, 0, 0, 0, 0, 0, 5, 1])
            // 金額は取り込んだ販売実績の金額（税込）の合計（このテストでは1個1,000円）
            ->assertJsonPath('data.0.skus.0.amounts', [null, 0, 0, 5000, 0, 0, 0, 0, 0, 0, 0, 5000, 1000])
            ->assertJsonPath('data.0.skus.0.not_sold', false)
            // 廃番のSKUも、過去に売れているので出す
            ->assertJsonPath('data.0.skus.2.status_label', '廃番')
            ->assertJsonPath('data.0.skus.2.quantities.6', 4);

        $this->getJson('/api/v1/actuals/sales?scope=amazon')
            ->assertJsonPath('meta.months.4.coverage', 'none')
            ->assertJsonPath('meta.months.5.coverage', 'full')
            ->assertJsonPath('data.0.skus.0.quantities', [null, null, null, null, null, 0, 0, 0, 0, 0, 0, 2, 1])
            // 子ASINの無いSKUは Amazon で売っていない（K-042）
            ->assertJsonPath('data.0.skus.1.not_sold', true);

        $this->getJson('/api/v1/actuals/sales?scope=boss')
            ->assertJsonPath('data.0.skus.0.quantities', [null, 0, 0, 5, 0, 0, 0, 0, 0, 0, 0, 3, 0])
            ->assertJsonPath('data.0.skus.1.not_sold', false);
    }

    public function test_lists_the_daily_stock_of_the_mall_and_leaves_days_without_data_empty(): void
    {
        $sku = $this->sku('fl-01-1-10');
        $unrecorded = $this->sku('fl-01-1-15', 1);
        $this->stock($sku, '2026-10-05', ['amazon_fba' => 10, 'boss_own' => 20, 'free_stock' => 5]);
        $this->stock($sku, '2026-10-06', ['amazon_fba' => 8]);
        $this->stock(Sku::factory()->create(['item_id' => null]), '2026-08-31', ['boss_own' => 1]);

        $this->getJson('/api/v1/actuals/stock')
            ->assertOk()
            ->assertJsonCount(31, 'meta.dates')
            ->assertJsonPath('meta.dates.0', ['date' => '2026-09-07', 'has_data' => false])
            ->assertJsonPath('meta.dates.28', ['date' => '2026-10-05', 'has_data' => true])
            ->assertJsonPath('meta.dates.30', ['date' => '2026-10-07', 'has_data' => false])
            ->assertJsonPath('meta.scope_warehouses.4.label', 'フリー在庫')
            // 全体は6区分の合計。在庫を取得していない日と、記録の無いSKUは null
            ->assertJsonPath('data.0.skus.0.quantities.28', 35)
            ->assertJsonPath('data.0.skus.0.quantities.29', 8)
            ->assertJsonPath('data.0.skus.0.quantities.30', null)
            ->assertJsonPath('data.0.skus.1.quantities.28', null)
            ->assertJsonMissingPath('data.0.skus.0.amounts');

        $this->getJson('/api/v1/actuals/stock?scope=amazon')
            ->assertJsonPath('meta.scope_warehouses.*.label', ['Amazon自社出荷', 'Amazon FBA'])
            ->assertJsonPath('data.0.skus.0.quantities.28', 10)
            ->assertJsonPath('data.0.skus.0.quantities.29', 8);

        $this->getJson('/api/v1/actuals/stock?scope=boss')
            ->assertJsonPath('data.0.skus.0.quantities.28', 20)
            ->assertJsonPath('data.0.skus.0.quantities.29', null);
    }

    public function test_returns_no_items_until_target_items_are_confirmed(): void
    {
        ItemSelection::query()->delete();

        $this->getJson('/api/v1/actuals/sales')->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.selection', null);
        $this->getJson('/api/v1/actuals/stock')->assertOk()->assertJsonPath('data', []);
    }

    public function test_rejects_an_unknown_scope(): void
    {
        $this->getJson('/api/v1/actuals/sales?scope=rakuten')->assertUnprocessable()->assertJsonValidationErrors('scope');
    }
}
