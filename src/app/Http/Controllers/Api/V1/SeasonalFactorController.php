<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSeasonalFactorRequest;
use App\Models\Item;
use App\Models\ItemSeasonalFactor;
use App\Services\Forecast\SeasonalFactorReport;
use App\Services\Forecast\SeasonalIndexEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class SeasonalFactorController extends Controller
{
    /**
     * 対象品番の季節係数（B-106）。登録した係数と、初期値にする販売実績から求めた係数。
     */
    public function index(SeasonalFactorReport $report): JsonResponse
    {
        return response()->json(['data' => $report->overview(CarbonImmutable::today()), 'meta' => $this->meta()]);
    }

    /**
     * 1つの品番の季節係数と、決める手がかり（3つの単位で求めた係数・年と月ごとの販売数・登録の履歴）。
     */
    public function show(string $itemNo, SeasonalFactorReport $report): JsonResponse
    {
        $item = Item::query()->with('skus')->where('item_no', $itemNo)->firstOrFail();

        return response()->json(['data' => $report->detail($item, CarbonImmutable::today()), 'meta' => $this->meta()]);
    }

    /**
     * 品番の季節係数を登録する（null で登録をやめる）。変更のたびに新しい記録として残し、そのときの初期値も残す。
     */
    public function update(SaveSeasonalFactorRequest $request, string $itemNo, SeasonalFactorReport $report): JsonResponse
    {
        $item = Item::query()->with('skus')->where('item_no', $itemNo)->firstOrFail();
        $today = CarbonImmutable::today();
        $initial = $report->initial($report->levels(collect([$item]), $today)[$item->item_no] ?? []);
        $factors = $request->validated('factors');
        ItemSeasonalFactor::create([
            'item_no' => $item->item_no,
            'factors' => $factors === null ? null : array_map(fn ($factor): float => round((float) $factor, 2), array_values($factors)),
            'initial_factors' => $initial?->factors === null ? null : array_map(fn (float $factor): float => round($factor, 2), array_values($initial->factors)),
            'initial_source' => $initial?->source,
            'note' => $request->validated('note'),
            'changed_by_user_id' => $request->user()?->id,
        ]);

        return $this->show($item->item_no, $report);
    }

    /**
     * @return array{min_factor: float, max_factor: float, min_monthly_quantity: float}
     */
    private function meta(): array
    {
        return [
            'min_factor' => ItemSeasonalFactor::MIN_FACTOR,
            'max_factor' => ItemSeasonalFactor::MAX_FACTOR,
            'min_monthly_quantity' => SeasonalIndexEstimator::MIN_MONTHLY_QUANTITY,
        ];
    }
}
