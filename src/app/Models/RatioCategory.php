<?php

namespace App\Models;

use App\Enums\RatioCategoryCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * 月別販売比率の区分（決定記録 K-049②・K-061）。最初は現行Excelの4区分（老眼・PCサングラス・調光老眼・既成近眼）で、画面で足したり名前を変えたりできる。
 * CrossWalker のカテゴリーとは合わない（flh-001ph は CrossWalker では老眼鏡だが、調光老眼の比率を使う）ため、品番ごとに別に持つ。
 */
#[Fillable(['code', 'name', 'position', 'changed_by_user_id'])]
class RatioCategory extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code' => RatioCategoryCode::class,
            'position' => 'integer',
        ];
    }

    /**
     * 並び順の区分。
     *
     * @return Collection<int, self>
     */
    public static function ordered(): Collection
    {
        return self::query()->orderBy('position')->orderBy('id')->get();
    }

    public static function forCode(RatioCategoryCode $code): ?self
    {
        return self::query()->where('code', $code->value)->first();
    }
}
