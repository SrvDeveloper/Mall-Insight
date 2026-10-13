<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Mall;
use App\Enums\SalesEventStatus;
use Carbon\CarbonImmutable;
use Database\Factories\SalesEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * イベント（キャンペーン、バックログ B-129・決定記録 K-112）。担当者が名前・期間・モールを登録する。
 * イベントの日は、そのモールのチャネルの平均日販から除き、今日以降のイベントの日は、品番ごとの倍率で需要予測を増やす。
 * 倍率は、同じ名前の過去のイベントからシステムが求めた候補を使い、担当者が入れた品番は multipliers の値を使う。
 *
 * @property Collection<int, Mall> $malls
 */
#[Fillable(['name', 'starts_on', 'ends_on', 'malls', 'note', 'created_by_user_id', 'updated_by_user_id'])]
class SalesEvent extends Model
{
    /** @use HasFactory<SalesEventFactory> */
    use HasFactory;

    /** 1つのイベントの最大の日数。これより長い売れ方の変化は季節性（B-106）で扱う */
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
            'malls' => AsEnumCollection::of(Mall::class),
        ];
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
     * イベントを行うモールのチャネル（楽天市場・Yahoo!ショッピング・au PAY マーケットは BOSS、K-038）。
     *
     * @return list<Channel>
     */
    public function channels(): array
    {
        return array_values(array_filter(Channel::cases(), fn (Channel $channel): bool => $this->malls->contains(fn (Mall $mall): bool => $mall->channel() === $channel)));
    }

    public function isOn(Channel $channel): bool
    {
        return in_array($channel, $this->channels(), true);
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
     * 今日より前に終わったか。終わったイベントは倍率を掛けず、平均日販から除くのと、倍率の候補を求めるのに使う。
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

    /**
     * 同じイベントかを比べるための名前。前後の空白を除き、全角の英数字・空白を半角にし、続く空白を1つにして、英字の大文字・小文字を区別しない。
     */
    public function nameKey(): string
    {
        return self::normalizeName($this->name);
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim(mb_convert_kana($name, 'as'))));
    }
}
