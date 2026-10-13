<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\SalesEventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SalesEventPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * イベントを開催する期間（B-129、決定記録 K-114）。1つのイベントに期間をいくつも登録する。モールはイベントのものを使う。
 * 倍率の候補は同じイベントの過去の期間から求め、担当者が入れた倍率（multipliers）は期間ごとに持つ。
 *
 * @property-read string $name イベントの名前
 */
#[Fillable(['sales_event_id', 'starts_on', 'ends_on', 'note', 'created_by_user_id', 'updated_by_user_id'])]
class SalesEventPeriod extends Model
{
    /** @use HasFactory<SalesEventPeriodFactory> */
    use HasFactory;

    /** 1つの期間の最大の日数。これより長い売れ方の変化は季節性（B-106）で扱う */
    public const MAX_DAYS = 31;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<SalesEvent, $this>
     */
    public function salesEvent(): BelongsTo
    {
        return $this->belongsTo(SalesEvent::class);
    }

    /**
     * @return HasMany<SalesEventMultiplier, $this>
     */
    public function multipliers(): HasMany
    {
        return $this->hasMany(SalesEventMultiplier::class);
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
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * イベントの名前。
     *
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => (string) $this->salesEvent?->name);
    }

    /**
     * イベントのモールのチャネル。
     *
     * @return list<Channel>
     */
    public function channels(): array
    {
        return $this->salesEvent?->channels() ?? [];
    }

    public function isOn(Channel $channel): bool
    {
        return in_array($channel, $this->channels(), true);
    }

    /**
     * 同じイベントの期間かを比べるための値（倍率の候補は、同じイベントの過去の期間から求める）。
     */
    public function eventKey(): string
    {
        return 'event:'.$this->sales_event_id;
    }

    /**
     * 初日から最終日までの日付（Y-m-d）。
     *
     * @return list<string>
     */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->starts_on; $day->lte($this->ends_on); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    public function days(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /**
     * $day より前に終わったか。終わった期間は倍率を掛けず、平均日販から除くのと、倍率の候補を求めるのに使う。
     */
    public function hasEndedBefore(CarbonImmutable $day): bool
    {
        return $this->ends_on->lt($day);
    }

    public function status(CarbonImmutable $today): SalesEventStatus
    {
        return match (true) {
            $this->hasEndedBefore($today) => SalesEventStatus::Ended,
            $this->starts_on->gt($today) => SalesEventStatus::Upcoming,
            default => SalesEventStatus::Ongoing,
        };
    }
}
