<?php

namespace Tests\Feature\Services\CrossWalker;

use App\Enums\ActiveStatus;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\CrossWalkerSync;
use App\Models\Item;
use App\Models\Sku;
use App\Services\CrossWalker\ItemSynchronizer;
use App\Services\SyncAlreadyRunningException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ItemSynchronizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1',
            'services.crosswalker.api_key' => 'test-api-key',
            'services.crosswalker.mock' => false,
        ]);
        Http::preventStrayRequests();
        Sleep::fake();
    }

    /**
     * @param  list<array<string, mixed>>  $skus
     * @return array<string, mixed>
     */
    private function cwItem(string $itemNo, array $skus = [], string $status = 'active', ?string $updatedAt = '2026-09-08T07:08:16.000000Z'): array
    {
        return ['item_no' => $itemNo, 'name' => null, 'brand' => 'SHIORI', 'category' => '老眼鏡', 'parent_asin' => "P-{$itemNo}", 'status' => $status, 'skus' => $skus, 'updated_at' => $updatedAt];
    }

    /**
     * @return array<string, mixed>
     */
    private function cwSku(string $skuCode, string $status = 'active', ?string $childAsin = 'B0CHILD001', string $size = '10'): array
    {
        return ['sku_code' => $skuCode, 'child_asin' => $childAsin, 'status' => $status, 'tq_item_no' => 'TQ', 'tq_color_no' => '1', 'tq_size' => $size];
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function fakeItemsPage(array $items, int $page = 1, int $lastPage = 1): array
    {
        return ['data' => $items, 'links' => [], 'meta' => ['current_page' => $page, 'last_page' => $lastPage, 'per_page' => 100, 'total' => count($items)]];
    }

    public function test_saves_all_pages_of_items_and_skus_as_crosswalker_returns_them(): void
    {
        Http::fake([
            'crosswalker.test/api/v1/items?status=all&per_page=100&page=1' => Http::response($this->fakeItemsPage([
                $this->cwItem('si-01sap', [$this->cwSku('si-01sa-1-20'), $this->cwSku('si-01sa-1-15', 'inactive', null)]),
            ], 1, 2)),
            'crosswalker.test/api/v1/items?status=all&per_page=100&page=2' => Http::response($this->fakeItemsPage([
                $this->cwItem('fi2002', [$this->cwSku('fi2002-1', size: '0')], 'inactive', null),
            ], 2, 2)),
        ]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Manual);

        $item = Item::query()->where('item_no', 'si-01sap')->sole();
        $this->assertSame('2026-09-08 16:08:16', $item->crosswalker_updated_at->format('Y-m-d H:i:s'));
        $this->assertSame(['si-01sa-1-20', 'si-01sa-1-15'], $item->skus->pluck('sku_code')->all());
        $this->assertSame(ActiveStatus::Inactive, $item->skus[1]->status);
        $this->assertNull($item->skus[1]->child_asin);
        $this->assertDatabaseHas('items', ['item_no' => 'fi2002', 'status' => 'inactive', 'crosswalker_updated_at' => null]);
        $this->assertDatabaseHas('skus', ['sku_code' => 'fi2002-1', 'tq_size' => '0']);

        $this->assertSame(SyncStatus::Succeeded, $sync->status);
        $this->assertSame(SyncTrigger::Manual, $sync->triggered_by);
        $this->assertSame([2, 3, 2, 0, 0], [$sync->item_count, $sync->sku_count, $sync->added_item_count, $sync->removed_item_count, $sync->detached_sku_count]);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-api-key'));
    }

    public function test_overwrites_saved_items_removes_vanished_items_and_keeps_their_skus_as_unassigned(): void
    {
        $kept = Item::factory()->create(['item_no' => 'fisi-05', 'brand' => '旧ブランド']);
        Sku::factory()->for($kept)->create(['sku_code' => 'fisi-05-1-10']);
        Sku::factory()->for($kept)->create(['sku_code' => 'fisi-05-9-99']);
        $vanished = Item::factory()->create(['item_no' => 'old-01']);
        $vanishedSku = Sku::factory()->for($vanished)->create(['sku_code' => 'old-01-1-10']);
        $unassigned = Sku::factory()->unassigned()->create(['sku_code' => 'sales-only-1']);
        Http::fake(['crosswalker.test/*' => Http::response($this->fakeItemsPage([
            $this->cwItem('fisi-05', [$this->cwSku('fisi-05-1-10'), $this->cwSku('fisi-05-1-15')]),
            $this->cwItem('rf-03', [$this->cwSku('rf-03-1-10')]),
        ]))]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Schedule);

        $this->assertDatabaseHas('items', ['item_no' => 'fisi-05', 'brand' => 'SHIORI']);
        $this->assertModelMissing($vanished);
        $this->assertDatabaseHas('skus', ['id' => $vanishedSku->id, 'item_id' => null, 'status' => null, 'tq_item_no' => null]);
        $this->assertDatabaseHas('skus', ['sku_code' => 'fisi-05-9-99', 'item_id' => null]);
        $this->assertDatabaseHas('skus', ['sku_code' => 'fisi-05-1-15', 'item_id' => $kept->id]);
        $this->assertModelExists($unassigned);
        $this->assertSame([2, 3, 1, 1, 2], [$sync->item_count, $sync->sku_count, $sync->added_item_count, $sync->removed_item_count, $sync->detached_sku_count]);
    }

    public function test_records_failure_with_user_facing_message_and_keeps_data_when_api_key_is_rejected(): void
    {
        $item = Item::factory()->create();
        Http::fake(['crosswalker.test/*' => Http::response(['message' => 'APIキーが正しくありません。'], 401)]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame(SyncStatus::Failed, $sync->status);
        $this->assertSame('CrossWalkerのAPIキーが正しくありません。サイト設定でAPIキーを確認してください。', $sync->error_message);
        $this->assertNull($sync->item_count);
        $this->assertModelExists($item);
        Http::assertSentCount(1);
    }

    public function test_retries_server_errors_before_recording_failure(): void
    {
        Http::fake(['crosswalker.test/*' => Http::response([], 503)]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame('CrossWalkerでエラーが発生しました（503）。時間をおいてもう一度お試しください。', $sync->error_message);
        Http::assertSentCount(3);
    }

    public function test_records_failure_when_connection_fails(): void
    {
        Http::fake(['crosswalker.test/*' => Http::failedConnection()]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame(SyncStatus::Failed, $sync->status);
        $this->assertStringStartsWith('CrossWalkerに接続できませんでした。', $sync->error_message);
    }

    public function test_does_not_delete_saved_items_when_crosswalker_returns_no_items(): void
    {
        $item = Item::factory()->create();
        Http::fake(['crosswalker.test/*' => Http::response($this->fakeItemsPage([]))]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Schedule);

        $this->assertSame(SyncStatus::Failed, $sync->status);
        $this->assertStringStartsWith('CrossWalkerから品番が1件も返りませんでした。', $sync->error_message);
        $this->assertModelExists($item);
    }

    public function test_reads_bundled_sample_without_calling_api_in_mock_mode(): void
    {
        config(['services.crosswalker.mock' => true]);

        $sync = app(ItemSynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame(SyncStatus::Succeeded, $sync->status);
        $this->assertSame(4, $sync->item_count);
        $this->assertDatabaseHas('skus', ['sku_code' => 'si-01sa-1-15', 'item_id' => Item::query()->where('item_no', 'si-01sap')->value('id')]);
        Http::assertNothingSent();
    }

    public function test_rejects_a_second_run_while_another_run_holds_the_lock(): void
    {
        $lock = Cache::lock('crosswalker-item-sync', 300);
        $lock->get();

        try {
            app(ItemSynchronizer::class)->sync(SyncTrigger::Manual);
            $this->fail('SyncAlreadyRunningException was not thrown.');
        } catch (SyncAlreadyRunningException) {
            $this->assertSame(0, CrossWalkerSync::query()->count());
        } finally {
            $lock->release();
        }
    }
}
