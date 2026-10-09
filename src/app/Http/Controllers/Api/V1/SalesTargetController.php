<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSalesTargetRequest;
use App\Http\Resources\ItemTargetPlanResource;
use App\Http\Resources\MonthlySalesRatioResource;
use App\Http\Resources\SalesTargetResource;
use App\Models\ItemSelection;
use App\Models\RatioCategory;
use App\Models\SalesTarget;
use App\Services\SalesTarget\SalesTargetPlanner;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SalesTargetController extends Controller
{
    /**
     * 年度の年間販売目標と、月・SKUへの割り振り（バックログ B-110）。対象品番と、対象品番でないが目標のある品番を返す。
     * 割り振りは保存せず、開くたびに最新の目標・月別販売比率・販売実績から計算する。
     */
    public function index(Request $request, SalesTargetPlanner $planner): JsonResponse
    {
        $request->validate(['fiscal_year' => ['nullable', 'integer', 'between:2000,2100']]);
        $now = CarbonImmutable::now();
        $currentFiscalYear = ItemSelection::fiscalYearStart($now)->year;
        $fiscalYear = $request->integer('fiscal_year', $currentFiscalYear);
        $plan = $planner->plan($fiscalYear, $now);

        return response()->json([
            'data' => ItemTargetPlanResource::collection($plan->items)->resolve($request),
            'meta' => [
                'fiscal_year' => $plan->fiscalYear,
                'current_fiscal_year' => $currentFiscalYear,
                'months' => array_map(fn (CarbonImmutable $month): string => $month->format('Y-m'), $plan->months),
                'ratio_categories' => RatioCategory::ordered()->map(fn (RatioCategory $category): array => [
                    'id' => $category->id,
                    'code' => $category->code?->value,
                    'name' => $category->name,
                    'ratio' => isset($plan->ratios[$category->id]) ? MonthlySalesRatioResource::make($plan->ratios[$category->id])->resolve($request) : null,
                ])->all(),
                'composition_from' => $plan->compositionFrom->toDateString(),
                'composition_to' => $plan->compositionTo->toDateString(),
            ],
        ]);
    }

    /**
     * 品番の年間販売目標を登録・変更する。新しい版として記録し、前の版は残す（原則3）。
     */
    public function store(SaveSalesTargetRequest $request): JsonResponse
    {
        $target = SalesTarget::create([
            'fiscal_year' => $request->integer('fiscal_year'),
            'item_no' => $request->validated('item_no'),
            'annual_quantity' => $request->integer('annual_quantity'),
            'monthly_quantities' => $request->monthlyQuantities(),
            'sku_quantities' => $request->skuQuantities(),
            'note' => $request->validated('note'),
            'source' => ChangeSource::Screen,
            'changed_by_user_id' => $request->user()?->id,
        ]);

        return SalesTargetResource::make($target->load('changedBy'))->response()->setStatusCode(201);
    }

    /**
     * 品番の年間販売目標の変更履歴（新しい順）。
     */
    public function history(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'item_no' => ['required', 'string'],
        ]);

        return SalesTargetResource::collection(
            SalesTarget::query()->with('changedBy')->forItem((int) $validated['fiscal_year'], $validated['item_no'])->latest('id')->get(),
        );
    }
}
