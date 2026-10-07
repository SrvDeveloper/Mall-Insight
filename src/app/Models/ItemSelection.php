<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 対象品番の選定（確定1回につき1行）。最新の選定が需要予測と在庫推移の対象になり、前の選定は上書きしない（決定記録 K-030）。
 */
#[Fillable(['confirmed_at', 'confirmed_by_user_id', 'ranking_from', 'ranking_to', 'note'])]
class ItemSelection extends Model
{
    /** 確定できる品番数の上限（K-031）。 */
    public const MAX_ITEMS = 100;

    /** 最初に候補として選ばれた状態にする売上順位（K-031）。 */
    public const CANDIDATE_RANK = 20;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'ranking_from' => 'date',
            'ranking_to' => 'date',
        ];
    }

    /**
     * 確定した利用者。ログイン（B-009）より前に確定した選定は null。
     *
     * @return BelongsTo<User, $this>
     */
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }

    /**
     * @return HasMany<ItemSelectionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ItemSelectionItem::class)->orderByRaw('sales_rank is null')->orderBy('sales_rank')->orderBy('item_no');
    }

    /**
     * その日が属する年度の初日（年度は4月始まり、K-030）。
     */
    public static function fiscalYearStart(CarbonInterface $date): CarbonImmutable
    {
        $date = CarbonImmutable::instance($date);
        $year = $date->month >= 4 ? $date->year : $date->year - 1;

        return CarbonImmutable::create($year, 4, 1);
    }

    /**
     * 最新の選定（需要予測と在庫推移の対象）。確定した順にIDが振られるため、IDの大きいものを最新とする。
     */
    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }

    /**
     * 前回の選定からの変更。前回が無い（初回の）ときは null。items と previous を読み込んでから呼ぶ。
     *
     * @return array{added: list<string>, removed: list<string>}|null
     */
    public function changesFromPrevious(): ?array
    {
        $previous = $this->getRelation('previous');
        if ($previous === null) {
            return null;
        }

        $itemNos = $this->items->pluck('item_no');
        $previousItemNos = $previous->items->pluck('item_no');

        return [
            'added' => $itemNos->diff($previousItemNos)->values()->all(),
            'removed' => $previousItemNos->diff($itemNos)->sort()->values()->all(),
        ];
    }
}
