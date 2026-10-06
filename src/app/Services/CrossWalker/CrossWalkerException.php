<?php

namespace App\Services\CrossWalker;

use RuntimeException;

/**
 * CrossWalker からの取得に失敗したことを表す。メッセージは画面にそのまま表示できる利用者向けの文言とする。
 */
class CrossWalkerException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('CrossWalkerの接続先またはAPIキーが設定されていません。');
    }

    public static function connectionFailed(): self
    {
        return new self('CrossWalkerに接続できませんでした。ネットワークの状態か、CrossWalkerが動いているかを確認してください。');
    }

    public static function fromStatus(int $status): self
    {
        return new self(match (true) {
            $status === 401 => 'CrossWalkerのAPIキーが正しくありません。サイト設定でAPIキーを確認してください。',
            $status === 403 => 'CrossWalkerへの接続が許可されていません。外部APIが有効か、接続元IPアドレスが許可されているかを確認してください。',
            $status === 429 => 'CrossWalkerの利用回数の上限（毎分120回）を超えました。しばらく待ってからもう一度お試しください。',
            $status >= 500 => "CrossWalkerでエラーが発生しました（{$status}）。時間をおいてもう一度お試しください。",
            default => "CrossWalkerから想定していない応答がありました（{$status}）。",
        });
    }

    public static function noItems(): self
    {
        return new self('CrossWalkerから品番が1件も返りませんでした。保存済みの品番は変更していません。CrossWalkerの登録内容を確認してください。');
    }

    public static function invalidResponse(): self
    {
        return new self('CrossWalkerの応答の形式が想定と異なります。API仕様が変わっていないか確認してください。');
    }
}
