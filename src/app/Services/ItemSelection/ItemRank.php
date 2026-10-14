<?php

namespace App\Services\ItemSelection;

use App\Models\Item;
use App\Models\ItemSelection;

/**
 * 品番1件の売上順位。期間内に販売実績が無い品番は、売上金額・販売数・順位が null。
 */
final readonly class ItemRank
{
    public function __construct(
        public Item $item,
        public ?int $salesAmount,
        public ?int $salesQuantity,
        public ?int $rank,
        public int $candidateRank = ItemSelection::CANDIDATE_RANK,
    ) {}

    /**
     * 最初に候補として選ばれた状態にするか（全体は20位、Amazon・BOSS は10位まで、K-031・K-129）。
     */
    public function isCandidate(): bool
    {
        return $this->rank !== null && $this->rank <= $this->candidateRank;
    }
}
