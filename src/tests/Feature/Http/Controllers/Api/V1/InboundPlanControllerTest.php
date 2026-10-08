<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Models\InboundPlan;
use App\Models\Item;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboundPlanControllerTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    /** @var list<Sku> */
    private array $skus;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->actingAs(User::factory()->create());

        $this->travelTo('2026-10-07 09:00:00');
        $this->item = Item::factory()->create(['item_no' => 'fisi-05', 'brand' => 'SHIORI', 'category' => '老眼鏡']);
        $this->skus = [
            Sku::factory()->for($this->item)->create(['sku_code' => 'fisi-05-1-10']),
            Sku::factory()->for($this->item)->create(['sku_code' => 'fisi-05-1-15']),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'item_no' => 'fisi-05',
            'arrival_month' => '2026-11',
            'quantity' => 1210,
            'note' => '発注番号 PO-001',
            'allocations' => [['sku_id' => $this->skus[0]->id, 'quantity' => 300], ['sku_id' => $this->skus[1]->id, 'quantity' => 0]],
            ...$overrides,
        ];
    }

    public function test_registers_a_monthly_plan_for_an_item_with_partial_allocations_to_its_skus(): void
    {
        $response = $this->postJson('/api/v1/inbound-plans', $this->payload());

        $response->assertCreated()->assertJsonPath('data', [
            'id' => InboundPlan::firstOrFail()->id,
            'item_no' => 'fisi-05',
            'brand' => 'SHIORI',
            'category' => '老眼鏡',
            'exists_in_crosswalker' => true,
            'arrival_month' => '2026-11',
            'quantity' => 1210,
            'allocated_quantity' => 300,
            'unallocated_quantity' => 910,
            'note' => '発注番号 PO-001',
            'received_at' => null,
            'is_overdue' => false,
            // 0 の割り振りは保存しない
            'allocations' => [['sku_id' => $this->skus[0]->id, 'sku_code' => 'fisi-05-1-10', 'quantity' => 300]],
        ]);
        $this->assertSame('2026-11-01', InboundPlan::firstOrFail()->arrival_month->toDateString());
    }

    public function test_updates_a_plan_and_replaces_its_allocations(): void
    {
        $id = $this->postJson('/api/v1/inbound-plans', $this->payload())->json('data.id');

        $response = $this->putJson("/api/v1/inbound-plans/{$id}", $this->payload([
            'arrival_month' => '2026-12',
            'quantity' => 1000,
            'note' => null,
            'allocations' => [['sku_id' => $this->skus[1]->id, 'quantity' => 1000]],
        ]));

        $response->assertOk()
            ->assertJsonPath('data.arrival_month', '2026-12')
            ->assertJsonPath('data.unallocated_quantity', 0)
            ->assertJsonPath('data.allocations', [['sku_id' => $this->skus[1]->id, 'sku_code' => 'fisi-05-1-15', 'quantity' => 1000]]);
        $this->assertDatabaseCount('inbound_plan_allocations', 1);
    }

    public function test_rejects_allocations_over_the_planned_quantity_or_to_skus_of_another_item(): void
    {
        $other = Sku::factory()->create(['sku_code' => 'other-1']);

        $this->postJson('/api/v1/inbound-plans', $this->payload(['quantity' => 100, 'allocations' => [['sku_id' => $this->skus[0]->id, 'quantity' => 60], ['sku_id' => $this->skus[1]->id, 'quantity' => 50]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations' => 'SKUへの割り振りの合計（110）が入荷予定数（100）を超えています。']);
        $this->postJson('/api/v1/inbound-plans', $this->payload(['allocations' => [['sku_id' => $other->id, 'quantity' => 1]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['allocations.0.sku_id']);
        $this->postJson('/api/v1/inbound-plans', $this->payload(['item_no' => 'nothing', 'arrival_month' => '2026-13', 'quantity' => 0, 'allocations' => []]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_no', 'arrival_month', 'quantity']);
        $this->assertDatabaseCount('inbound_plans', 0);
    }

    public function test_lists_unreceived_plans_by_month_and_flags_overdue_ones(): void
    {
        $this->postJson('/api/v1/inbound-plans', $this->payload(['arrival_month' => '2026-12']));
        $this->postJson('/api/v1/inbound-plans', $this->payload(['arrival_month' => '2026-09', 'allocations' => []]));
        $received = $this->postJson('/api/v1/inbound-plans', $this->payload(['arrival_month' => '2026-10', 'allocations' => []]))->json('data.id');
        $this->postJson("/api/v1/inbound-plans/{$received}/receipt")->assertOk()->assertJsonPath('data.received_at', '2026-10-07T09:00:00+09:00');

        $this->getJson('/api/v1/inbound-plans')
            ->assertOk()
            ->assertJsonPath('data.*.arrival_month', ['2026-09', '2026-12'])
            ->assertJsonPath('data.*.is_overdue', [true, false])
            // まとめは入荷前の2件（12月は300を割り振り済み、9月は割り振りなし）と入荷済みの1件
            ->assertJsonPath('meta.summary', ['pending_count' => 2, 'pending_quantity' => 2420, 'unallocated_quantity' => 2120, 'overdue_count' => 1, 'received_count' => 1]);
        $this->getJson('/api/v1/inbound-plans?include_received=1')->assertJsonPath('data.*.arrival_month', ['2026-09', '2026-10', '2026-12']);
        // まとめは絞り込みによらない
        $this->getJson('/api/v1/inbound-plans?keyword=none')->assertJsonPath('data', [])->assertJsonPath('meta.summary.pending_count', 2);

        $this->deleteJson("/api/v1/inbound-plans/{$received}/receipt")->assertOk()->assertJsonPath('data.received_at', null);
        $this->assertCount(3, $this->getJson('/api/v1/inbound-plans')->json('data'));
    }

    public function test_deletes_a_plan_with_its_allocations(): void
    {
        $id = $this->postJson('/api/v1/inbound-plans', $this->payload())->json('data.id');

        $this->deleteJson("/api/v1/inbound-plans/{$id}")->assertNoContent();

        $this->assertDatabaseCount('inbound_plans', 0);
        $this->assertDatabaseCount('inbound_plan_allocations', 0);
    }
}
