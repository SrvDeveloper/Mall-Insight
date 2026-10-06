<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\CrossWalkerSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CrossWalkerSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.crosswalker.mock' => true]);
        Http::preventStrayRequests();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function syncRecord(SyncStatus $status, string $finishedAt, array $attributes = []): CrossWalkerSync
    {
        return CrossWalkerSync::create([
            'status' => $status,
            'triggered_by' => SyncTrigger::Schedule,
            'started_at' => $finishedAt,
            'finished_at' => $finishedAt,
            ...$attributes,
        ]);
    }

    public function test_latest_returns_nulls_before_the_first_sync(): void
    {
        $response = $this->getJson('/api/v1/crosswalker-syncs/latest');

        $response->assertOk()->assertExactJson(['data' => ['latest' => null, 'last_succeeded' => null]]);
    }

    public function test_latest_returns_the_failed_run_together_with_the_last_successful_run(): void
    {
        $this->syncRecord(SyncStatus::Succeeded, '2026-10-05 06:00:00', ['item_count' => 55, 'sku_count' => 581]);
        $succeeded = $this->syncRecord(SyncStatus::Succeeded, '2026-10-06 06:00:00', ['item_count' => 55, 'sku_count' => 581]);
        $failed = $this->syncRecord(SyncStatus::Failed, '2026-10-06 09:00:00', ['error_message' => 'CrossWalkerに接続できませんでした。']);

        $response = $this->getJson('/api/v1/crosswalker-syncs/latest');

        $response->assertOk()
            ->assertJsonPath('data.latest.id', $failed->id)
            ->assertJsonPath('data.latest.status', 'failed')
            ->assertJsonPath('data.latest.error_message', 'CrossWalkerに接続できませんでした。')
            ->assertJsonPath('data.last_succeeded.id', $succeeded->id)
            ->assertJsonPath('data.last_succeeded.finished_at', '2026-10-06T06:00:00+09:00');
    }

    public function test_store_runs_a_manual_sync_and_returns_201_with_the_result(): void
    {
        $response = $this->postJson('/api/v1/crosswalker-syncs');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.triggered_by', 'manual')
            ->assertJsonPath('data.triggered_by_label', '手動')
            ->assertJsonPath('data.item_count', 4);
        $this->assertDatabaseHas('items', ['item_no' => 'fisi-05']);
    }

    public function test_store_returns_201_with_the_failure_when_crosswalker_is_unreachable(): void
    {
        config(['services.crosswalker.mock' => false, 'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1', 'services.crosswalker.api_key' => 'k']);
        Http::fake(['crosswalker.test/*' => Http::response([], 403)]);

        $response = $this->postJson('/api/v1/crosswalker-syncs');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error_message', 'CrossWalkerへの接続が許可されていません。外部APIが有効か、接続元IPアドレスが許可されているかを確認してください。');
    }

    public function test_store_returns_409_while_another_sync_is_running(): void
    {
        $lock = Cache::lock('crosswalker-item-sync', 300);
        $lock->get();

        $response = $this->postJson('/api/v1/crosswalker-syncs');

        $lock->release();
        $response->assertConflict()->assertExactJson(['message' => 'CrossWalkerからの取得を実行中です。終わるまでお待ちください。']);
    }
}
