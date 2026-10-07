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
    ) {}

    public function isCandidate(): bool
    {
        return $this->rank !== null && $this->rank <= ItemSelection::CANDIDATE_RANK;
    }
}
