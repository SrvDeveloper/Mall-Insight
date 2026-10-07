<?php

use App\Http\Controllers\Api\V1\CrossWalkerSyncController;
use App\Http\Controllers\Api\V1\DemandForecastController;
use App\Http\Controllers\Api\V1\InboundPlanController;
use App\Http\Controllers\Api\V1\InboundPlanReceiptController;
use App\Http\Controllers\Api\V1\InventoryDateController;
use App\Http\Controllers\Api\V1\InventoryTrendController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemRankingController;
use App\Http\Controllers\Api\V1\ItemSelectionController;
use App\Http\Controllers\Api\V1\SalesImportController;
use App\Http\Controllers\Api\V1\UnregisteredSkuController;
use App\Http\Controllers\Api\V1\ZeroStockViewSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::get('/items', [ItemController::class, 'index'])->name('items.index');
    Route::get('/item-ranking', [ItemRankingController::class, 'index'])->name('item-ranking.index');
    Route::get('/item-selections', [ItemSelectionController::class, 'index'])->name('item-selections.index');
    Route::get('/item-selections/current', [ItemSelectionController::class, 'current'])->name('item-selections.current');
    Route::post('/item-selections', [ItemSelectionController::class, 'store'])->name('item-selections.store');
    Route::get('/demand-forecasts', [DemandForecastController::class, 'index'])->name('demand-forecasts.index');
    Route::apiResource('inbound-plans', InboundPlanController::class)->except('show');
    Route::post('/inbound-plans/{inboundPlan}/receipt', [InboundPlanReceiptController::class, 'store'])->name('inbound-plans.receipt.store');
    Route::delete('/inbound-plans/{inboundPlan}/receipt', [InboundPlanReceiptController::class, 'destroy'])->name('inbound-plans.receipt.destroy');
    Route::get('/inventory-trends', [InventoryTrendController::class, 'index'])->name('inventory-trends.index');
    Route::get('/unregistered-skus', [UnregisteredSkuController::class, 'index'])->name('unregistered-skus.index');
    Route::get('/crosswalker-syncs/latest', [CrossWalkerSyncController::class, 'latest'])->name('crosswalker-syncs.latest');
    Route::post('/crosswalker-syncs', [CrossWalkerSyncController::class, 'store'])->name('crosswalker-syncs.store');
    Route::get('/sales-imports', [SalesImportController::class, 'index'])->name('sales-imports.index');
    Route::post('/sales-imports', [SalesImportController::class, 'store'])->name('sales-imports.store');
    Route::get('/sales-imports/{salesImport}', [SalesImportController::class, 'show'])->name('sales-imports.show');
    Route::get('/inventory-dates', [InventoryDateController::class, 'index'])->name('inventory-dates.index');
    Route::get('/zerostockview-syncs/latest', [ZeroStockViewSyncController::class, 'latest'])->name('zerostockview-syncs.latest');
    Route::post('/zerostockview-syncs', [ZeroStockViewSyncController::class, 'store'])->name('zerostockview-syncs.store');
});
