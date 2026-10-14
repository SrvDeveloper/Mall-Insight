<?php

namespace App\Console\Commands;

use App\Enums\SnapshotTrigger;
use App\Services\Forecast\ForecastSnapshotTaker;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('forecasts:snapshot {--force : 今月の自動保存の版があっても、もう一度保存する}')]
#[Description('今の需要予測を版として保存する（毎月1日に自動で実行、B-108）')]
class TakeForecastSnapshot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ForecastSnapshotTaker $taker): int
    {
        $now = CarbonImmutable::now();
        if (! $this->option('force') && ForecastSnapshotTaker::hasScheduled($now)) {
            $this->info('今月の自動保存の版はもう保存してあります。');

            return self::SUCCESS;
        }
        $snapshot = $taker->take($now, SnapshotTrigger::Schedule);
        $this->info("需要予測の版を保存しました（{$snapshot->item_count}品番・{$snapshot->sku_count} SKU）。");

        return self::SUCCESS;
    }
}
