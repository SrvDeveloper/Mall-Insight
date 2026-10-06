<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Resources\ZeroStockViewSyncResource;
use App\Models\ZeroStockViewSync;
use App\Services\SyncAlreadyRunningException;
use App\Services\ZeroStockView\InventorySynchronizer;
use Illuminate\Http\JsonResponse;

class ZeroStockViewSyncController extends Controller
{
    /**
     * 最新の取得結果と、最後に成功した取得結果。一度も取得していなければ null。
     */
    public function latest(): JsonResponse
    {
        $latest = ZeroStockViewSync::query()->latestFirst()->first();
        $lastSucceeded = $latest?->status === SyncStatus::Succeeded ? $latest : ZeroStockViewSync::query()->succeeded()->latestFirst()->first();

        return response()->json(['data' => [
            'latest' => $latest ? new ZeroStockViewSyncResource($latest) : null,
            'last_succeeded' => $lastSucceeded ? new ZeroStockViewSyncResource($lastSucceeded) : null,
        ]]);
    }

    /**
     * 画面から取得を実行する。取得に失敗した場合も、失敗の記録を 201 で返す。
     */
    public function store(InventorySynchronizer $synchronizer): JsonResponse
    {
        try {
            $sync = $synchronizer->sync(SyncTrigger::Manual);
        } catch (SyncAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new ZeroStockViewSyncResource($sync))->response()->setStatusCode(201);
    }
}
