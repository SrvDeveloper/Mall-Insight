<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ItemRankResource;
use App\Models\ItemSelection;
use App\Services\ItemSelection\ItemRanker;
use Carbon\CarbonImmutable;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemRankingController extends Controller
{
    /**
     * 全品番の売上順位（決定記録 K-032）。順位の高い順で、販売実績の無い品番は最後。
     */
    public function index(ItemRanker $ranker): AnonymousResourceCollection
    {
        $ranking = $ranker->rank(CarbonImmutable::today());

        return ItemRankResource::collection($ranking->ranks)->additional([
            'meta' => [
                'ranking_from' => $ranking->from->toDateString(),
                'ranking_to' => $ranking->to->toDateString(),
                'candidate_rank' => ItemSelection::CANDIDATE_RANK,
                'max_items' => ItemSelection::MAX_ITEMS,
            ],
        ]);
    }
}
