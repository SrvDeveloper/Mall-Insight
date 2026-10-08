<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 在庫推移の判定の基準（変更1回につき1行）。最新の行を使い、前の基準は上書きしない（決定記録 K-050）。
 * 一度も変更していなければ初期値（6か月後、K-027）を使う。
 */
#[Fillable(['check_month_offset', 'changed_by_user_id'])]
class InventoryTrendSetting extends Model
{
    /** 判定する月の初期値（K-027）。 */
    public const DEFAULT_CHECK_MONTH_OFFSET = 6;

    /** 選べる判定する月の範囲（K-050）。推移は今月から11か月後までの12か月。 */
    public const MIN_CHECK_MONTH_OFFSET = 1;

    public const MAX_CHECK_MONTH_OFFSET = 11;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_month_offset' => 'integer',
        ];
    }

    /**
     * 変更した利用者。ログイン（B-009）より前の変更は null。
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    /**
     * 最新の基準。変更した順にIDが振られるため、IDの大きいものを最新とする。一度も変更していなければ null。
     */
    public static function current(): ?self
    {
        return self::query()->latest('id')->first();
    }
}
