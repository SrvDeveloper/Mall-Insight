<?php

namespace App\Enums;

/**
 * 取込時の指摘の重さ。
 */
enum ImportIssueLevel: string
{
    /** 登録しなかった */
    case Error = 'error';
    /** 登録したが確認が必要 */
    case Warning = 'warning';

    public function label(): string
    {
        return match ($this) {
            self::Error => 'エラー',
            self::Warning => '警告',
        };
    }
}
