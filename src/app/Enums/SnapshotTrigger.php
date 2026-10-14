<?php

namespace App\Enums;

/**
 * 需要予測の版（B-108、K-136）を保存したきっかけ。
 */
enum SnapshotTrigger: string
{
    case Schedule = 'schedule';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Schedule => '毎月1日の自動保存',
            self::Manual => '画面から保存',
        };
    }
}
