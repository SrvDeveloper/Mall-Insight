<?php

namespace App\Enums;

/**
 * 外部システムからの取得結果。
 */
enum SyncStatus: string
{
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Succeeded => '成功',
            self::Failed => '失敗',
        };
    }
}
