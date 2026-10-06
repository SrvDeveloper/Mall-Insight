<?php

use Illuminate\Support\Facades\Schedule;

// CrossWalker の品番・SKUを毎朝取得する（決定記録 K-018）。画面からも随時実行できる。
Schedule::command('crosswalker:sync-items')->dailyAt('06:00')->withoutOverlapping(10);

// ZeroStockView の日次在庫を毎朝取得する（決定記録 K-021）。ZeroStockView 側の在庫取込が毎朝手動のため、ゆとりを持たせて10時とする。
Schedule::command('zerostockview:sync-inventory')->dailyAt('10:00')->withoutOverlapping(15);
