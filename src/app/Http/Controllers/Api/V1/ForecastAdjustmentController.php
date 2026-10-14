<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveForecastAdjustmentRequest;
use App\Http\Resources\ForecastAdjustmentResource;
use App\Models\ForecastAdjustment;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ForecastAdjustmentController extends Controller
{
    /**
     * 品番の手動での予測補正（B-109、K-132）。取り消した・終えた補正も記録として返す（新しい順）。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['item_no' => ['required', 'string', 'max:255']]);

        return ForecastAdjustmentResource::collection(
            ForecastAdjustment::query()->with(['createdBy', 'cancelledBy'])->where('item_no', $request->string('item_no')->toString())->latest('id')->get(),
        )->additional(['meta' => ['min_percent' => ForecastAdjustment::MIN_PERCENT, 'max_percent' => ForecastAdjustment::MAX_PERCENT]]);
    }

    /**
     * 補正を登録する。replaces を送ると、その補正を取り消して新しい補正を登録する（補正を変える）。
     */
    public function store(SaveForecastAdjustmentRequest $request): JsonResponse
    {
        $adjustment = DB::transaction(function () use ($request): ForecastAdjustment {
            $request->replaced()?->update(['cancelled_at' => CarbonImmutable::now(), 'cancelled_by_user_id' => $request->user()?->id]);

            return ForecastAdjustment::create([
                'item_no' => $request->validated('item_no'),
                'channel' => $request->validated('channel'),
                'starts_on' => $request->startsOn(),
                'ends_on' => $request->endsOn(),
                'rate_percent' => (int) $request->validated('rate_percent'),
                'reason' => $request->validated('reason'),
                'created_by_user_id' => $request->user()?->id,
            ]);
        });

        return ForecastAdjustmentResource::make($adjustment->load(['createdBy', 'cancelledBy']))->response()->setStatusCode(201);
    }

    /**
     * 補正を取り消す。行は消さず、取り消した日時と利用者を残す（原則3）。
     */
    public function cancel(Request $request, ForecastAdjustment $forecastAdjustment): ForecastAdjustmentResource
    {
        abort_if($forecastAdjustment->cancelled_at !== null, 422, 'この補正はもう取り消されています。');
        $forecastAdjustment->update(['cancelled_at' => CarbonImmutable::now(), 'cancelled_by_user_id' => $request->user()?->id]);

        return ForecastAdjustmentResource::make($forecastAdjustment->load(['createdBy', 'cancelledBy']));
    }
}
