<?php

namespace App\Models;

use App\Services\InventoryTrend\TrendScope;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 対象品番の選定（確定1回につき1行）。最新の選定が需要予測と在庫推移の対象になり、前の選定は上書きしない（決定記録 K-030）。
 * 対象品番は Amazon・BOSS（scope）ごとに選んで確定する（B-136、K-129・K-130）。全体の対象品番は2つを合わせたもので、確定しない。
 * scope が total の選定は、全体の対象品番を確定していたころ（2026年10月14日まで）の記録で、今は使わない。
 *
 * @property TrendScope $scope
 */
#[Fillable(['scope', 'confirmed_at', 'confirmed_by_user_id', 'ranking_from', 'ranking_to', 'note'])]
class ItemSelection extends Model
{
    /** 確定できる品番数の上限（K-031。全体・Amazon・BOSS のそれぞれ）。 */
    public const MAX_ITEMS = 100;

    /** 全体の売上順位で、候補とする順位（K-031。全体の売上順位を見るときの目安）。 */
    public const CANDIDATE_RANK = 20;

    /** Amazon・BOSS の対象品番で、最初に候補として選ばれた状態にする売上順位。未確定の間はこの品番を対象品番として使う（K-129・K-130）。 */
    public const CHANNEL_CANDIDATE_RANK = 10;

    /**
     * 最初に候補として選ばれた状態にする売上順位。
     */
    public static function candidateRank(TrendScope $scope): int
    {
        return $scope === TrendScope::Total ? self::CANDIDATE_RANK : self::CHANNEL_CANDIDATE_RANK;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'scope' => TrendScope::class,
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
     * その対象（Amazon・BOSS）の最新の選定。確定した順にIDが振られるため、IDの大きいものを最新とする。
     * 画面に出す対象品番は TargetItems を使う（未確定のときは売上上位の品番を使い、全体は2つを合わせるため）。
     */
    public static function current(TrendScope $scope = TrendScope::Total): ?self
    {
        return self::query()->where('scope', $scope->value)->latest('id')->first();
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
