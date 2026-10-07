<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemSelectionRequest;
use App\Http\Resources\ItemSelectionResource;
use App\Models\ItemSelection;
use App\Services\ItemSelection\ItemRanker;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ItemSelectionController extends Controller
{
    /**
     * 確定の履歴（新しい順）。各選定に、前回の選定からの変更（追加・外した品番）を付ける。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        $selections = ItemSelection::query()->with(['items.item', 'confirmedBy'])->latest('id')->paginate(10);

        $page = $selections->getCollection()->values();
        $olderThanPage = $page->isEmpty() ? null : ItemSelection::query()->with('items')->where('id', '<', $page->last()->id)->latest('id')->first();
        foreach ($page as $index => $selection) {
            $selection->setRelation('previous', $page->get($index + 1) ?? $olderThanPage);
        }

        return ItemSelectionResource::collection($selections);
    }

    /**
     * 今の対象品番（最新の選定）と、今年度の初日。確定したことが無ければ data は null。
     */
    public function current(): JsonResponse
    {
        $selection = ItemSelection::current()?->load(['items.item', 'confirmedBy']);

        return response()->json([
            'data' => $selection ? ItemSelectionResource::make($selection) : null,
            'meta' => ['fiscal_year_start' => ItemSelection::fiscalYearStart(today())->toDateString()],
        ]);
    }

    /**
     * 対象品番を確定する。新しい選定として記録し、前の選定は残す（決定記録 K-030）。確定時の売上順位も記録する（K-032）。
     */
    public function store(StoreItemSelectionRequest $request, ItemRanker $ranker): JsonResponse
    {
        $ranking = $ranker->rank(CarbonImmutable::today());

        $selection = DB::transaction(function () use ($request, $ranking) {
            $selection = ItemSelection::create([
                'confirmed_at' => now(),
                'confirmed_by_user_id' => $request->user()?->id,
                'ranking_from' => $ranking->from,
                'ranking_to' => $ranking->to,
                'note' => $request->validated('note'),
            ]);

            foreach ($request->validated('item_nos') as $itemNo) {
                $rank = $ranking->find($itemNo);
                $selection->items()->create([
                    'item_no' => $itemNo,
                    'sales_rank' => $rank?->rank,
                    'sales_amount' => $rank?->salesAmount,
                    'is_candidate' => $rank?->isCandidate() ?? false,
                ]);
            }

            return $selection;
        });

        return ItemSelectionResource::make($selection->load(['items.item', 'confirmedBy']))->response()->setStatusCode(201);
    }
}
