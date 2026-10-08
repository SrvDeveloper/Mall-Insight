<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Inventory;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryDateControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());
    }

    public function test_lists_stored_stock_dates_newest_first_with_sku_counts(): void
    {
        [$first, $second] = Sku::factory()->count(2)->create();
        $this->travelTo('2026-10-05 10:00:00');
        Inventory::create(['stock_date' => '2026-10-05', 'sku_id' => $first->id, 'warehouse' => 'amazon_fba', 'quantity' => 1]);
        Inventory::create(['stock_date' => '2026-10-05', 'sku_id' => $first->id, 'warehouse' => 'boss_own', 'quantity' => 1]);
        $this->travelTo('2026-10-06 10:00:00');
        Inventory::create(['stock_date' => '2026-10-06', 'sku_id' => $first->id, 'warehouse' => 'amazon_fba', 'quantity' => 1]);
        Inventory::create(['stock_date' => '2026-10-06', 'sku_id' => $second->id, 'warehouse' => 'amazon_fba', 'quantity' => 1]);

        $response = $this->getJson('/api/v1/inventory-dates');

        $response->assertOk()
            ->assertJsonPath('data', [
                ['stock_date' => '2026-10-06', 'sku_count' => 2, 'fetched_at' => '2026-10-06T10:00:00+09:00'],
                ['stock_date' => '2026-10-05', 'sku_count' => 1, 'fetched_at' => '2026-10-05T10:00:00+09:00'],
            ])
            ->assertJsonPath('meta.total', 2);
    }
}
