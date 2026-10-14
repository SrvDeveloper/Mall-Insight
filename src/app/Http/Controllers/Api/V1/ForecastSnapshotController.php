<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SnapshotTrigger;
use App\Http\Controllers\Controller;
use App\Models\ForecastSnapshot;
use App\Services\Forecast\ForecastSnapshotEvaluator;
use App\Services\Forecast\ForecastSnapshotTaker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ForecastSnapshotController extends Controller
{
    /** 一覧に出す版の数（新しい順）。 */
    public const LIMIT = 24;

    /**
     * 保存した需要予測の版（B-108、K-136）と、版ごとの答え合わせ（何か月先ごと・全体／チャネルごと、補正の後と前）。新しい順。
     */
    public function index(ForecastSnapshotEvaluator $evaluator): JsonResponse
    {
        $snapshots = ForecastSnapshot::query()->with('createdBy')->latest('taken_at')->latest('id')->limit(self::LIMIT)->get();
        $evaluations = $evaluator->evaluate($snapshots, CarbonImmutable::today());

        return response()->json([
            'data' => $snapshots->map(fn (ForecastSnapshot $snapshot): array => [
                'id' => $snapshot->id,
                'taken_at' => $snapshot->taken_at->toIso8601String(),
                'forecast_from' => $snapshot->forecast_from->toDateString(),
                'trigger' => $snapshot->trigger->value,
                'trigger_label' => $snapshot->trigger->label(),
                'created_by' => $snapshot->createdBy?->name,
                'check_month_offset' => $snapshot->check_month_offset,
                'channels' => $snapshot->channels,
                'item_count' => $snapshot->item_count,
                'sku_count' => $snapshot->sku_count,
                'adjustment_count' => count($snapshot->adjustments),
                'evaluations' => $evaluations[$snapshot->id] ?? [],
            ])->all(),
        ]);
    }

    /**
     * 今の需要予測を、画面から版として保存する。
     */
    public function store(Request $request, ForecastSnapshotTaker $taker): JsonResponse
    {
        $snapshot = $taker->take(CarbonImmutable::now(), SnapshotTrigger::Manual, $request->user()?->id);

        return response()->json(['data' => ['id' => $snapshot->id, 'item_count' => $snapshot->item_count, 'sku_count' => $snapshot->sku_count]], 201);
    }
}
