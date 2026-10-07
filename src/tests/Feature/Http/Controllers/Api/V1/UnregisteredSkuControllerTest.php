<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\SalesLine;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnregisteredSkuControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
    }

    public function test_lists_skus_without_an_item_by_sales_quantity_in_the_last_12_months(): void
    {
        Sku::factory()->create(['sku_code' => 'registered-1']);
        $few = $this->unregisteredSku('few-1');
        $many = $this->unregisteredSku('many-1');
        $old = $this->unregisteredSku('old-1');
        $this->sell($few, '2026-10-07', 2);
        $this->sell($many, '2025-10-08', 3);
        $this->sell($many, '2026-09-30', 4);
        $this->sell($old, '2025-10-07', 9);

        $response = $this->getJson('/api/v1/unregistered-skus');

        $response->assertOk()
            ->assertJsonPath('data.*.sku_code', ['many-1', 'few-1', 'old-1'])
            ->assertJsonPath('data.0.recent_sales_quantity', 7)
            ->assertJsonPath('data.2.recent_sales_quantity', 0)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.sales_from', '2025-10-08')
            ->assertJsonPath('meta.sales_to', '2026-10-07');
    }

    public function test_shows_when_a_sku_first_and_last_appeared_in_sales_and_inventory_and_skips_skus_seen_nowhere(): void
    {
        $sku = $this->unregisteredSku('seen-1');
        $this->sell($sku, '2025-03-01', 1);
        $this->sell($sku, '2026-08-20', 1);
        Inventory::create(['stock_date' => '2026-09-16', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 5]);
        Inventory::create(['stock_date' => '2026-10-06', 'sku_id' => $sku->id, 'warehouse' => 'boss_own', 'quantity' => 0]);
        $stockOnly = $this->unregisteredSku('stock-only-1');
        Inventory::create(['stock_date' => '2026-10-06', 'sku_id' => $stockOnly->id, 'warehouse' => 'free_stock', 'quantity' => 3]);
        $this->unregisteredSku('never-seen-1');

        $response = $this->getJson('/api/v1/unregistered-skus');

        $response->assertOk()->assertJsonPath('data', [
            [
                'id' => $sku->id,
                'sku_code' => 'seen-1',
                'recent_sales_quantity' => 1,
                'first_sold_on' => '2025-03-01',
                'last_sold_on' => '2026-08-20',
                'first_stocked_on' => '2026-09-16',
                'last_stocked_on' => '2026-10-06',
            ],
            [
                'id' => $stockOnly->id,
                'sku_code' => 'stock-only-1',
                'recent_sales_quantity' => 0,
                'first_sold_on' => null,
                'last_sold_on' => null,
                'first_stocked_on' => '2026-10-06',
                'last_stocked_on' => '2026-10-06',
            ],
        ]);
    }

    public function test_returns_an_empty_list_when_every_sku_belongs_to_an_item(): void
    {
        $sku = Sku::factory()->create();
        $this->sell($sku, '2026-10-01', 1);

        $this->getJson('/api/v1/unregistered-skus')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    private function unregisteredSku(string $code): Sku
    {
        return Sku::factory()->create(['item_id' => null, 'sku_code' => $code, 'status' => null, 'child_asin' => null]);
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
}
