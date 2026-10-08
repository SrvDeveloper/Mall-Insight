<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreInventoryTrendSettingRequest;
use App\Models\InventoryTrendSetting;
use App\Services\InventoryTrend\TrendSettings;
use Illuminate\Http\JsonResponse;

class InventoryTrendSettingController extends Controller
{
    /**
     * 在庫推移の判定の基準を変更する（バックログ B-011）。新しい行として記録し、前の基準は残す（決定記録 K-050）。
     * 次に在庫推移を開いたときから、新しい基準で計算される。
     */
    public function store(StoreInventoryTrendSettingRequest $request): JsonResponse
    {
        InventoryTrendSetting::create([
            'check_month_offset' => $request->validated('check_month_offset'),
            'changed_by_user_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => TrendSettings::current()->toArray()], 201);
    }
}
