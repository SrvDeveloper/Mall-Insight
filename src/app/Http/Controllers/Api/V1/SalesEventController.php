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
     * イベントの一覧（B-129、K-114）。イベントごとに、期間（初日の順）と、期間・チャネルごとの倍率（これからの期間は候補、
     * 終わった期間は普段の何倍売れたか）を添える。次の期間（開催中かこれから）の早い順、無ければ最後の期間の新しい順に並べる。
     */
    public function index(SalesEventReport $report): JsonResponse
    {
        $today = CarbonImmutable::today();
        $events = SalesEvent::query()->with(['periods.multipliers', 'periods.updatedBy', 'createdBy', 'updatedBy'])->get();
        $periods = $events->flatMap(fn (SalesEvent $event) => $event->periods->each(fn (SalesEventPeriod $period) => $period->setRelation('salesEvent', $event)))->sortBy(fn (SalesEventPeriod $period): string => $period->starts_on->toDateString())->values();
        $overview = $report->overview($periods, $today);

        $rows = $events->map(fn (SalesEvent $event): array => $this->eventData($event, $overview, $today));

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
     * 1つのイベントと、その期間・倍率。
     */
    public function show(SalesEvent $salesEvent, SalesEventReport $report): JsonResponse
    {
        $today = CarbonImmutable::today();
        $salesEvent->load(['periods.multipliers', 'periods.updatedBy', 'createdBy', 'updatedBy']);
        $all = app(EventUpliftEstimator::class)->periods();

        return response()->json([
            'data' => $this->eventData($salesEvent, $report->overview($all, $today), $today),
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

        return $this->show($event, $report)->setStatusCode(201);
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

        return $this->show($salesEvent->refresh(), $report);
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
     * @param  array<int, list<array<string, mixed>>>  $overview  期間ID => チャネルごとの倍率
     * @return array<string, mixed>
     */
    private function eventData(SalesEvent $event, array $overview, CarbonImmutable $today): array
    {
        /** @var Collection<int, SalesEventPeriod> $periods */
        $periods = $event->periods;
        $periodRows = $periods->map(fn (SalesEventPeriod $period): array => [
            ...SalesEventPeriodResource::make($period)->resolve(),
            'multipliers' => $overview[$period->id] ?? [],
        ])->values();
        $next = $periodRows->first(fn (array $period): bool => $period['status'] !== 'ended');
        $last = $periodRows->filter(fn (array $period): bool => $period['status'] === 'ended')->last();

        return [
            ...SalesEventResource::make($event)->resolve(),
            'periods' => $periodRows->all(),
            'next_period' => $next,
            'last_period' => $last,
            'upcoming_count' => $periodRows->filter(fn (array $period): bool => $period['status'] !== 'ended')->count(),
            'ended_count' => $periodRows->filter(fn (array $period): bool => $period['status'] === 'ended')->count(),
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
