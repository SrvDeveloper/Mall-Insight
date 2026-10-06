<?php

namespace Tests\Feature\Console\Commands;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncCrossWalkerItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Sleep::fake();
    }

    public function test_reports_counts_and_records_a_scheduled_sync(): void
    {
        config(['services.crosswalker.mock' => true]);

        $this->artisan('crosswalker:sync-items')
            ->expectsOutput('品番 4件・SKU 16件を取得しました（追加 4件、削除 0件、品番から外れたSKU 0件）。')
            ->assertSuccessful();

        $this->assertDatabaseHas('crosswalker_syncs', ['status' => 'succeeded', 'triggered_by' => 'schedule']);
    }

    public function test_fails_with_the_reason_when_crosswalker_is_unreachable(): void
    {
        config(['services.crosswalker.mock' => false, 'services.crosswalker.base_url' => 'https://crosswalker.test/api/v1', 'services.crosswalker.api_key' => 'k']);
        Http::fake(['crosswalker.test/*' => Http::failedConnection()]);

        $this->artisan('crosswalker:sync-items')
            ->expectsOutput('CrossWalkerに接続できませんでした。ネットワークの状態か、CrossWalkerが動いているかを確認してください。')
            ->assertFailed();
    }
}
