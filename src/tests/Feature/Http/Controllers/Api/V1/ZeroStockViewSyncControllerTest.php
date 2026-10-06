<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\ZeroStockViewSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZeroStockViewSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zerostockview.mock' => true]);
        Http::preventStrayRequests();
        $this->travelTo('2026-10-06 10:00:00');
    }

    public function test_latest_returns_nulls_before_the_first_sync(): void
    {
        $response = $this->getJson('/api/v1/zerostockview-syncs/latest');

        $response->assertOk()->assertExactJson(['data' => ['latest' => null, 'last_succeeded' => null]]);
    }

    public function test_latest_returns_the_failed_run_together_with_the_last_successful_run(): void
    {
        $succeeded = ZeroStockViewSync::create(['status' => SyncStatus::Succeeded, 'triggered_by' => SyncTrigger::Schedule, 'started_at' => '2026-10-06 10:00:00', 'finished_at' => '2026-10-06 10:00:02', 'latest_stock_date' => '2026-10-06']);
        $failed = ZeroStockViewSync::create(['status' => SyncStatus::Failed, 'triggered_by' => SyncTrigger::Manual, 'started_at' => '2026-10-06 11:00:00', 'finished_at' => '2026-10-06 11:00:01', 'latest_stock_date' => '2026-10-06', 'error_message' => 'ZeroStockViewに接続できませんでした。']);

        $response = $this->getJson('/api/v1/zerostockview-syncs/latest');

        $response->assertOk()
            ->assertJsonPath('data.latest.id', $failed->id)
            ->assertJsonPath('data.latest.error_message', 'ZeroStockViewに接続できませんでした。')
            ->assertJsonPath('data.latest.latest_stock_date', '2026-10-06')
            ->assertJsonPath('data.last_succeeded.id', $succeeded->id);
    }

    public function test_store_runs_a_manual_sync_and_returns_201_with_the_result(): void
    {
        $response = $this->postJson('/api/v1/zerostockview-syncs');

        $response->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.triggered_by', 'manual')
            ->assertJsonPath('data.latest_stock_date', '2026-10-06');
    }

    public function test_store_returns_409_while_another_sync_is_running(): void
    {
        $lock = Cache::lock('zerostockview-inventory-sync', 600);
        $lock->get();

        $response = $this->postJson('/api/v1/zerostockview-syncs');

        $lock->release();
        $response->assertConflict()->assertExactJson(['message' => 'ZeroStockViewからの取得を実行中です。終わるまでお待ちください。']);
    }
}
