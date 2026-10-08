<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\InboundPlanIndexRequest;
use App\Http\Requests\SaveInboundPlanRequest;
use App\Http\Resources\InboundPlanResource;
use App\Models\InboundPlan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class InboundPlanController extends Controller
{
    /**
     * 入荷予定の一覧（入荷予定月の古い順）。入荷済みは、指定したときだけ含める。
     * meta.summary に、絞り込みによらない入荷前の入荷予定のまとめ（件数・数量・未割り振り・予定月を過ぎた件数）と入荷済みの件数を付ける。
     */
    public function index(InboundPlanIndexRequest $request): AnonymousResourceCollection
    {
        $keyword = $request->validated('keyword');

        $plans = InboundPlan::query()
            ->with(['item', 'allocations.sku'])
            ->when(! $request->boolean('include_received'), fn (Builder $query) => $query->whereNull('received_at'))
            ->when($keyword, fn (Builder $query) => $query->whereLike('item_no', "%{$keyword}%"))
            ->orderBy('arrival_month')
            ->orderBy('item_no')
            ->orderBy('id')
            ->paginate(50)
            ->withQueryString();

        return InboundPlanResource::collection($plans)->additional(['meta' => ['summary' => $this->summary()]]);
    }

    /**
     * 入荷前の入荷予定のまとめと、入荷済みの件数。
     *
     * @return array{pending_count: int, pending_quantity: int, unallocated_quantity: int, overdue_count: int, received_count: int}
     */
    private function summary(): array
    {
        $pending = InboundPlan::query()->with('allocations')->whereNull('received_at')->get();
        $currentMonth = today()->startOfMonth();

        return [
            'pending_count' => $pending->count(),
            'pending_quantity' => (int) $pending->sum('quantity'),
            'unallocated_quantity' => (int) $pending->sum(fn (InboundPlan $plan): int => max(0, $plan->quantity - $plan->allocatedQuantity())),
            'overdue_count' => $pending->filter(fn (InboundPlan $plan): bool => $plan->arrival_month->lt($currentMonth))->count(),
            'received_count' => InboundPlan::query()->whereNotNull('received_at')->count(),
        ];
    }

    public function store(SaveInboundPlanRequest $request): JsonResponse
    {
        $plan = DB::transaction(function () use ($request): InboundPlan {
            $plan = InboundPlan::create($this->attributes($request));
            $this->saveAllocations($plan, $request);

            return $plan;
        });

        return InboundPlanResource::make($plan->load(['item', 'allocations.sku']))->response()->setStatusCode(201);
    }

    /**
     * 入荷予定を変更する。SKUへの割り振りは送られた内容でまるごと置き換える。
     */
    public function update(SaveInboundPlanRequest $request, InboundPlan $inboundPlan): InboundPlanResource
    {
        DB::transaction(function () use ($request, $inboundPlan): void {
            $inboundPlan->update($this->attributes($request));
            $inboundPlan->allocations()->delete();
            $this->saveAllocations($inboundPlan, $request);
        });

        return InboundPlanResource::make($inboundPlan->load(['item', 'allocations.sku']));
    }

    public function destroy(InboundPlan $inboundPlan): Response
    {
        $inboundPlan->delete();

        return response()->noContent();
    }

    /**
     * @return array{item_no: string, arrival_month: CarbonImmutable, quantity: int, note: ?string}
     */
    private function attributes(SaveInboundPlanRequest $request): array
    {
        return [
            'item_no' => $request->validated('item_no'),
            'arrival_month' => CarbonImmutable::createFromFormat('!Y-m', $request->validated('arrival_month')),
            'quantity' => (int) $request->validated('quantity'),
            'note' => $request->validated('note'),
        ];
    }

    /**
     * 0 の割り振りは保存しない。
     */
    private function saveAllocations(InboundPlan $plan, SaveInboundPlanRequest $request): void
    {
        $allocations = array_values(array_filter($request->validated('allocations'), fn (array $allocation): bool => (int) $allocation['quantity'] > 0));
        $plan->allocations()->createMany(array_map(fn (array $allocation): array => ['sku_id' => (int) $allocation['sku_id'], 'quantity' => (int) $allocation['quantity']], $allocations));
    }
}
