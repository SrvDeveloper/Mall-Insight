<?php

namespace App\Models;

use App\Enums\ChangeSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 月別販売比率（決定記録 K-049②）。区分ごとに4月～翌3月の12か月分を1万分率で持つ。
 * 変更するたびに新しい行として記録し、区分ごとに最新の行を使う。合計は保存するときに100%（10000）にそろえる（K-106）。
 */
#[Fillable(['ratio_category_id', 'ratios', 'source', 'changed_by_user_id'])]
class MonthlySalesRatio extends Model
{
    /** 比率の合計（100%）。 */
    public const TOTAL = 10000;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ratios' => 'array',
            'source' => ChangeSource::class,
        ];
    }

    /**
     * @return BelongsTo<RatioCategory, $this>
     */
    public function ratioCategory(): BelongsTo
    {
        return $this->belongsTo(RatioCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * 区分ごとの最新の比率。登録の無い区分は含まない。
     *
     * @return array<int, self> [区分のID => 比率]
     */
    public static function currentByCategory(): array
    {
        $latestIds = self::query()->selectRaw('max(id) as id')->groupBy('ratio_category_id')->pluck('id');

        return self::query()->with('changedBy')->whereIn('id', $latestIds)->get()->keyBy('ratio_category_id')->all();
    }
}
