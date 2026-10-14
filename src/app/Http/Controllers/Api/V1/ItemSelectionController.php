<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreItemSelectionRequest;
use App\Http\Resources\ItemSelectionResource;
use App\Models\ItemSelection;
use App\Services\InventoryTrend\TrendScope;
use App\Services\ItemSelection\ItemRanker;
use App\Services\ItemSelection\TargetItems;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ItemSelectionController extends Controller
{
    /**
     * 確定の履歴（新しい順）。各選定に、前回の選定からの変更（追加・外した品番）を付ける。scope で全体（初期値）・Amazon・BOSS を選ぶ（B-136）。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1'], 'scope' => ['nullable', Rule::enum(TrendScope::class)]]);
        $scope = $request->enum('scope', TrendScope::class) ?? TrendScope::Total;

        $selections = ItemSelection::query()->where('scope', $scope->value)->with(['items.item', 'confirmedBy'])->latest('id')->paginate(10);

        $page = $selections->getCollection()->values();
        $olderThanPage = $page->isEmpty() ? null : ItemSelection::query()->where('scope', $scope->value)->with('items')->where('id', '<', $page->last()->id)->latest('id')->first();
        foreach ($page as $index => $selection) {
            $selection->setRelation('previous', $page->get($index + 1) ?? $olderThanPage);
        }

        return ItemSelectionResource::collection($selections);
    }

    /**
     * 今の対象品番（最新の選定）と、今年度の初日。scope で Amazon・BOSS を選ぶ（B-136、K-129・K-130）。確定したことが無ければ data は null。
     * item_nos は画面に出す対象品番で、未確定ならそのチャネルの売上上位の品番（uses_candidates）。全体は確定せず、Amazon・BOSS の対象品番を合わせたものを
     * item_nos・amazon_item_nos・boss_item_nos で返す。
     */
    public function current(Request $request, TargetItems $targets): JsonResponse
    {
        $request->validate(['scope' => ['nullable', Rule::enum(TrendScope::class)]]);
        $scope = $request->enum('scope', TrendScope::class) ?? TrendScope::Total;
        $channel = $scope->channel();
        $selection = $channel === null ? null : $targets->selection($channel)?->load(['items.item', 'confirmedBy']);

        return response()->json([
            'data' => $selection ? ItemSelectionResource::make($selection) : null,
            'meta' => [
                'scope' => $scope->value,
                'item_nos' => $targets->itemNos($scope),
                'uses_candidates' => $targets->usesCandidates($scope),
                ...($channel === null ? [
                    'amazon_item_nos' => $targets->itemNos(TrendScope::Amazon),
                    'boss_item_nos' => $targets->itemNos(TrendScope::Boss),
                    'amazon_uses_candidates' => $targets->usesCandidates(TrendScope::Amazon),
                    'boss_uses_candidates' => $targets->usesCandidates(TrendScope::Boss),
                ] : []),
                'fiscal_year_start' => ItemSelection::fiscalYearStart(today())->toDateString(),
            ],
        ]);
    }

    /**
     * 対象品番を確定する。新しい選定として記録し、前の選定は残す（決定記録 K-030）。確定時の売上順位も記録する（K-032）。
     * scope で Amazon・BOSS のどちらを確定するかを選び、そのチャネルの売上順位を記録する（B-136、K-130。全体は確定しない）。
     */
    public function store(StoreItemSelectionRequest $request, ItemRanker $ranker): JsonResponse
    {
        $scope = $request->enum('scope', TrendScope::class);
        $ranking = $ranker->rank(CarbonImmutable::today(), $scope);

        $selection = DB::transaction(function () use ($request, $ranking, $scope) {
            $selection = ItemSelection::create([
                'scope' => $scope,
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
