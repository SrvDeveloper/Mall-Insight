<?php

namespace App\Services\SalesImport;

use RuntimeException;

/**
 * ファイル全体を取り込めないことを表す（文字コード違い、必要な列が無いなど）。メッセージは利用者向けの文言とする。
 */
class SalesImportFileException extends RuntimeException {}
