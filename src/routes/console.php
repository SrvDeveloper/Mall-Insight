<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// CrossWalker の品番・SKUを毎朝取得する（決定記録 K-018）。画面からも随時実行できる。
Schedule::command('crosswalker:sync-items')->dailyAt('06:00')->withoutOverlapping(10);

// ZeroStockView の日次在庫を毎朝取得する（決定記録 K-021）。ZeroStockView 側の在庫取込が毎朝手動のため、ゆとりを持たせて10時とする。
Schedule::command('zerostockview:sync-inventory')->dailyAt('10:00')->withoutOverlapping(15);

// キャッシュをデータベースに置くときは、期限の切れた行が残り続けるため毎晩消す（イベントの倍率を測った結果などを30日残す、K-121）。
Schedule::call(function (): void {
    if (config('cache.default') === 'database') {
        DB::connection(config('cache.stores.database.connection'))->table(config('cache.stores.database.table'))->where('expiration', '<', now()->getTimestamp())->delete();
    }
})->name('cache:delete-expired')->dailyAt('03:00')->withoutOverlapping(10);
