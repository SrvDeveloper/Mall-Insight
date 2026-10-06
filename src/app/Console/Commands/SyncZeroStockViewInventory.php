<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Services\SyncAlreadyRunningException;
use App\Services\ZeroStockView\InventorySynchronizer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('zerostockview:sync-inventory')]
#[Description('ZeroStockView から日次在庫を取得して保存する（毎日自動で実行）')]
class SyncZeroStockViewInventory extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(InventorySynchronizer $synchronizer): int
    {
        try {
            $sync = $synchronizer->sync(SyncTrigger::Schedule);
        } catch (SyncAlreadyRunningException $exception) {
            $this->warn($exception->getMessage());

            return self::SUCCESS;
        }

        if ($sync->status === SyncStatus::Failed) {
            $this->error($sync->error_message);

            return self::FAILURE;
        }

        $latest = $sync->latest_stock_date?->toDateString() ?? 'なし';
        $this->info("{$sync->from_date->toDateString()}〜{$sync->to_date->toDateString()} の在庫を取得しました（在庫基準日 {$sync->stock_date_count}日分・{$sync->sku_row_count}行、新しい未登録SKU {$sync->created_sku_count}件）。最新の在庫基準日：{$latest}");

        return self::SUCCESS;
    }
}
