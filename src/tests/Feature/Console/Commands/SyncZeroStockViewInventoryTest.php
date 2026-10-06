<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncZeroStockViewInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo('2026-10-06 10:00:00');
    }

    public function test_reports_the_fetched_range_and_records_a_scheduled_sync(): void
    {
        config(['services.zerostockview.mock' => true]);

        $this->artisan('zerostockview:sync-inventory')
            ->expectsOutputToContain('の在庫を取得しました（在庫基準日 43日分・688行、新しい未登録SKU 16件）。最新の在庫基準日：2026-10-06')
            ->assertSuccessful();

        $this->assertDatabaseHas('zerostockview_syncs', ['status' => 'succeeded', 'triggered_by' => 'schedule']);
    }

    public function test_fails_with_the_reason_when_zerostockview_is_unreachable(): void
    {
        config(['services.zerostockview.mock' => false, 'services.zerostockview.base_url' => 'https://zsv.test/api/v1', 'services.zerostockview.api_key' => 'k']);
        Http::fake(['zsv.test/*' => Http::failedConnection()]);

        $this->artisan('zerostockview:sync-inventory')
            ->expectsOutput('ZeroStockViewに接続できませんでした。ネットワークの状態か、ZeroStockViewが動いているかを確認してください。')
            ->assertFailed();
    }
}
