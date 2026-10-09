<?php

namespace App\Enums;

/**
 * 最初からある月別販売比率の4つの区分の印（決定記録 K-049②・K-061）。現行の販売試算Excelの「月別販売比率」シートの区分。
 * 区分の名前は画面で変えられるため、CrossWalker のカテゴリーから初期値を引くとき（K-058）と、Excelを取り込むときは、名前ではなくこの印で区分を探す。
 * 画面で足した区分には印が無い。
 */
enum RatioCategoryCode: string
{
    case Reading = 'reading';
    case PcSunglasses = 'pc_sunglasses';
    case PhotochromicReading = 'photochromic_reading';
    case ReadyMadeMyopia = 'ready_made_myopia';

    /**
     * 最初の名前。
     */
    public function label(): string
    {
        return match ($this) {
            self::Reading => '老眼',
            self::PcSunglasses => 'PCサングラス',
            self::PhotochromicReading => '調光老眼',
            self::ReadyMadeMyopia => '既成近眼',
        };
    }

    /**
     * 区分を登録していない品番の初期値。CrossWalker のカテゴリーから引き、引けなければ null（未設定、K-058）。
     */
    public static function fromCrossWalkerCategory(?string $category): ?self
    {
        return match ($category) {
            '老眼鏡' => self::Reading,
            '調光老眼鏡' => self::PhotochromicReading,
            'サングラス', '調光サングラス' => self::PcSunglasses,
            default => null,
        };
    }
}
