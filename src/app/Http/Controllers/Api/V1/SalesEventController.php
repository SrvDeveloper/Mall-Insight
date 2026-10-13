<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveSalesEventRequest;
use App\Http\Resources\SalesEventPeriodResource;
use App\Http\Resources\SalesEventResource;
use App\Models\SalesEvent;
use App\Models\SalesEventMultiplier;
use App\Models\SalesEventPeriod;
use App\Services\Forecast\EventUpliftEstimator;
use App\Services\Forecast\SalesEventReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalesEventController extends Controller
{
    /**
     * イベントの一覧（B-129、K-114・K-120）。イベントごとに、次の期間（開催中かこれから）と最後に終わった期間、期間の数を添える。
     * 倍率（これからの期間は候補、終わった期間は普段の何倍売れたか）は、この2つの期間だけ求める（すべての期間を測ると遅いため。
     * 期間ごとの倍率はイベントの画面で出す）。次の期間の早い順、無ければ最後の期間の新しい順に並べる。
     */
    public function index(SalesEventReport $report, EventUpliftEstimator $estimator): JsonResponse
    {
        $today = CarbonImmutable::today();
        $all = $estimator->periods()->load('updatedBy');
        $events = SalesEvent::query()->with(['createdBy', 'updatedBy'])->get();
        $byEvent = $all->groupBy('sales_event_id');
        $shown = $events->flatMap(function (SalesEvent $event) use ($byEvent, $today): array {
            $periods = $byEvent->get($event->id, collect());

            return array_values(array_filter([
                $periods->first(fn (SalesEventPeriod $period): bool => ! $period->hasEndedBefore($today)),
                $periods->filter(fn (SalesEventPeriod $period): bool => $period->hasEndedBefore($today))->last(),
            ]));
        });
        $overview = $report->overview($all, $shown, $today);

        $rows = $events->map(fn (SalesEvent $event): array => $this->eventData($event, $byEvent->get($event->id, collect()), $overview, $today, withPeriods: false));

        return response()->json([
            'data' => $rows->sort(function (array $a, array $b): int {
                // 次の期間のあるイベントを先に、その初日の早い順。無いイベントは、最後の期間の初日の新しい順
                if (($a['next_period'] === null) !== ($b['next_period'] === null)) {
                    return $a['next_period'] === null ? 1 : -1;
                }
                if ($a['next_period'] !== null) {
                    return [$a['next_period']['starts_on'], $a['id']] <=> [$b['next_period']['starts_on'], $b['id']];
                }

                return [$b['last_period']['starts_on'] ?? '', $b['id']] <=> [$a['last_period']['starts_on'] ?? '', $a['id']];
            })->values()->all(),
            'meta' => $this->meta(),
        ]);
    }

    /**
     * 1つのイベントと、その期間・倍率（このイベントの期間だけ求める）。
     */
    public function show(SalesEvent $salesEvent, SalesEventReport $report, EventUpliftEstimator $estimator): JsonResponse
    {
        $today = CarbonImmutable::today();
        $salesEvent->load(['createdBy', 'updatedBy']);
        $all = $estimator->periods();
        $periods = $all->where('sales_event_id', $salesEvent->id)->values();
        $periods->load('updatedBy');

        return response()->json([
            'data' => $this->eventData($salesEvent, $periods, $report->overview($all, $periods, $today), $today),
            'meta' => $this->meta(),
        ]);
    }

    /**
     * イベントを登録する。最初の期間を一緒に入れられる。
     */
    public function store(SaveSalesEventRequest $request, SalesEventReport $report): JsonResponse
    {
        $event = DB::transaction(function () use ($request): SalesEvent {
            $event = SalesEvent::create([...$this->attributes($request), 'created_by_user_id' => $request->user()?->id, 'updated_by_user_id' => $request->user()?->id]);
            $period = $request->validated('period');
            if (is_array($period) && ($period['starts_on'] ?? null) !== null) {
                $event->periods()->create([
                    'starts_on' => $period['starts_on'],
                    'ends_on' => $period['ends_on'],
                    'note' => $period['note'] ?? null,
                    'created_by_user_id' => $request->user()?->id,
                    'updated_by_user_id' => $request->user()?->id,
                ]);
            }

            return $event;
        });

        return $this->show($event, $report, app(EventUpliftEstimator::class))->setStatusCode(201);
    }

    /**
     * イベントを変更する。モールを変えてチャネルが外れたら、そのチャネルで担当者が入れた倍率は消す。
     */
    public function update(SaveSalesEventRequest $request, SalesEvent $salesEvent, SalesEventReport $report): JsonResponse
    {
        DB::transaction(function () use ($request, $salesEvent): void {
            $salesEvent->update([...$this->attributes($request), 'updated_by_user_id' => $request->user()?->id]);
            $channels = array_map(fn ($channel): string => $channel->value, $salesEvent->channels());
            SalesEventMultiplier::query()->whereIn('sales_event_period_id', $salesEvent->periods()->select('id'))->whereNotIn('channel', $channels)->delete();
        });

        return $this->show($salesEvent->refresh(), $report, app(EventUpliftEstimator::class));
    }

    /**
     * イベントを、期間と担当者が入れた倍率ごと削除する。
     */
    public function destroy(SalesEvent $salesEvent): Response
    {
        $salesEvent->delete();

        return response()->noContent();
    }

    /**
     * @param  Collection<int, SalesEventPeriod>  $periods  イベントの期間（初日の順）
     * @param  array<int, list<array<string, mixed>>>  $overview  期間ID => チャネルごとの倍率（求めた期間だけ）
     * @param  bool  $withPeriods  false なら期間の一覧を付けず、期間の数だけにする（一覧）
     * @return array<string, mixed>
     */
    private function eventData(SalesEvent $event, Collection $periods, array $overview, CarbonImmutable $today, bool $withPeriods = true): array
    {
        $row = fn (SalesEventPeriod $period): array => [
            ...SalesEventPeriodResource::make($period)->resolve(),
            'multipliers' => $overview[$period->id] ?? [],
        ];
        $next = $periods->first(fn (SalesEventPeriod $period): bool => ! $period->hasEndedBefore($today));
        $last = $periods->filter(fn (SalesEventPeriod $period): bool => $period->hasEndedBefore($today))->last();
        $endedCount = $periods->filter(fn (SalesEventPeriod $period): bool => $period->hasEndedBefore($today))->count();

        return [
            ...SalesEventResource::make($event)->resolve(),
            ...($withPeriods ? ['periods' => $periods->map($row)->values()->all()] : []),
            'period_count' => $periods->count(),
            'next_period' => $next === null ? null : $row($next),
            'last_period' => $last === null ? null : $row($last),
            'upcoming_count' => $periods->count() - $endedCount,
            'ended_count' => $endedCount,
        ];
    }

    /**
     * @return array{name: string, malls: list<string>, note: ?string}
     */
    private function attributes(SaveSalesEventRequest $request): array
    {
        return [
            'name' => trim((string) $request->validated('name')),
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
            'max_days' => SalesEventPeriod::MAX_DAYS,
        ];
    }
}
