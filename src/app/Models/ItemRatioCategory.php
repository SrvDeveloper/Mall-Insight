<?php

namespace App\Models;

use App\Enums\ChangeSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 品番ごとに使う月別販売比率の区分（決定記録 K-049②）。登録していない品番は、CrossWalker のカテゴリーから引く（K-108）。
 */
#[Fillable(['item_no', 'ratio_category_id', 'source', 'changed_by_user_id'])]
class ItemRatioCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
}
