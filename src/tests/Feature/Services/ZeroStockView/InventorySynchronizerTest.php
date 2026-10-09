<?php

namespace Tests\Feature\Services\ZeroStockView;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Inventory;
use App\Models\Sku;
use App\Models\User;
use App\Models\ZeroStockViewSync;
use App\Services\SyncAlreadyRunningException;
use App\Services\ZeroStockView\InventorySynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class InventorySynchronizerTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<array<string, mixed>>> 在庫基準日ごとにAPIが返す行 */
    private array $apiRows = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.zerostockview.base_url' => 'https://zsv.test/api/v1',
            'services.zerostockview.api_key' => 'zsv_test_key',
            'services.zerostockview.mock' => false,
        ]);
        Http::preventStrayRequests();
        Sleep::fake();
        $this->travelTo('2026-10-06 10:00:00');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(string $date, string $sku, int $fba = 5, int $ec = 0): array
    {
        return ['date' => $date, 'productCode' => 'fisi-05', 'brand' => 'SHIORI', 'category' => '老眼鏡', 'sku' => $sku, 'size' => '10', 'amazonOwn' => 1, 'amazonFba' => $fba, 'bossOwn' => 2, 'bossRfc' => 3, 'freeStock' => 4, 'ecStock' => $ec];
    }

    /**
     * 期間内の在庫基準日の行だけを返す ZeroStockView のフェイク。
     */
    private function fakeApi(): void
    {
        Http::fake(['zsv.test/api/v1/inventory/daily*' => function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
            $rows = [];
            foreach ($this->apiRows as $date => $dateRows) {
                if ($date >= $query['from'] && $date <= $query['to']) {
                    array_push($rows, ...$dateRows);
                }
            }

            return Http::response(['data' => $rows, 'meta' => ['from' => $query['from'], 'to' => $query['to'], 'productCode' => null, 'count' => count($rows)]]);
        }]);
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private function requestedRanges(): array
    {
        return Http::recorded()->map(function (array $pair): array {
            parse_str(parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return ['from' => $query['from'], 'to' => $query['to']];
        })->values()->all();
    }

    public function test_first_run_walks_back_through_history_in_31_day_windows_until_three_empty_windows(): void
    {
        Sku::factory()->create(['sku_code' => 'fisi-05-1-10']);
        $this->apiRows = [
            '2026-09-16' => [$this->row('2026-09-16', 'fisi-05-1-10', fba: 27, ec: 362)],
            '2026-10-06' => [$this->row('2026-10-06', 'fisi-05-1-10'), $this->row('2026-10-06', 'new-sku-1')],
        ];
        $this->fakeApi();

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame([
            ['from' => '2026-09-06', 'to' => '2026-10-06'],
            ['from' => '2026-08-06', 'to' => '2026-09-05'],
            ['from' => '2026-07-06', 'to' => '2026-08-05'],
            ['from' => '2026-06-05', 'to' => '2026-07-05'],
        ], $this->requestedRanges());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-API-Key', 'zsv_test_key'));

        $sku = Sku::query()->where('sku_code', 'fisi-05-1-10')->sole();
        $this->assertSame(
            ['amazon_own' => 1, 'amazon_fba' => 27, 'boss_own' => 2, 'boss_rfc' => 3, 'free_stock' => 4, 'ec_stock' => 362],
            $sku->inventories()->whereDate('stock_date', '2026-09-16')->get()->mapWithKeys(fn (Inventory $inventory): array => [$inventory->warehouse->value => $inventory->quantity])->all(),
        );
        $this->assertDatabaseHas('skus', ['sku_code' => 'new-sku-1', 'item_id' => null]);

        $this->assertSame(SyncStatus::Succeeded, $sync->status);
        $this->assertSame(['2026-06-05', '2026-10-06', '2026-10-06'], [$sync->from_date->toDateString(), $sync->to_date->toDateString(), $sync->latest_stock_date->toDateString()]);
        $this->assertSame([2, 3, 1], [$sync->stock_date_count, $sync->sku_row_count, $sync->created_sku_count]);
        $this->assertSame(18, Inventory::query()->count());
    }

    public function test_later_runs_refetch_the_last_7_days_and_replace_each_returned_date_entirely(): void
    {
        $kept = Sku::factory()->create(['sku_code' => 'fisi-05-1-10']);
        $dropped = Sku::factory()->create(['sku_code' => 'fisi-05-1-15']);
        foreach (['2026-09-28', '2026-10-05'] as $date) {
            foreach ([$kept, $dropped] as $sku) {
                Inventory::create(['stock_date' => $date, 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 99]);
            }
        }
        $this->apiRows = ['2026-10-05' => [$this->row('2026-10-05', 'fisi-05-1-10', fba: 7)]];
        $this->fakeApi();

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Schedule);

        $this->assertSame([['from' => '2026-09-30', 'to' => '2026-10-06']], $this->requestedRanges());
        $this->assertDatabaseHas('inventories', ['sku_id' => $kept->id, 'stock_date' => '2026-10-05', 'warehouse' => 'amazon_fba', 'quantity' => 7]);
        $this->assertDatabaseMissing('inventories', ['sku_id' => $dropped->id, 'stock_date' => '2026-10-05']);
        $this->assertSame(2, Inventory::query()->whereDate('stock_date', '2026-09-28')->count());
        $this->assertSame([1, 1, 0], [$sync->stock_date_count, $sync->sku_row_count, $sync->created_sku_count]);
    }

    public function test_refetches_from_the_latest_stored_date_when_fetches_were_missed_for_longer_than_7_days(): void
    {
        $sku = Sku::factory()->create(['sku_code' => 'fisi-05-1-10']);
        Inventory::create(['stock_date' => '2026-08-20', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 1]);
        $this->fakeApi();

        app(InventorySynchronizer::class)->sync(SyncTrigger::Schedule);

        $this->assertSame([
            ['from' => '2026-08-20', 'to' => '2026-09-19'],
            ['from' => '2026-09-20', 'to' => '2026-10-06'],
        ], $this->requestedRanges());
    }

    public function test_records_failure_and_keeps_stored_inventory_when_the_api_key_is_rejected(): void
    {
        $sku = Sku::factory()->create();
        Inventory::create(['stock_date' => '2026-10-05', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 1]);
        Http::fake(['zsv.test/*' => Http::response(['message' => 'APIキーが正しくありません。'], 401)]);

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame(SyncStatus::Failed, $sync->status);
        $this->assertSame('ZeroStockViewのAPIキーが正しくありません。APIキーを確認してください。', $sync->error_message);
        $this->assertSame('2026-10-05', $sync->latest_stock_date->toDateString());
        $this->assertSame(1, Inventory::query()->count());
    }

    #[TestWith([SyncTrigger::Manual, true])]
    #[TestWith([SyncTrigger::Schedule, true])]
    #[TestWith([SyncTrigger::Manual, false])]
    #[TestWith([SyncTrigger::Schedule, false])]
    public function test_keeps_the_latest_twenty_syncs_and_preserves_inventory(SyncTrigger $trigger, bool $succeeds): void
    {
        $ids = [];
        for ($index = 0; $index < 20; $index++) {
            $ids[] = ZeroStockViewSync::create([
                'status' => $index % 2 === 0 ? SyncStatus::Succeeded : SyncStatus::Failed,
                'triggered_by' => $index % 2 === 0 ? SyncTrigger::Manual : SyncTrigger::Schedule,
                'started_at' => now(),
                'finished_at' => now(),
            ])->id;
        }
        $sku = Sku::factory()->create(['sku_code' => 'fisi-05-1-10']);
        $historicalInventory = Inventory::create(['stock_date' => '2026-09-16', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 11]);
        $recentInventory = Inventory::create(['stock_date' => '2026-10-05', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 3]);
        if ($succeeds) {
            $this->apiRows = ['2026-10-06' => [$this->row('2026-10-06', 'fisi-05-1-10')]];
            $this->fakeApi();
        } else {
            Http::fake(['zsv.test/*' => Http::response(['message' => 'APIキーが正しくありません。'], 401)]);
        }

        $sync = app(InventorySynchronizer::class)->sync($trigger);

        $this->assertSame($succeeds ? SyncStatus::Succeeded : SyncStatus::Failed, $sync->status);
        $this->assertSame([...array_slice($ids, 1), $sync->id], ZeroStockViewSync::query()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseHas('inventories', ['id' => $historicalInventory->id, 'sku_id' => $sku->id, 'quantity' => 11]);
        $this->assertDatabaseHas('inventories', ['id' => $recentInventory->id, 'sku_id' => $sku->id, 'quantity' => 3]);
    }

    public function test_keeps_the_last_succeeded_sync_beyond_twenty_while_failures_continue(): void
    {
        $succeededId = ZeroStockViewSync::create(['status' => SyncStatus::Succeeded, 'triggered_by' => SyncTrigger::Schedule, 'started_at' => now()->subDays(30), 'finished_at' => now()->subDays(30)])->id;
        $failedIds = [];
        for ($index = 0; $index < 20; $index++) {
            $failedIds[] = ZeroStockViewSync::create(['status' => SyncStatus::Failed, 'triggered_by' => SyncTrigger::Schedule, 'started_at' => now(), 'finished_at' => now()])->id;
        }
        Http::fake(['zsv.test/*' => Http::response(['message' => 'APIキーが正しくありません。'], 401)]);

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame([$succeededId, ...array_slice($failedIds, 1), $sync->id], ZeroStockViewSync::query()->orderBy('id')->pluck('id')->all());
        $this->actingAs(User::factory()->create())->getJson('/api/v1/zerostockview-syncs/latest')->assertOk()->assertJsonPath('data.latest.id', $sync->id)->assertJsonPath('data.last_succeeded.id', $succeededId);
    }

    public function test_does_not_retry_when_the_external_api_is_disabled(): void
    {
        Http::fake(['zsv.test/*' => Http::response(['message' => '外部APIは現在無効です。'], 503)]);

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame('ZeroStockViewの外部APIが無効になっています。ZeroStockView側の設定を確認してください。', $sync->error_message);
        Http::assertSentCount(1);
    }

    public function test_retries_when_the_rate_limit_is_exceeded(): void
    {
        Http::fake(['zsv.test/*' => Http::response(['message' => 'Too Many Attempts.'], 429)]);

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertStringStartsWith('ZeroStockViewの利用回数の上限', $sync->error_message);
        Http::assertSentCount(3);
    }

    public function test_generates_weekday_inventory_for_sample_skus_without_calling_the_api_in_mock_mode(): void
    {
        config(['services.zerostockview.mock' => true]);

        $sync = app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);

        $this->assertSame(SyncStatus::Succeeded, $sync->status);
        $this->assertSame(16, $sync->created_sku_count);
        $this->assertSame('2026-10-06', $sync->latest_stock_date->toDateString());
        $this->assertSame(0, Inventory::query()->whereDate('stock_date', '2026-10-04')->count());
        Http::assertNothingSent();
    }

    public function test_rejects_a_second_run_while_another_run_holds_the_lock(): void
    {
        $lock = Cache::lock('zerostockview-inventory-sync', 600);
        $lock->get();

        try {
            app(InventorySynchronizer::class)->sync(SyncTrigger::Manual);
            $this->fail('SyncAlreadyRunningException was not thrown.');
        } catch (SyncAlreadyRunningException $exception) {
            $this->assertSame('ZeroStockViewからの取得を実行中です。終わるまでお待ちください。', $exception->getMessage());
            $this->assertSame(0, ZeroStockViewSync::query()->count());
        } finally {
            $lock->release();
        }
    }
}
