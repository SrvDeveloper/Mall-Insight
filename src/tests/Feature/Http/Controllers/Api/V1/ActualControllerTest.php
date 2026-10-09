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
            ->assertJsonPath('meta.unit', 'month')
            ->assertJsonCount(13, 'meta.periods')
            // 2025年10月はどちらも取り込んでいない、11月は BOSS が途中から、1月は Amazon がまだ、4月はそろう、今月は途中まで
            ->assertJsonPath('meta.periods.0', ['period' => '2025-10', 'coverage' => 'none'])
            ->assertJsonPath('meta.periods.1.coverage', 'partial')
            ->assertJsonPath('meta.periods.3.coverage', 'partial')
            ->assertJsonPath('meta.periods.6', ['period' => '2026-04', 'coverage' => 'full'])
            ->assertJsonPath('meta.periods.12', ['period' => '2026-10', 'coverage' => 'partial'])
            ->assertJsonPath('meta.channels.0', ['channel' => 'boss', 'label' => 'BOSS', 'sales_data_from' => '2025-11-15', 'sales_data_to' => '2026-10-06'])
            ->assertJsonPath('meta.selection.item_count', 1)
            ->assertJsonPath('data.0.item_no', 'fl-01')
            ->assertJsonPath('data.0.skus.0.quantities', [null, 0, 0, 5, 0, 0, 0, 0, 0, 0, 0, 5, 1])
            // 金額は取り込んだ販売実績の金額（税込）の合計（このテストでは1個1,000円）
            ->assertJsonPath('data.0.skus.0.amounts', [null, 0, 0, 5000, 0, 0, 0, 0, 0, 0, 0, 5000, 1000])
            // グラフの積み上げのため、モールごとの内訳も返す（2026年9月は BOSS 3・Amazon 2）
            ->assertJsonPath('data.0.skus.0.channels.boss.quantities.11', 3)
            ->assertJsonPath('data.0.skus.0.channels.amazon.quantities.11', 2)
            ->assertJsonPath('data.0.skus.0.channels.amazon.amounts.12', 1000)
            ->assertJsonPath('data.0.skus.0.channels.amazon.quantities.0', null)
            ->assertJsonPath('data.0.skus.0.not_sold', false)
            // 廃番のSKUも、過去に売れているので出す
            ->assertJsonPath('data.0.skus.2.status_label', '廃番')
            ->assertJsonPath('data.0.skus.2.quantities.6', 4);

        $this->getJson('/api/v1/actuals/sales?scope=amazon')
            ->assertJsonPath('meta.periods.4.coverage', 'none')
            ->assertJsonPath('meta.periods.5.coverage', 'full')
            ->assertJsonPath('data.0.skus.0.quantities', [null, null, null, null, null, 0, 0, 0, 0, 0, 0, 2, 1])
            // 子ASINの無いSKUは Amazon で売っていない（K-042）
            ->assertJsonPath('data.0.skus.1.not_sold', true)
            // モールを選んだときの内訳は、そのモールだけ
            ->assertJsonPath('data.0.skus.0.channels.amazon.quantities.11', 2)
            ->assertJsonMissingPath('data.0.skus.0.channels.boss');

        $this->getJson('/api/v1/actuals/sales?scope=boss')
            ->assertJsonPath('data.0.skus.0.quantities', [null, 0, 0, 5, 0, 0, 0, 0, 0, 0, 0, 3, 0])
            ->assertJsonPath('data.0.skus.1.not_sold', false);
    }

    public function test_sums_sales_by_day_for_the_last_ninety_days(): void
    {
        // BOSS の販売実績は 2026-07-15 から 2026-10-06 まで。Amazon は取り込んでいない
        $other = Sku::factory()->create(['item_id' => null]);
        $this->sell($other, '2026-07-15', 1);
        $this->sell($other, '2026-10-06', 1);
        $sku = $this->sku('fl-01-1-10');
        $this->sell($sku, '2026-09-30', 3);
        $this->sell($sku, '2026-09-30', 2);
        $this->sell($sku, '2026-10-06', 1);

        $response = $this->getJson('/api/v1/actuals/sales?unit=day');

        // 直近90日は 2026-07-10 から 2026-10-07 まで
        $response->assertOk()
            ->assertJsonPath('meta.unit', 'day')
            ->assertJsonCount(90, 'meta.periods')
            ->assertJsonPath('meta.periods.0', ['period' => '2026-07-10', 'coverage' => 'none'])
            ->assertJsonPath('meta.periods.5', ['period' => '2026-07-15', 'coverage' => 'full'])
            // 今日（2026-10-07）の販売実績はまだ取り込まれていない
            ->assertJsonPath('meta.periods.89', ['period' => '2026-10-07', 'coverage' => 'none'])
            ->assertJsonPath('data.0.skus.0.quantities.89', null)
            ->assertJsonPath('data.0.skus.0.quantities.0', null)
            ->assertJsonPath('data.0.skus.0.quantities.5', 0)
            ->assertJsonPath('data.0.skus.0.quantities.82', 5)
            ->assertJsonPath('data.0.skus.0.amounts.82', 5000)
            ->assertJsonPath('data.0.skus.0.quantities.88', 1)
            ->assertJsonPath('data.0.skus.0.channels.boss.quantities.82', 5)
            ->assertJsonPath('data.0.skus.0.channels.amazon.quantities.82', 0);
        $this->getJson('/api/v1/actuals/sales?unit=week')->assertUnprocessable()->assertJsonValidationErrors('unit');
    }

    public function test_sums_sales_for_the_period_asked_for(): void
    {
        $this->sell(Sku::factory()->create(['item_id' => null]), '2025-01-01', 1);
        $sku = $this->sku('fl-01-1-10', 0, ['tq_color_no' => '1', 'tq_size' => '10']);
        $this->sell($sku, '2026-01-10', 5);
        $this->sell($sku, '2026-03-31', 2);
        $this->sell($sku, '2026-04-01', 9);

        $this->getJson('/api/v1/actuals/sales?from=2026-01&to=2026-03')
            ->assertOk()
            ->assertJsonPath('meta.from', '2026-01')
            ->assertJsonPath('meta.to', '2026-03')
            ->assertJsonPath('meta.periods.*.period', ['2026-01', '2026-02', '2026-03'])
            ->assertJsonPath('data.0.skus.0.quantities', [5, 0, 2])
            // カラー×度数の表に使う
            ->assertJsonPath('data.0.skus.0.color_no', '1')
            ->assertJsonPath('data.0.skus.0.size', '10');

        $this->getJson('/api/v1/actuals/sales?unit=day&from=2026-03-31&to=2026-04-01')
            ->assertOk()
            ->assertJsonPath('meta.periods.*.period', ['2026-03-31', '2026-04-01'])
            ->assertJsonPath('data.0.skus.0.quantities', [2, 9]);
    }

    public function test_rejects_a_period_that_is_reversed_in_the_future_too_long_or_malformed(): void
    {
        $this->getJson('/api/v1/actuals/sales?from=2026-05&to=2026-03')->assertUnprocessable()->assertJsonPath('errors.from.0', '開始は終了と同じか、それより前にしてください。');
        $this->getJson('/api/v1/actuals/sales?from=2026-09&to=2026-11')->assertUnprocessable()->assertJsonPath('errors.to.0', '終了は今月までにしてください。');
        $this->getJson('/api/v1/actuals/sales?from=2023-01&to=2026-10')->assertUnprocessable()->assertJsonPath('errors.from.0', '期間は36か月以内にしてください。');
        $this->getJson('/api/v1/actuals/sales?unit=day&from=2026-06-01&to=2026-10-07')->assertUnprocessable()->assertJsonPath('errors.from.0', '期間は92日以内にしてください。');
        $this->getJson('/api/v1/actuals/sales?unit=day&to=2026-10-08')->assertUnprocessable()->assertJsonPath('errors.to.0', '終了は今日までにしてください。');
        $this->getJson('/api/v1/actuals/sales?from=2026-1')->assertUnprocessable()->assertJsonValidationErrors('from');
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

    public function test_returns_only_the_requested_target_item_for_the_sku_screen(): void
    {
        Item::factory()->create(['item_no' => 'fl-02']);
        ItemSelection::current()->items()->create(['item_no' => 'fl-02', 'sales_rank' => 2, 'is_candidate' => true]);
        Item::factory()->create(['item_no' => 'not-selected']);
        $this->sku('fl-01-1-10');

        $this->getJson('/api/v1/actuals/sales?item_no=fl-01')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.item_no', 'fl-01');
        $this->getJson('/api/v1/actuals/sales')->assertJsonCount(2, 'data');
        // 対象品番でない品番は返さない
        $this->getJson('/api/v1/actuals/sales?item_no=not-selected')->assertOk()->assertJsonPath('data', []);
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
