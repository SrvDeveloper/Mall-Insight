<?php

namespace App\Enums;

/**
 * 販売チャネル（モール）。
 */
enum Mall: string
{
    case Rakuten = 'rakuten';
    case Yahoo = 'yahoo';
    case AuPay = 'au_pay';
    case Amazon = 'amazon';

    public function label(): string
    {
        return match ($this) {
            self::Rakuten => '楽天市場',
            self::Yahoo => 'Yahoo!ショッピング',
            self::AuPay => 'au PAY マーケット',
            self::Amazon => 'Amazon',
        };
    }

    /**
     * そのモールの販売チャネル。楽天市場・Yahoo!ショッピング・au PAY マーケットは BOSS で受注して BOSS の倉庫から出荷する（K-038）。
     */
    public function channel(): Channel
    {
        return $this === self::Amazon ? Channel::Amazon : Channel::Boss;
    }

    /**
     * BOSS受注実績の `ショップID` からモールを判定する。該当しなければ null。
     */
    public static function fromBossShopId(string $shopId): ?self
    {
        return match ($shopId) {
            '67419' => self::Rakuten,
            '67572' => self::Yahoo,
            '67574' => self::AuPay,
            default => null,
        };
    }
}
