<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Enums\Channel;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\ItemUnitPrice;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use App\Services\Pricing\UnitPriceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemUnitPriceControllerTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create(['name' => '単価 担当']));
        $this->travelTo('2026-10-08 09:00:00');

        // BOSS で 10個×5,500円、Amazon で 30個×3,700円（直近12か月）、期間外に 1個×99,999円
        $this->item = Item::factory()->create(['item_no' => 'fisi-05']);
        $sku = Sku::factory()->for($this->item)->create(['sku_code' => 'fisi-05-1-10']);
        $this->sell($sku, '2026-09-01', 'boss_own', 10, 55000);
        $this->sell($sku, '2026-09-02', 'amazon_fba', 30, 111000);
        $this->sell($sku, '2025-10-08', 'boss_own', 1, 99999);
        Item::factory()->create(['item_no' => 'aa-01']);
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-09', 'ranking_to' => '2026-10-08']);
        $selection->items()->create(['item_no' => 'fisi-05', 'sales_rank' => 1, 'is_candidate' => true]);
    }

    private function sell(Sku $sku, string $date, string $warehouse, int $quantity, int $amount): void
    {
        static $orderId = 1;

        SalesLine::create(['source' => str_starts_with($warehouse, 'amazon') ? 'amazon' : 'boss', 'source_order_id' => (string) $orderId++, 'sales_date' => $date, 'mall' => 'rakuten', 'sku_id' => $sku->id, 'warehouse' => $warehouse, 'quantity' => $quantity, 'amount' => $amount]);
    }

    public function test_lists_registered_prices_next_to_the_average_prices_of_each_mall(): void
    {
        ItemUnitPrice::create(['item_no' => 'fisi-05', 'unit_price' => 4400, 'source' => ChangeSource::Excel]);

        $this->getJson('/api/v1/item-unit-prices')
            ->assertOk()
            ->assertJsonPath('meta.sales_from', '2025-10-09')
            ->assertJsonPath('data.*.item_no', ['aa-01', 'fisi-05'])
            ->assertJsonPath('data.0.registered', null)
            ->assertJsonPath('data.0.actual.all', ['quantity' => 0, 'unit_price' => null])
            ->assertJsonPath('data.1.is_target_item', true)
            ->assertJsonPath('data.1.registered.unit_price', 4400)
            ->assertJsonPath('data.1.registered.amazon_unit_price', null)
            ->assertJsonPath('data.1.registered.source_label', '現行Excelから取り込み')
            // 全体 166,000÷40 = 4,150、BOSS 5,500、Amazon 3,700
            ->assertJsonPath('data.1.actual.all', ['quantity' => 40, 'unit_price' => 4150])
            ->assertJsonPath('data.1.actual.boss', ['quantity' => 10, 'unit_price' => 5500])
            ->assertJsonPath('data.1.actual.amazon', ['quantity' => 30, 'unit_price' => 3700]);
    }

    public function test_registers_prices_per_mall_and_clears_them(): void
    {
        $this->putJson('/api/v1/item-unit-prices/fisi-05', ['unit_price' => 4400, 'amazon_unit_price' => 3700, 'boss_unit_price' => null])
            ->assertOk()
            ->assertJsonPath('data.amazon_unit_price', 3700);
        $this->getJson('/api/v1/item-unit-prices')
            ->assertJsonPath('data.1.registered.boss_unit_price', null)
            ->assertJsonPath('data.1.registered.changed_by', '単価 担当')
            ->assertJsonPath('data.1.registered.source_label', '画面');

        $this->putJson('/api/v1/item-unit-prices/fisi-05', ['unit_price' => null, 'amazon_unit_price' => null, 'boss_unit_price' => null])->assertOk();
        $this->assertSame(0, ItemUnitPrice::count());

        $this->putJson('/api/v1/item-unit-prices/fisi-05', ['unit_price' => 0, 'amazon_unit_price' => null, 'boss_unit_price' => null])->assertUnprocessable()->assertJsonValidationErrors('unit_price');
        $this->putJson('/api/v1/item-unit-prices/fisi-05', ['unit_price' => 100])->assertUnprocessable()->assertJsonValidationErrors(['amazon_unit_price', 'boss_unit_price']);
        $this->putJson('/api/v1/item-unit-prices/missing', ['unit_price' => 100, 'amazon_unit_price' => null, 'boss_unit_price' => null])->assertNotFound();
    }

    public function test_falls_back_from_the_mall_price_to_the_overall_price_and_then_to_the_average(): void
    {
        $catalog = fn (): UnitPriceCatalog => UnitPriceCatalog::build(Item::query()->with('skus')->get(), CarbonImmutable::now());

        $this->assertSame(3700.0, $catalog()->channelPrice('fisi-05', Channel::Amazon));
        ItemUnitPrice::create(['item_no' => 'fisi-05', 'unit_price' => 4400, 'source' => ChangeSource::Screen]);
        $this->assertSame(4400.0, $catalog()->channelPrice('fisi-05', Channel::Amazon));
        ItemUnitPrice::query()->update(['amazon_unit_price' => 3600]);
        $this->assertSame(3600.0, $catalog()->channelPrice('fisi-05', Channel::Amazon));
        $this->assertSame(4400.0, $catalog()->channelPrice('fisi-05', Channel::Boss));
        $this->assertNull($catalog()->channelPrice('aa-01', Channel::Boss));
    }
}
