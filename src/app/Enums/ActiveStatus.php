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

    /**
     * SKUの状態の表示名。無効のSKUは廃番のSKUのため「廃番」と示す（K-053）。
     */
    public function skuLabel(): string
    {
        return match ($this) {
            self::Active => '有効',
            self::Inactive => '廃番',
        };
    }
}
