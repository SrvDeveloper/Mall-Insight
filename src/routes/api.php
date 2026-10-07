<?php

use App\Http\Controllers\Api\V1\CrossWalkerSyncController;
use App\Http\Controllers\Api\V1\InventoryDateController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\SalesImportController;
use App\Http\Controllers\Api\V1\ZeroStockViewSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::get('/crosswalker-syncs/latest', [CrossWalkerSyncController::class, 'latest'])->name('crosswalker-syncs.latest');
    Route::post('/crosswalker-syncs', [CrossWalkerSyncController::class, 'store'])->name('crosswalker-syncs.store');
    Route::get('/sales-imports', [SalesImportController::class, 'index'])->name('sales-imports.index');
    Route::post('/sales-imports', [SalesImportController::class, 'store'])->name('sales-imports.store');
    Route::get('/sales-imports/{salesImport}', [SalesImportController::class, 'show'])->name('sales-imports.show');
    Route::get('/inventory-dates', [InventoryDateController::class, 'index'])->name('inventory-dates.index');
    Route::get('/zerostockview-syncs/latest', [ZeroStockViewSyncController::class, 'latest'])->name('zerostockview-syncs.latest');
    Route::post('/zerostockview-syncs', [ZeroStockViewSyncController::class, 'store'])->name('zerostockview-syncs.store');
});
