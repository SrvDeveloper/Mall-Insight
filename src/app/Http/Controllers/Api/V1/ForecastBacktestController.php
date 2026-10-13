<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ForecastBacktestResource;
use App\Models\ForecastBacktest;
use App\Services\Forecast\BacktestHorizon;
use App\Services\Forecast\ForecastBacktestRecorder;
use App\Services\Forecast\ForecastBacktestSummary;
use App\Services\SyncAlreadyRunningException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ForecastBacktestController extends Controller
{
    /**
     * 予測精度を測った記録（新しい順、最新20件、B-130）。
     */
    public function index(): AnonymousResourceCollection
    {
        return ForecastBacktestResource::collection(ForecastBacktest::query()->with('measuredBy')->orderByDesc('id')->get());
    }

    /**
     * 測った結果を、何か月先（horizon、初期値は1か月目）とチャネル（channel：total（初期値）・boss・amazon）で絞り、方式ごと・品番ごと・起点ごとに集計して返す。
     */
    public function show(Request $request, ForecastBacktest $forecastBacktest, ForecastBacktestSummary $summary): JsonResponse
    {
        $request->validate([
            'horizon' => ['nullable', Rule::enum(BacktestHorizon::class)],
            'channel' => ['nullable', Rule::in(['total', 'boss', 'amazon'])],
        ]);
        $horizon = $request->enum('horizon', BacktestHorizon::class) ?? BacktestHorizon::FirstMonth;
        $channel = $request->input('channel', 'total');

        return response()->json([
            'data' => $summary->summarize($forecastBacktest, $horizon, $channel === 'total' ? null : $channel),
            'meta' => [
                'backtest' => new ForecastBacktestResource($forecastBacktest->load('measuredBy')),
                'horizon' => $horizon->value,
                'channel' => $channel,
            ],
        ]);
    }

    /**
     * 今の販売実績で測り直す。測るのに数秒かかる。別の測定が実行中なら 409。
     */
    public function store(Request $request, ForecastBacktestRecorder $recorder): JsonResponse
    {
        try {
            $backtest = $recorder->record(CarbonImmutable::now(), $request->user());
        } catch (SyncAlreadyRunningException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return (new ForecastBacktestResource($backtest->load('measuredBy')))->response()->setStatusCode(201);
    }
}
