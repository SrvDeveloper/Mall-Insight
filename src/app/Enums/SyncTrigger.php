<?php

namespace App\Enums;

/**
 * 外部システムからの取得を実行したきっかけ。
 */
enum SyncTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => '自動',
            self::Manual => '手動',
        };
    }
}
