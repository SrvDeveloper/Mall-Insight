<?php

namespace App\Models;

use App\Enums\Channel;
use Carbon\CarbonImmutable;
use Database\Factories\ForecastAdjustmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 手動での予測補正（B-109、決定記録 K-132）。品番・チャネルごとに、始める月から終える月まで、システムの予測に倍率（%）を掛ける。
 * 品番のSKUすべてに同じ倍率を掛ける。終える月が null なら取り消すまで続ける。取り消しても行は消さず、取り消した日時と利用者を残す（原則3）。
 * 同じ品番・チャネルで、取り消していない補正の月を重ねない。
 */
#[Fillable(['item_no', 'channel', 'starts_on', 'ends_on', 'rate_percent', 'reason', 'created_by_user_id', 'cancelled_at', 'cancelled_by_user_id'])]
class ForecastAdjustment extends Model
{
    /** @use HasFactory<ForecastAdjustmentFactory> */
    use HasFactory;

    /** 倍率（%）の下限・上限 */
    public const MIN_PERCENT = 0;

    public const MAX_PERCENT = 500;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'rate_percent' => 'integer',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    /**
     * 取り消していない補正。
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    /**
     * 予測に掛ける倍率（1.2 など）。
     */
    public function rate(): float
    {
        return $this->rate_percent / 100;
    }

    /**
     * その月（月の1日）に補正を掛けるか。
     */
    public function covers(CarbonImmutable $month): bool
    {
        return $this->cancelled_at === null && $this->starts_on->lte($month) && ($this->ends_on === null || $this->ends_on->gte($month));
    }

    /**
     * 補正の状態。取り消した（cancelled）・終えた月が過ぎた（ended）・始める月がまだ（scheduled）・掛けている（active）。
     */
    public function status(CarbonImmutable $today): string
    {
        $month = $today->startOfMonth();

        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->ends_on !== null && $this->ends_on->lt($month) => 'ended',
            $this->starts_on->gt($month) => 'scheduled',
            default => 'active',
        };
    }

    /**
     * 需要予測に使う、品番・チャネルごとの取り消していない補正（今月より前に終えた補正は含まない）。
     *
     * @param  list<string>  $itemNos
     * @return array<string, array<string, list<self>>> 品番 => チャネルの値 => 補正（始める月の順）
     */
    public static function currentFor(array $itemNos, CarbonImmutable $today): array
    {
        if ($itemNos === []) {
            return [];
        }
        $adjustments = [];
        $records = self::query()
            ->active()
            ->whereIn('item_no', $itemNos)
            ->where(fn (Builder $query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today->startOfMonth()->toDateString()))
            ->orderBy('starts_on')
            ->get();
        foreach ($records as $record) {
            $adjustments[$record->item_no][$record->channel->value][] = $record;
        }

        return $adjustments;
    }
}
