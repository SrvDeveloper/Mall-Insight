<?php

namespace App\Models;

use App\Enums\ChangeSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 年間販売目標（バックログ B-110、決定記録 K-048・K-049）。年度・品番ごとに、変更するたびに新しい行として記録し、最新の行を使う（原則3）。
 * 1行に、品番の年間の数・手で直した月間の数・手で上書きしたSKUの年間の数をまとめて持つ。
 * 年間販売目標はシステム需要予測の計算に使わない（原則1）。
 */
#[Fillable(['fiscal_year', 'item_no', 'annual_quantity', 'monthly_quantities', 'sku_quantities', 'note', 'source', 'changed_by_user_id'])]
class SalesTarget extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fiscal_year' => 'integer',
            'annual_quantity' => 'integer',
            'monthly_quantities' => 'array',
            'sku_quantities' => 'array',
            'source' => ChangeSource::class,
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
     * その年度の品番ごとの最新の目標。
     *
     * @return Collection<string, self> [品番 => 目標]
     */
    public static function currentFor(int $fiscalYear): Collection
    {
        $latestIds = self::query()->selectRaw('max(id) as id')->where('fiscal_year', $fiscalYear)->groupBy('item_no')->pluck('id');

        return self::query()->with('changedBy')->whereIn('id', $latestIds)->get()->keyBy('item_no');
    }

    /**
     * 手で直した月間の数（4月～翌3月）。直していない月は null。
     *
     * @return list<int|null>
     */
    public function monthlyOverrides(): array
    {
        $values = $this->monthly_quantities ?? [];

        return array_map(fn (int $index): ?int => isset($values[$index]) ? (int) $values[$index] : null, range(0, 11));
    }

    /**
     * 手で上書きしたSKUの年間の数。
     *
     * @return array<int, int> [SKU ID => 数量]
     */
    public function skuOverrides(): array
    {
        $values = [];
        foreach ($this->sku_quantities ?? [] as $skuId => $quantity) {
            $values[(int) $skuId] = (int) $quantity;
        }

        return $values;
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeForItem(Builder $query, int $fiscalYear, string $itemNo): void
    {
        $query->where('fiscal_year', $fiscalYear)->where('item_no', $itemNo);
    }
}
