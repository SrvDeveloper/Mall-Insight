<?php

namespace App\Services\ZeroStockView;

use RuntimeException;

/**
 * ZeroStockView からの取得に失敗したことを表す。メッセージは画面にそのまま表示できる利用者向けの文言とする。
 */
class ZeroStockViewException extends RuntimeException
{
    public static function notConfigured(): self
    {
        return new self('ZeroStockViewの接続先またはAPIキーが設定されていません。');
    }

    public static function connectionFailed(): self
    {
        return new self('ZeroStockViewに接続できませんでした。ネットワークの状態か、ZeroStockViewが動いているかを確認してください。');
    }

    public static function fromStatus(int $status): self
    {
        return new self(match (true) {
            $status === 401 => 'ZeroStockViewのAPIキーが正しくありません。APIキーを確認してください。',
            $status === 403 => 'この接続元からZeroStockViewへの接続は許可されていません。許可するIPアドレスの設定を確認してください。',
            $status === 422 => 'ZeroStockViewに送った取得条件が受け付けられませんでした。API仕様が変わっていないか確認してください。',
            $status === 429 => 'ZeroStockViewの利用回数の上限（毎分60回）を超えました。しばらく待ってからもう一度お試しください。',
            $status === 503 => 'ZeroStockViewの外部APIが無効になっています。ZeroStockView側の設定を確認してください。',
            $status >= 500 => "ZeroStockViewでエラーが発生しました（{$status}）。時間をおいてもう一度お試しください。",
            default => "ZeroStockViewから想定していない応答がありました（{$status}）。",
        });
    }

    public static function invalidResponse(): self
    {
        return new self('ZeroStockViewの応答の形式が想定と異なります。API仕様が変わっていないか確認してください。');
    }
}
