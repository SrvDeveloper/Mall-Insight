<?php

namespace App\Services\CrossWalker;

use RuntimeException;

/**
 * CrossWalker からの取得が、別の実行（自動実行や他の利用者の操作）と重なったことを表す。
 */
class SyncAlreadyRunningException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('CrossWalkerからの取得を実行中です。終わるまでお待ちください。');
    }
}
