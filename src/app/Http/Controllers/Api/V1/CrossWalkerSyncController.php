<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Resources\CrossWalkerSyncResource;
use App\Models\CrossWalkerSync;
use App\Services\CrossWalker\ItemSynchronizer;
use App\Services\CrossWalker\SyncAlreadyRunningException;
use Illuminate\Http\JsonResponse;

class CrossWalkerSyncController extends Controller
{
    /**
     * 最新の取得結果と、最後に成功した取得結果。一度も取得していなければ null。
     */
    public function latest(): JsonResponse
    {
        $latest = CrossWalkerSync::query()->latestFirst()->first();
        $lastSucceeded = $latest?->status === SyncStatus::Succeeded ? $latest : CrossWalkerSync::query()->succeeded()->latestFirst()->first();

        return response()->json(['data' => [
            'latest' => $latest ? new CrossWalkerSyncResource($latest) : null,
            'last_succeeded' => $lastSucceeded ? new CrossWalkerSyncResource($lastSucceeded) : null,
        ]]);
    }

    /**
     * 画面から取得を実行する。取得に失敗した場合も、失敗の記録を 201 で返す。
     */
    public function store(ItemSynchronizer $synchronizer): JsonResponse
    {
        try {
            $sync = $synchronizer->sync(SyncTrigger::Manual);
        } catch (SyncAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new CrossWalkerSyncResource($sync))->response()->setStatusCode(201);
    }
}
