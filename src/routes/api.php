<?php

use App\Http\Controllers\Api\V1\CrossWalkerSyncController;
use App\Http\Controllers\Api\V1\ItemController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::get('/crosswalker-syncs/latest', [CrossWalkerSyncController::class, 'latest'])->name('crosswalker-syncs.latest');
    Route::post('/crosswalker-syncs', [CrossWalkerSyncController::class, 'store'])->name('crosswalker-syncs.store');
});
