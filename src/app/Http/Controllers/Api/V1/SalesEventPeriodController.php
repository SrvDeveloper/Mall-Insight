<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSalesEventMultipliersRequest;
use App\Http\Requests\SaveSalesEventPeriodRequest;
use App\Http\Resources\SalesEventPeriodResource;
use App\Http\Resources\SalesEventResource;
use App\Models\SalesEvent;
use App\Models\SalesEventPeriod;
use App\Services\Forecast\EventUpliftEstimator;
use App\Services\Forecast\SalesEventReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SalesEventPeriodController extends Controller
{
    /**
     * 1つの期間の、チャネル・品番ごとの倍率と根拠（B-129、K-114）。
     */
    public function show(SalesEventPeriod $salesEventPeriod, SalesEventReport $report): JsonResponse
    {
        $salesEventPeriod->load(['salesEvent', 'updatedBy']);

        return response()->json([
            'data' => [
                ...SalesEventPeriodResource::make($salesEventPeriod)->resolve(),
                'event' => SalesEventResource::make($salesEventPeriod->salesEvent)->resolve(),
                ...$report->detail($salesEventPeriod, CarbonImmutable::today()),
            ],
            'meta' => [
                'recent_events' => EventUpliftEstimator::RECENT_EVENTS,
                'min_expected' => EventUpliftEstimator::MIN_EXPECTED,
                'max_days' => SalesEventPeriod::MAX_DAYS,
            ],
        ]);
    }

    public function store(SaveSalesEventPeriodRequest $request, SalesEvent $salesEvent): JsonResponse
    {
        $period = $salesEvent->periods()->create([...$this->attributes($request), 'created_by_user_id' => $request->user()?->id, 'updated_by_user_id' => $request->user()?->id]);

        return SalesEventPeriodResource::make($period->load('updatedBy'))->response()->setStatusCode(201);
    }

    public function update(SaveSalesEventPeriodRequest $request, SalesEventPeriod $salesEventPeriod): SalesEventPeriodResource
    {
        $salesEventPeriod->update([...$this->attributes($request), 'updated_by_user_id' => $request->user()?->id]);

        return SalesEventPeriodResource::make($salesEventPeriod->load('updatedBy'));
    }

    /**
     * 期間を、担当者が入れた倍率ごと削除する。
     */
    public function destroy(SalesEventPeriod $salesEventPeriod): Response
    {
        $salesEventPeriod->delete();

        return response()->noContent();
    }

    /**
     * 担当者が入れた品番ごとの倍率を、送った内容で置き換える。送らなかった品番はシステムの候補を使う。
     */
    public function updateMultipliers(SaveSalesEventMultipliersRequest $request, SalesEventPeriod $salesEventPeriod, SalesEventReport $report): JsonResponse
    {
        DB::transaction(function () use ($request, $salesEventPeriod): void {
            $salesEventPeriod->multipliers()->delete();
            $salesEventPeriod->multipliers()->createMany(array_map(fn (array $row): array => [
                'item_no' => $row['item_no'],
                'channel' => $row['channel'],
                'multiplier' => round((float) $row['multiplier'], 2),
                'updated_by_user_id' => $request->user()?->id,
            ], $request->validated('multipliers')));
            $salesEventPeriod->update(['updated_by_user_id' => $request->user()?->id]);
            $salesEventPeriod->touch();
        });

        return $this->show($salesEventPeriod->refresh(), $report);
    }

    /**
     * @return array{starts_on: string, ends_on: string, note: ?string}
     */
    private function attributes(SaveSalesEventPeriodRequest $request): array
    {
        return [
            'starts_on' => $request->validated('starts_on'),
            'ends_on' => $request->validated('ends_on'),
            'note' => $request->validated('note'),
        ];
    }
}
