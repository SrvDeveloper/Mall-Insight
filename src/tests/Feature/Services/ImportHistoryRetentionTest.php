<?php

namespace Tests\Feature\Services;

use App\Models\Inventory;
use App\Models\SalesImport;
use App\Models\SalesLine;
use App\Models\Sku;
use App\Models\ZeroStockViewSync;
use App\Services\ImportHistoryRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ImportHistoryRetentionTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([SalesImport::class, 0])]
    #[TestWith([SalesImport::class, 19])]
    #[TestWith([SalesImport::class, 20])]
    #[TestWith([SalesImport::class, 25])]
    #[TestWith([ZeroStockViewSync::class, 0])]
    #[TestWith([ZeroStockViewSync::class, 19])]
    #[TestWith([ZeroStockViewSync::class, 20])]
    #[TestWith([ZeroStockViewSync::class, 25])]
    public function test_retains_twenty_histories_without_removing_any_when_under_the_limit(string $model, int $count): void
    {
        $ids = $this->histories($model, $count);

        app(ImportHistoryRetention::class)->prune($model::query());

        $this->assertSame(array_slice($ids, -20), $model::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_keeps_the_given_history_even_when_it_is_older_than_the_latest_twenty(): void
    {
        $ids = $this->histories(ZeroStockViewSync::class, 25);

        app(ImportHistoryRetention::class)->prune(ZeroStockViewSync::query(), $ids[2]);

        $this->assertSame([$ids[2], ...array_slice($ids, -20)], ZeroStockViewSync::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_migration_keeps_the_last_succeeded_sync_older_than_the_latest_twenty(): void
    {
        $syncs = $this->histories(ZeroStockViewSync::class, 25);
        ZeroStockViewSync::query()->whereKey(array_slice($syncs, 3))->update(['status' => 'failed']);
        $migration = require database_path('migrations/2026_10_09_173058_retain_latest_twenty_import_histories.php');

        $migration->up();

        $this->assertSame([$syncs[2], ...array_slice($syncs, -20)], ZeroStockViewSync::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_migration_trims_existing_histories_and_issues_without_deleting_actuals(): void
    {
        $imports = $this->histories(SalesImport::class, 25);
        $syncs = $this->histories(ZeroStockViewSync::class, 25);
        SalesImport::findOrFail($imports[0])->issues()->create(['level' => 'error', 'message' => '古い履歴のエラー']);
        SalesImport::findOrFail($imports[24])->issues()->create(['level' => 'warning', 'message' => '残す履歴の警告']);
        $sku = Sku::factory()->create();
        Inventory::create(['stock_date' => '2026-09-16', 'sku_id' => $sku->id, 'warehouse' => 'amazon_fba', 'quantity' => 5]);
        SalesLine::create(['source' => 'boss', 'source_order_id' => 'old-order', 'sales_date' => '2026-01-01', 'mall' => 'rakuten', 'sku_id' => $sku->id, 'warehouse' => 'boss_own', 'quantity' => 3, 'amount' => 3000]);
        $migration = require database_path('migrations/2026_10_09_173058_retain_latest_twenty_import_histories.php');

        $migration->up();

        $this->assertSame(array_slice($imports, -20), SalesImport::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(array_slice($syncs, -20), ZeroStockViewSync::query()->orderBy('id')->pluck('id')->all());
        $this->assertDatabaseMissing('sales_import_issues', ['sales_import_id' => $imports[0]]);
        $this->assertDatabaseHas('sales_import_issues', ['sales_import_id' => $imports[24]]);
        $this->assertDatabaseHas('inventories', ['sku_id' => $sku->id, 'quantity' => 5]);
        $this->assertDatabaseHas('sales_lines', ['source_order_id' => 'old-order', 'quantity' => 3]);
    }

    /**
     * @param  class-string<SalesImport|ZeroStockViewSync>  $model
     * @return list<int>
     */
    private function histories(string $model, int $count): array
    {
        $ids = [];
        for ($index = 0; $index < $count; $index++) {
            $attributes = $model === SalesImport::class ? ['source' => 'boss', 'file_name' => "{$index}.csv"] : ['triggered_by' => 'manual'];
            $ids[] = $model::create([...$attributes, 'status' => $index % 2 === 0 ? 'succeeded' : 'failed', 'started_at' => now(), 'finished_at' => now()])->id;
        }

        return $ids;
    }
}
