<?php

namespace App\Services\ItemSelection;

use Carbon\CarbonImmutable;

/**
 * 集計期間と、品番ごとの売上順位（順位の高い順。順位の無い品番は品番コード順で最後）。
 */
final readonly class ItemRanking
{
    /**
     * @param  list<ItemRank>  $ranks
     */
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public array $ranks,
    ) {}

    public function find(string $itemNo): ?ItemRank
    {
        foreach ($this->ranks as $rank) {
            if ($rank->item->item_no === $itemNo) {
                return $rank;
            }
        }

        return null;
    }
}
