<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 担当者が登録した品番の季節係数（B-106、決定記録 K-113）。1月～12月の12か月分を、普段の月を1として持つ。
 * 変更するたびに新しい行として記録し、品番ごとに最新の行を使う（原則3）。factors が null の行は、登録をやめた記録。
 * 登録したときに初期値として出した、販売実績から求めた係数も残す（原則4）。
 */
#[Fillable(['item_no', 'factors', 'initial_factors', 'initial_source', 'note', 'changed_by_user_id'])]
class ItemSeasonalFactor extends Model
{
    /** 係数の下限・上限 */
    public const MIN_FACTOR = 0.1;

    public const MAX_FACTOR = 5.0;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'factors' => 'array',
            'initial_factors' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * 月（1〜12） => 係数。登録をやめた記録なら null。
     *
     * @return array<int, float>|null
     */
    public function factorsByMonth(): ?array
    {
        if ($this->factors === null) {
            return null;
        }

        return array_combine(range(1, 12), array_map(fn ($factor): float => (float) $factor, array_values($this->factors)));
    }

    /**
     * 品番ごとの最新の記録（登録をやめた記録も含む）。
     *
     * @param  list<string>|null  $itemNos  null ならすべての品番
     * @return array<string, self> 品番 => 最新の記録
     */
    public static function latestByItem(?array $itemNos = null): array
    {
        $latestIds = self::query()
            ->selectRaw('max(id) as id')
            ->when($itemNos !== null, fn ($query) => $query->whereIn('item_no', $itemNos))
            ->groupBy('item_no')
            ->pluck('id');

        return self::query()->with('changedBy')->whereIn('id', $latestIds)->get()->keyBy('item_no')->all();
    }

    /**
     * 需要予測に使う、品番ごとの季節係数（登録をやめた品番は含まない）。
     *
     * @param  list<string>|null  $itemNos
     * @return array<string, array<int, float>> 品番 => 月 => 係数
     */
    public static function currentFactors(?array $itemNos = null): array
    {
        $factors = [];
        foreach (self::latestByItem($itemNos) as $itemNo => $record) {
            $byMonth = $record->factorsByMonth();
            if ($byMonth !== null) {
                $factors[$itemNo] = $byMonth;
            }
        }

        return $factors;
    }
}
