<?php

namespace App\Models;

use App\Enums\ChangeSource;
use App\Enums\Channel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 品番の単価（税込、決定記録 K-063）。単価の画面で登録し、売上金額の概算などに使う。
 * 全体の単価はモールを分けない金額に、Amazon・BOSSの単価はモール別の金額に使う。登録の無い単価は、販売実績の平均単価を使う。
 */
#[Fillable(['item_no', 'unit_price', 'amazon_unit_price', 'boss_unit_price', 'source', 'changed_by_user_id'])]
class ItemUnitPrice extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'unit_price' => 'integer',
            'amazon_unit_price' => 'integer',
            'boss_unit_price' => 'integer',
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
     * モールの単価。
     */
    public function priceFor(Channel $channel): ?int
    {
        return match ($channel) {
            Channel::Amazon => $this->amazon_unit_price,
            Channel::Boss => $this->boss_unit_price,
        };
    }
}
