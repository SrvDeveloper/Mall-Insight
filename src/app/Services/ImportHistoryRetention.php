<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/** 販売実績の取込履歴・在庫の取得履歴を、それぞれ登録順で最新20件に保つ（K-086）。 */
class ImportHistoryRetention
{
    public const LIMIT = 20;

    /**
     * @param  int|null  $keptId  20件より古くても残す履歴（在庫取得の最後に成功した記録）
     */
    public function prune(Builder $histories, ?int $keptId = null): void
    {
        $oldestRetainedId = (clone $histories)->orderByDesc('id')->offset(self::LIMIT - 1)->value('id');
        if ($oldestRetainedId !== null) {
            $histories->where('id', '<', $oldestRetainedId)->when($keptId !== null, fn (Builder $query) => $query->whereKeyNot($keptId))->delete();
        }
    }
}
