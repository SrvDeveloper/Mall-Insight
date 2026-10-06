<?php

namespace App\Console\Commands;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Services\CrossWalker\ItemSynchronizer;
use App\Services\CrossWalker\SyncAlreadyRunningException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('crosswalker:sync-items')]
#[Description('CrossWalker から品番・SKUを取得して保存する（毎日自動で実行）')]
class SyncCrossWalkerItems extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ItemSynchronizer $synchronizer): int
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

        $this->info("品番 {$sync->item_count}件・SKU {$sync->sku_count}件を取得しました（追加 {$sync->added_item_count}件、削除 {$sync->removed_item_count}件、品番から外れたSKU {$sync->detached_sku_count}件）。");

        return self::SUCCESS;
    }
}
