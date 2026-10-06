<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Item;
use App\Models\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_items_with_skus_ordered_by_item_no_and_sku_position(): void
    {
        $item = Item::factory()->create([
            'item_no' => 'fisi-05',
            'brand' => 'SHIORI',
            'category' => '老眼鏡',
            'parent_asin' => 'B09T32PVM5',
            'crosswalker_updated_at' => '2026-09-08 16:08:16',
        ]);
        Sku::factory()->for($item)->create(['sku_code' => 'fisi-05-1-15', 'child_asin' => 'B0SKU00002', 'tq_item_no' => 'FISI05', 'tq_color_no' => '1', 'tq_size' => '15', 'position' => 1]);
        Sku::factory()->for($item)->inactive()->create(['sku_code' => 'fisi-05-1-10', 'child_asin' => null, 'tq_item_no' => 'FISI05', 'tq_color_no' => '1', 'tq_size' => '10', 'position' => 0]);
        Item::factory()->create(['item_no' => 'bdr-1001']);

        $response = $this->getJson('/api/v1/items');

        $response->assertOk()
            ->assertJsonPath('data.0.item_no', 'bdr-1001')
            ->assertJsonPath('data.1', [
                'id' => $item->id,
                'item_no' => 'fisi-05',
                'brand' => 'SHIORI',
                'category' => '老眼鏡',
                'parent_asin' => 'B09T32PVM5',
                'status' => 'active',
                'status_label' => '有効',
                'crosswalker_updated_at' => '2026-09-08T16:08:16+09:00',
                'skus' => [
                    ['id' => $item->skus[0]->id, 'sku_code' => 'fisi-05-1-10', 'child_asin' => null, 'status' => 'inactive', 'status_label' => '無効', 'tq_item_no' => 'FISI05', 'tq_color_no' => '1', 'tq_size' => '10'],
                    ['id' => $item->skus[1]->id, 'sku_code' => 'fisi-05-1-15', 'child_asin' => 'B0SKU00002', 'status' => 'active', 'status_label' => '有効', 'tq_item_no' => 'FISI05', 'tq_color_no' => '1', 'tq_size' => '15'],
                ],
            ])
            ->assertJsonPath('meta.total', 2);
    }

    public function test_returns_active_and_inactive_items_by_default(): void
    {
        Item::factory()->create(['item_no' => 'a-active']);
        Item::factory()->inactive()->create(['item_no' => 'b-inactive']);

        $response = $this->getJson('/api/v1/items');

        $response->assertJsonPath('data.*.item_no', ['a-active', 'b-inactive']);
    }

    public function test_status_filter_returns_only_matching_items(): void
    {
        Item::factory()->create(['item_no' => 'a-active']);
        Item::factory()->inactive()->create(['item_no' => 'b-inactive']);

        $response = $this->getJson('/api/v1/items?status=inactive');

        $response->assertJsonPath('data.*.item_no', ['b-inactive']);
    }

    public function test_keyword_matches_item_no_or_sku_code(): void
    {
        Item::factory()->create(['item_no' => 'si-01sap']);
        $matchedBySku = Item::factory()->create(['item_no' => 'rf-03']);
        Sku::factory()->for($matchedBySku)->create(['sku_code' => 'rf-03-SAP-10']);
        Item::factory()->create(['item_no' => 'fl-3001']);

        $response = $this->getJson('/api/v1/items?keyword=sap');

        $response->assertJsonPath('data.*.item_no', ['rf-03', 'si-01sap']);
    }

    public function test_paginates_with_per_page_and_keeps_query_in_links(): void
    {
        Item::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/items?per_page=2&status=all');

        $response->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('links.next', url('/api/v1/items?per_page=2&status=all&page=2'));
    }

    public function test_returns_422_with_message_when_per_page_exceeds_100(): void
    {
        $response = $this->getJson('/api/v1/items?per_page=101');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page' => '表示件数は1～100の範囲で指定してください。']);
    }

    public function test_returns_422_when_status_is_unknown(): void
    {
        $response = $this->getJson('/api/v1/items?status=deleted');

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
