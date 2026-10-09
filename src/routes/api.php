<?php

use App\Http\Controllers\Api\V1\ActualController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CrossWalkerSyncController;
use App\Http\Controllers\Api\V1\DemandForecastController;
use App\Http\Controllers\Api\V1\InboundPlanController;
use App\Http\Controllers\Api\V1\InboundPlanReceiptController;
use App\Http\Controllers\Api\V1\InventoryDateController;
use App\Http\Controllers\Api\V1\InventoryTrendController;
use App\Http\Controllers\Api\V1\InventoryTrendSettingController;
use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\ItemRankingController;
use App\Http\Controllers\Api\V1\ItemRatioCategoryController;
use App\Http\Controllers\Api\V1\ItemSelectionController;
use App\Http\Controllers\Api\V1\ItemUnitPriceController;
use App\Http\Controllers\Api\V1\MonthlySalesRatioController;
use App\Http\Controllers\Api\V1\RatioCategoryController;
use App\Http\Controllers\Api\V1\SalesImportController;
use App\Http\Controllers\Api\V1\SalesTargetCarryOverController;
use App\Http\Controllers\Api\V1\SalesTargetController;
use App\Http\Controllers\Api\V1\UnregisteredSkuController;
use App\Http\Controllers\Api\V1\ZeroStockViewSyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // ログイン以外のAPIは、ログインしていないと呼べない（B-009）
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth')->group(function () {
        Route::get('/user', [AuthController::class, 'user'])->name('user');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/items', [ItemController::class, 'index'])->name('items.index');
        Route::get('/item-ranking', [ItemRankingController::class, 'index'])->name('item-ranking.index');
        Route::get('/item-selections', [ItemSelectionController::class, 'index'])->name('item-selections.index');
        Route::get('/item-selections/current', [ItemSelectionController::class, 'current'])->name('item-selections.current');
        Route::post('/item-selections', [ItemSelectionController::class, 'store'])->name('item-selections.store');
        Route::get('/demand-forecasts', [DemandForecastController::class, 'index'])->name('demand-forecasts.index');
        Route::apiResource('inbound-plans', InboundPlanController::class)->except('show');
        Route::post('/inbound-plans/{inboundPlan}/receipt', [InboundPlanReceiptController::class, 'store'])->name('inbound-plans.receipt.store');
        Route::delete('/inbound-plans/{inboundPlan}/receipt', [InboundPlanReceiptController::class, 'destroy'])->name('inbound-plans.receipt.destroy');
        Route::get('/sales-targets', [SalesTargetController::class, 'index'])->name('sales-targets.index');
        Route::get('/sales-targets/history', [SalesTargetController::class, 'history'])->name('sales-targets.history');
        Route::post('/sales-targets', [SalesTargetController::class, 'store'])->name('sales-targets.store');
        Route::get('/sales-targets/carry-over', [SalesTargetCarryOverController::class, 'index'])->name('sales-targets.carry-over.index');
        Route::post('/sales-targets/carry-over', [SalesTargetCarryOverController::class, 'store'])->name('sales-targets.carry-over.store');
        Route::post('/monthly-sales-ratios', [MonthlySalesRatioController::class, 'store'])->name('monthly-sales-ratios.store');
        Route::post('/ratio-categories', [RatioCategoryController::class, 'store'])->name('ratio-categories.store');
        Route::patch('/ratio-categories/{ratioCategory}', [RatioCategoryController::class, 'update'])->name('ratio-categories.update');
        Route::put('/item-ratio-categories/{itemNo}', [ItemRatioCategoryController::class, 'update'])->name('item-ratio-categories.update');
        Route::get('/item-unit-prices', [ItemUnitPriceController::class, 'index'])->name('item-unit-prices.index');
        Route::put('/item-unit-prices/{itemNo}', [ItemUnitPriceController::class, 'update'])->name('item-unit-prices.update');
        Route::get('/inventory-trends', [InventoryTrendController::class, 'index'])->name('inventory-trends.index');
        Route::get('/actuals/sales', [ActualController::class, 'sales'])->name('actuals.sales');
        Route::get('/actuals/stock', [ActualController::class, 'stock'])->name('actuals.stock');
        Route::post('/inventory-trend-settings', [InventoryTrendSettingController::class, 'store'])->name('inventory-trend-settings.store');
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
});
