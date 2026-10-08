<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Item;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemRankingControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());

        $this->travelTo('2026-10-07 09:00:00');
    }

    public function test_ranks_items_by_sales_amount_of_their_skus_in_the_last_12_months(): void
    {
        $top = $this->itemWithSkus('top-01', 2);
        $second = $this->itemWithSkus('second-01');
        $this->sell($top->skus[0], '2026-10-07', 1, 3000);
        $this->sell($top->skus[1], '2025-10-08', 2, 4000);
        $this->sell($second->skus[0], '2026-05-01', 1, 5000);
        $this->sell($second->skus[0], '2025-10-07', 1, 9000);
        $unregistered = Sku::factory()->create(['item_id' => null]);
        $this->sell($unregistered, '2026-10-01', 1, 99000);

        $response = $this->getJson('/api/v1/item-ranking');

        $response->assertOk()
            ->assertJsonPath('data.*.item_no', ['top-01', 'second-01'])
            ->assertJsonPath('data.0.sales_amount', 7000)
            ->assertJsonPath('data.0.sales_quantity', 3)
            ->assertJsonPath('data.0.sales_rank', 1)
            ->assertJsonPath('data.0.sku_count', 2)
            ->assertJsonPath('data.1.sales_amount', 5000)
            ->assertJsonPath('meta.ranking_from', '2025-10-08')
            ->assertJsonPath('meta.ranking_to', '2026-10-07')
            ->assertJsonPath('meta.candidate_rank', 20)
            ->assertJsonPath('meta.max_items', 100);
    }

    public function test_gives_tied_items_the_same_rank_and_leaves_unsold_items_unranked_at_the_end(): void
    {
        foreach (['a-01' => 2000, 'b-01' => 1000, 'c-01' => 1000, 'd-01' => 500] as $itemNo => $amount) {
            $this->sell($this->itemWithSkus($itemNo)->skus[0], '2026-10-01', 1, $amount);
        }
        $this->itemWithSkus('z-unsold');
        $this->itemWithSkus('y-unsold');

        $response = $this->getJson('/api/v1/item-ranking');

        $response->assertOk()
            ->assertJsonPath('data.*.item_no', ['a-01', 'b-01', 'c-01', 'd-01', 'y-unsold', 'z-unsold'])
            ->assertJsonPath('data.*.sales_rank', [1, 2, 2, 4, null, null])
            ->assertJsonPath('data.4.sales_amount', null);
    }

    public function test_marks_items_ranked_within_20th_as_candidates_including_ties_at_20th(): void
    {
        for ($i = 1; $i <= 22; $i++) {
            $amount = $i <= 19 ? 100000 - $i * 1000 : 50000;
            $this->sell($this->itemWithSkus(sprintf('item-%02d', $i))->skus[0], '2026-10-01', 1, $amount);
        }

        $response = $this->getJson('/api/v1/item-ranking');

        $response->assertOk();
        $this->assertSame(array_fill(0, 22, true), $response->json('data.*.is_candidate'));
        $this->assertSame([20, 20, 20], array_slice($response->json('data.*.sales_rank'), 19));
    }

    private function itemWithSkus(string $itemNo, int $skuCount = 1): Item
    {
        $item = Item::factory()->create(['item_no' => $itemNo]);
        Sku::factory()->count($skuCount)->sequence(fn ($sequence) => ['position' => $sequence->index])->create(['item_id' => $item->id]);

        return $item->load('skus');
    }

    private function sell(Sku $sku, string $date, int $quantity, int $amount): void
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
            'amount' => $amount,
        ]);
    }
}
