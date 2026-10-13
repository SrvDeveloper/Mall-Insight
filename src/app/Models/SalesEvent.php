<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\Mall;
use Database\Factories\SalesEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * イベント（キャンペーン、バックログ B-129・決定記録 K-112・K-114）。担当者が名前・モール・メモを登録し、開催する期間（SalesEventPeriod）を
 * いくつも登録する。期間の日は、そのモールのチャネルの平均日販から除き、今日以降の期間の日は、品番ごとの倍率で需要予測を増やす。
 * 倍率は、同じイベントの過去の期間からシステムが求めた候補を使い、担当者が期間ごとに直せる。
 *
 * @property Collection<int, Mall> $malls
 */
#[Fillable(['name', 'malls', 'note', 'created_by_user_id', 'updated_by_user_id'])]
class SalesEvent extends Model
{
    /** @use HasFactory<SalesEventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'malls' => AsEnumCollection::of(Mall::class),
        ];
    }

    /**
     * 期間（初日の順）。
     *
     * @return HasMany<SalesEventPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(SalesEventPeriod::class)->orderBy('starts_on')->orderBy('id');
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
     * 同じイベントかを比べるための名前。前後の空白を除き、全角の英数字・空白を半角にし、続く空白を1つにして、英字の大文字・小文字を区別しない。
     */
    public static function normalizeName(string $name): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim(mb_convert_kana($name, 'as'))));
    }
}
