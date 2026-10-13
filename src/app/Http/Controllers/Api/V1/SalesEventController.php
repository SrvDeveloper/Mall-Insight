<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSalesEventMultipliersRequest;
use App\Http\Requests\SaveSalesEventRequest;
use App\Http\Resources\SalesEventResource;
use App\Models\SalesEvent;
use App\Services\Forecast\EventUpliftEstimator;
use App\Services\Forecast\SalesEventReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class SalesEventController extends Controller
{
    /**
     * イベントの一覧（初日の新しい順、B-129）。チャネルごとに、これからのイベントは倍率の候補（品番の全体）、終わったイベントは普段の何倍売れたかを添える。
     */
    public function index(SalesEventReport $report): JsonResponse
    {
        $today = CarbonImmutable::today();
        $events = SalesEvent::query()->with(['multipliers', 'createdBy', 'updatedBy'])->orderBy('starts_on')->orderBy('id')->get();
        $overview = $report->overview($events, $today);

        return response()->json([
            'data' => $events->sortByDesc(fn (SalesEvent $event): string => $event->starts_on->toDateString().sprintf('%010d', $event->id))->values()->map(fn (SalesEvent $event): array => [
                ...SalesEventResource::make($event)->resolve(),
                'multipliers' => $overview[$event->id] ?? [],
            ])->all(),
            'meta' => $this->meta(),
        ]);
    }

    /**
     * 1つのイベントの、チャネル・品番ごとの倍率と根拠。
     */
    public function show(SalesEvent $salesEvent, SalesEventReport $report): JsonResponse
    {
        $salesEvent->load(['createdBy', 'updatedBy']);

        return response()->json([
            'data' => [
                ...SalesEventResource::make($salesEvent)->resolve(),
                ...$report->detail($salesEvent, CarbonImmutable::today()),
            ],
            'meta' => $this->meta(),
        ]);
    }

    public function store(SaveSalesEventRequest $request): JsonResponse
    {
        $event = SalesEvent::create([...$this->attributes($request), 'created_by_user_id' => $request->user()?->id, 'updated_by_user_id' => $request->user()?->id]);

        return SalesEventResource::make($event->load(['createdBy', 'updatedBy']))->response()->setStatusCode(201);
    }

    /**
     * イベントを変更する。モールを変えてチャネルが外れたら、そのチャネルで担当者が入れた倍率は消す。
     */
    public function update(SaveSalesEventRequest $request, SalesEvent $salesEvent): SalesEventResource
    {
        DB::transaction(function () use ($request, $salesEvent): void {
            $salesEvent->update([...$this->attributes($request), 'updated_by_user_id' => $request->user()?->id]);
            $channels = array_map(fn ($channel): string => $channel->value, $salesEvent->channels());
            $salesEvent->multipliers()->whereNotIn('channel', $channels)->delete();
        });

        return SalesEventResource::make($salesEvent->load(['createdBy', 'updatedBy']));
    }

    public function destroy(SalesEvent $salesEvent): Response
    {
        $salesEvent->delete();

        return response()->noContent();
    }

    /**
     * 担当者が入れた品番ごとの倍率を、送った内容で置き換える。送らなかった品番はシステムの候補を使う。
     */
    public function updateMultipliers(SaveSalesEventMultipliersRequest $request, SalesEvent $salesEvent, SalesEventReport $report): JsonResponse
    {
        DB::transaction(function () use ($request, $salesEvent): void {
            $salesEvent->multipliers()->delete();
            $salesEvent->multipliers()->createMany(array_map(fn (array $row): array => [
                'item_no' => $row['item_no'],
                'channel' => $row['channel'],
                'multiplier' => round((float) $row['multiplier'], 2),
                'updated_by_user_id' => $request->user()?->id,
            ], $request->validated('multipliers')));
            $salesEvent->touch();
            $salesEvent->update(['updated_by_user_id' => $request->user()?->id]);
        });

        return $this->show($salesEvent->refresh(), $report);
    }

    /**
     * @return array{name: string, starts_on: string, ends_on: string, malls: list<string>, note: ?string}
     */
    private function attributes(SaveSalesEventRequest $request): array
    {
        return [
            'name' => trim((string) $request->validated('name')),
            'starts_on' => $request->validated('starts_on'),
            'ends_on' => $request->validated('ends_on'),
            'malls' => array_values($request->validated('malls')),
            'note' => $request->validated('note'),
        ];
    }

    /**
     * 倍率の候補の求め方（画面の説明に使う）。
     *
     * @return array{recent_events: int, min_expected: float, max_days: int}
     */
    private function meta(): array
    {
        return [
            'recent_events' => EventUpliftEstimator::RECENT_EVENTS,
            'min_expected' => EventUpliftEstimator::MIN_EXPECTED,
            'max_days' => SalesEvent::MAX_DAYS,
        ];
    }
}
