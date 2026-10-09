<?php

namespace App\Enums;

/**
 * 販売目標・月別販売比率・比率の区分を、どこで登録・変更したか（バックログ B-110）。
 */
enum ChangeSource: string
{
    case Screen = 'screen';
    case Excel = 'excel';
    case CarryOver = 'carry_over';

    public function label(): string
    {
        return match ($this) {
            self::Screen => '画面',
            self::Excel => '現行Excelから取り込み',
            self::CarryOver => '前年度から引き継ぎ',
        };
    }
}
