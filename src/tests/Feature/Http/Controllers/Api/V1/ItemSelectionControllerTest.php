<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemSelectionControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());

        $this->travelTo('2026-10-07 09:00:00');
    }

    public function test_returns_no_selection_and_the_fiscal_year_start_before_anything_is_confirmed(): void
    {
        $this->getJson('/api/v1/item-selections/current?scope=boss')
            ->assertOk()
            ->assertExactJson(['data' => null, 'meta' => ['scope' => 'boss', 'item_nos' => [], 'uses_candidates' => true, 'fiscal_year_start' => '2026-04-01']]);
    }

    public function test_fiscal_year_starts_in_april_of_the_previous_year_from_january_to_march(): void
    {
        $this->travelTo('2027-03-31 23:00:00');

        $this->getJson('/api/v1/item-selections/current?scope=boss')->assertJsonPath('meta.fiscal_year_start', '2026-04-01');
    }

    public function test_confirms_a_selection_with_the_sales_rank_at_that_time(): void
    {
        $top = $this->item('top-01', 5000);
        $this->item('second-01', 3000);
        $unsold = Item::factory()->create(['item_no' => 'unsold-01']);

        $response = $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['unsold-01', 'top-01'], 'note' => '2026年度の見直し']);

        $response->assertCreated()
            ->assertJsonPath('data.confirmed_at', '2026-10-07T09:00:00+09:00')
            ->assertJsonPath('data.ranking_from', '2025-10-08')
            ->assertJsonPath('data.note', '2026年度の見直し')
            ->assertJsonPath('data.items', [
                ['item_no' => 'top-01', 'sales_rank' => 1, 'sales_amount' => 5000, 'is_candidate' => true, 'exists_in_crosswalker' => true],
                ['item_no' => 'unsold-01', 'sales_rank' => null, 'sales_amount' => null, 'is_candidate' => false, 'exists_in_crosswalker' => true],
            ]);

        $top->delete();
        $this->getJson('/api/v1/item-selections/current?scope=boss')
            ->assertJsonPath('data.items.0.item_no', 'top-01')
            ->assertJsonPath('data.items.0.exists_in_crosswalker', false);
        $this->assertNotNull($unsold->fresh());
    }

    public function test_records_who_confirmed_the_selection(): void
    {
        $this->item('a-01', 1000);
        $user = User::factory()->create(['name' => '在庫 担当']);

        // ログイン（B-009）より前に確定した選定は、確定した人が記録されていない
        ItemSelection::create(['scope' => 'boss', 'confirmed_at' => now()->subDay(), 'ranking_from' => '2025-10-01', 'ranking_to' => '2026-09-30']);
        $this->actingAs($user)->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['a-01']])->assertJsonPath('data.confirmed_by', '在庫 担当');

        $this->getJson('/api/v1/item-selections?scope=boss')->assertJsonPath('data.*.confirmed_by', ['在庫 担当', null]);
        $this->getJson('/api/v1/item-selections/current?scope=boss')->assertJsonPath('data.confirmed_by', '在庫 担当');
    }

    public function test_a_new_confirmation_becomes_current_and_keeps_the_previous_selection(): void
    {
        $this->item('a-01', 1000);
        $this->item('b-01', 2000);
        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['a-01']])->assertCreated();
        $this->travel(1)->minutes();

        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['b-01']])->assertCreated();

        $this->getJson('/api/v1/item-selections/current?scope=boss')->assertJsonPath('data.items.*.item_no', ['b-01']);
        $this->assertSame(2, ItemSelection::count());
    }

    public function test_lists_the_history_newest_first_with_changes_from_the_previous_selection(): void
    {
        foreach (['a-01' => 3000, 'b-01' => 2000, 'c-01' => 1000] as $itemNo => $amount) {
            $this->item($itemNo, $amount);
        }
        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['a-01', 'b-01']])->assertCreated();
        $this->travel(1)->days();
        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['a-01', 'c-01'], 'note' => '入れ替え'])->assertCreated();

        $response = $this->getJson('/api/v1/item-selections?scope=boss');

        $response->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.note', '入れ替え')
            ->assertJsonPath('data.0.confirmed_at', '2026-10-08T09:00:00+09:00')
            ->assertJsonPath('data.0.items.*.item_no', ['a-01', 'c-01'])
            ->assertJsonPath('data.0.changes', ['added' => ['c-01'], 'removed' => ['b-01']])
            ->assertJsonPath('data.1.changes', null);
    }

    public function test_compares_the_oldest_selection_on_a_page_with_the_one_on_the_next_page(): void
    {
        $this->item('a-01', 1000);
        $this->item('b-01', 2000);
        for ($i = 0; $i < 11; $i++) {
            $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => $i === 0 ? ['a-01'] : ['a-01', 'b-01']])->assertCreated();
        }

        $this->getJson('/api/v1/item-selections?scope=boss')
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.9.changes', ['added' => ['b-01'], 'removed' => []]);
        $this->getJson('/api/v1/item-selections?scope=boss&page=2')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.changes', null);
        $this->getJson('/api/v1/item-selections?scope=boss&page=1')->assertJsonPath('data.0.changes', ['added' => [], 'removed' => []]);
    }

    public function test_rejects_an_empty_selection_unknown_items_duplicates_and_more_than_100_items(): void
    {
        Item::factory()->count(101)->sequence(fn ($sequence) => ['item_no' => sprintf('item-%03d', $sequence->index)])->create();

        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_nos' => '対象品番は必須です。']);
        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => ['item-000', 'item-000', 'nothing']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_nos.0', 'item_nos.1', 'item_nos.2']);
        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => Item::pluck('item_no')->all()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_nos' => '対象品番は100件以下で指定してください。']);

        $this->postJson('/api/v1/item-selections', ['scope' => 'boss', 'item_nos' => Item::limit(100)->pluck('item_no')->all()])->assertCreated();
        $this->assertSame(1, ItemSelection::count());
    }

    private function item(string $itemNo, int $salesAmount, int $amazonAmount = 0): Item
    {
        $item = Item::factory()->create(['item_no' => $itemNo]);
        $sku = Sku::factory()->create(['item_id' => $item->id]);
        $this->sell($sku, $salesAmount, 'boss_own');
        if ($amazonAmount > 0) {
            $this->sell($sku, $amazonAmount, 'amazon_fba');
        }

        return $item;
    }

    private function sell(Sku $sku, int $amount, string $warehouse): void
    {
        static $orderId = 1;

        SalesLine::create([
            'source' => $warehouse === 'amazon_fba' ? 'amazon' : 'boss',
            'source_order_id' => (string) $orderId++,
            'sales_date' => '2026-10-01',
            'mall' => $warehouse === 'amazon_fba' ? 'amazon' : 'rakuten',
            'sku_id' => $sku->id,
            'warehouse' => $warehouse,
            'quantity' => 1,
            'amount' => $amount,
        ]);
    }

    public function test_ranks_and_confirms_the_amazon_and_boss_selections_and_combines_them_into_the_total(): void
    {
        // boss-01 は BOSS だけ、both-01 は両方で売れる。Amazon の順位は Amazon の販売実績だけで付ける
        $this->item('boss-01', 9000);
        $this->item('both-01', 1000, 5000);
        $this->getJson('/api/v1/item-ranking?scope=amazon')
            ->assertOk()
            ->assertJsonPath('meta.scope', 'amazon')
            ->assertJsonPath('meta.candidate_rank', 10)
            ->assertJsonPath('data.0.item_no', 'both-01')
            ->assertJsonPath('data.0.sales_amount', 5000);

        // 未確定の間は、そのチャネルの売上上位の品番を使う。全体は Amazon・BOSS を合わせたもの
        $this->getJson('/api/v1/item-selections/current?scope=amazon')->assertOk()->assertJsonPath('data', null)->assertJsonPath('meta.item_nos', ['both-01'])->assertJsonPath('meta.uses_candidates', true);
        $this->getJson('/api/v1/item-selections/current')
            ->assertJsonPath('data', null)
            ->assertJsonPath('meta.item_nos', ['both-01', 'boss-01'])
            ->assertJsonPath('meta.amazon_item_nos', ['both-01'])
            ->assertJsonPath('meta.boss_item_nos', ['boss-01', 'both-01'])
            ->assertJsonPath('meta.uses_candidates', true);

        $this->postJson('/api/v1/item-selections', ['scope' => 'amazon', 'item_nos' => ['boss-01']])
            ->assertCreated()
            ->assertJsonPath('data.scope', 'amazon')
            ->assertJsonPath('data.items.0.sales_amount', null);
        $this->getJson('/api/v1/item-selections/current?scope=amazon')->assertJsonPath('data.items.*.item_no', ['boss-01'])->assertJsonPath('meta.uses_candidates', false);
        $this->getJson('/api/v1/item-selections/current')->assertJsonPath('meta.item_nos', ['boss-01', 'both-01'])->assertJsonPath('meta.amazon_uses_candidates', false)->assertJsonPath('meta.boss_uses_candidates', true);
        $this->getJson('/api/v1/item-selections?scope=amazon')->assertJsonCount(1, 'data')->assertJsonPath('data.0.changes', null);
        $this->getJson('/api/v1/item-selections?scope=boss')->assertJsonCount(0, 'data');

        // 全体は確定しない
        $this->postJson('/api/v1/item-selections', ['scope' => 'total', 'item_nos' => ['boss-01']])->assertUnprocessable()->assertJsonValidationErrors('scope');
        $this->postJson('/api/v1/item-selections', ['item_nos' => ['boss-01']])->assertUnprocessable()->assertJsonValidationErrors('scope');
    }
}
