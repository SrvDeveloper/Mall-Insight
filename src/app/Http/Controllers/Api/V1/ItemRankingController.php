<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemRankResource;
use App\Models\ItemSelection;
use App\Services\InventoryTrend\TrendScope;
use App\Services\ItemSelection\ItemRanker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class ItemRankingController extends Controller
{
    /**
     * 全品番の売上順位（決定記録 K-032）。順位の高い順で、販売実績の無い品番は最後。
     * scope で全体（初期値）・Amazon・BOSS を選び、Amazon・BOSS はそのチャネルの販売実績で順位を付ける（B-136、K-129）。
     */
    public function index(Request $request, ItemRanker $ranker): AnonymousResourceCollection
    {
        $request->validate(['scope' => ['nullable', Rule::enum(TrendScope::class)]]);
        $scope = $request->enum('scope', TrendScope::class) ?? TrendScope::Total;
        $ranking = $ranker->rank(CarbonImmutable::today(), $scope);

        return ItemRankResource::collection($ranking->ranks)->additional([
            'meta' => [
                'scope' => $scope->value,
                'ranking_from' => $ranking->from->toDateString(),
                'ranking_to' => $ranking->to->toDateString(),
                'candidate_rank' => ItemSelection::candidateRank($scope),
                'max_items' => ItemSelection::MAX_ITEMS,
            ],
        ]);
    }
}
