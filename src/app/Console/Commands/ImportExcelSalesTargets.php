<?php

namespace App\Console\Commands;

use App\Models\ItemSelection;
use App\Services\SalesTarget\Excel\ExcelSalesTargetImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('sales-targets:import-excel {path : 現行の販売試算Excel（xlsx）} {--fiscal-year= : 取り込む年度（4月始まり。省略すると今年度）}')]
#[Description('現行の販売試算Excelから、年間販売想定数・SKUの上書き・品番の比率の区分・月別販売比率を販売目標の初期値として取り込む（一度だけでよい）')]
class ImportExcelSalesTargets extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ExcelSalesTargetImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path)) {
            $this->error("ファイルがありません：{$path}");

            return self::FAILURE;
        }
        $fiscalYear = $this->option('fiscal-year') !== null ? (int) $this->option('fiscal-year') : ItemSelection::fiscalYearStart(today())->year;

        try {
            $report = $importer->import($path, $fiscalYear, null, basename($path));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$fiscalYear}年度の販売目標の初期値を取り込みました。");
        foreach ($report as $line) {
            $this->line("- {$line}");
        }

        return self::SUCCESS;
    }
}
