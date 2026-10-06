<?php

use Illuminate\Support\Facades\Schedule;

// CrossWalker の品番・SKUを毎朝取得する（決定記録 K-018）。画面からも随時実行できる。
Schedule::command('crosswalker:sync-items')->dailyAt('06:00')->withoutOverlapping(10);
