<?php

namespace App\Services;

use RuntimeException;

/**
 * 外部システムからの取得が、別の実行（自動実行や他の利用者の操作）と重なったことを表す。
 */
class SyncAlreadyRunningException extends RuntimeException
{
    public function __construct(string $message = 'CrossWalkerからの取得を実行中です。終わるまでお待ちください。')
    {
        parent::__construct($message);
    }
}
