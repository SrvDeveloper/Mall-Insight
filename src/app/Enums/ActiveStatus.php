<?php

namespace App\Enums;

/**
 * CrossWalker の品番・SKU の有効状態。
 */
enum ActiveStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';

    public function label(): string
    {
        return match ($this) {
            self::Active => '有効',
            self::Inactive => '無効',
        };
    }
}
