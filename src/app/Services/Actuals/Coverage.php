<?php

namespace App\Services\Actuals;

/**
 * 月の販売実績がそろっているか（バックログ B-124）。データ無しの月は0とせず、途中までの月はそのことを示す（原則2）。
 */
enum Coverage: string
{
    case Full = 'full';
    /** 販売実績を取り込んだ期間が月の途中で始まる・終わる（今月を含む） */
    case Partial = 'partial';
    /** 販売実績を取り込んでいない */
    case None = 'none';
}
