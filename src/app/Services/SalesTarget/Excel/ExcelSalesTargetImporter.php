<?php

namespace App\Services\SalesTarget\Excel;

use App\Enums\ChangeSource;
use App\Enums\RatioCategoryCode;
use App\Models\Item;
use App\Models\ItemRatioCategory;
use App\Models\ItemUnitPrice;
use App\Models\MonthlySalesRatio;
use App\Models\RatioCategory;
use App\Models\SalesTarget;
use App\Models\Sku;
use Illuminate\Support\Facades\DB;

/**
 * 現行の販売試算Excelを、販売目標の初期値として取り込む（バックログ B-110、決定記録 K-048・K-049・K-106～K-108）。一度取り込めればよい。
 *
 * - 月別販売比率：2025・2026年の4区分。合計が100%でないため、合計で割って100%にそろえ、1万分率に丸めて端数はいちばん大きい月で合わせる（K-106）。
 *   すでに登録のある区分は取り込まない。
 * - 品番の比率の区分：SKUの月別必要数の数式が参照している区分。すでに登録のある品番は取り込まない。
 * - 年間販売目標：年間販売想定数（H列）のある品番。SKUの年間の数のうち、数式でなく手で入れた値をSKUの上書きとして取り込む（K-107）。
 *   数式の値は、Excelの基準年の構成比による割り振りのため取り込まず、システムの構成比で割り振る。すでにその年度の目標がある品番は取り込まない。
 * - 品番の単価：年間売上想定額÷年間販売想定数（売上金額の概算に使う、K-062）。すでに登録のある品番は取り込まない。
 */
class ExcelSalesTargetImporter
{
    /**
     * @return list<string> 取り込んだ内容と、取り込まなかった理由
     */
    public function import(string $path, int $fiscalYear, ?int $userId, string $fileName): array
    {
        $workbook = new ExcelSalesTargetWorkbook(new XlsxReader($path));
        $ratios = $workbook->ratios();
        $excelItems = $workbook->items();

        return DB::transaction(function () use ($ratios, $excelItems, $fiscalYear, $userId, $fileName): array {
            $report = [];
            foreach ($ratios as $code => $values) {
                $category = RatioCategory::forCode(RatioCategoryCode::from($code));
                if ($category === null) {
                    $report[] = sprintf('月別販売比率（%s）は区分が無いため取り込みませんでした。', RatioCategoryCode::from($code)->label());

                    continue;
                }
                if (MonthlySalesRatio::query()->where('ratio_category_id', $category->id)->exists()) {
                    $report[] = "月別販売比率（{$category->name}）はすでに登録があるため取り込みませんでした。";

                    continue;
                }
                $normalized = self::normalize($values);
                MonthlySalesRatio::create(['ratio_category_id' => $category->id, 'ratios' => $normalized, 'source' => ChangeSource::Excel, 'changed_by_user_id' => $userId]);
                $report[] = sprintf('月別販売比率（%s）を取り込みました（Excelの合計 %.2f%% を100%%にそろえました）。', $category->name, array_sum($values) * 100);
            }

            foreach ($excelItems as $excelItem) {
                if ($excelItem->annual === null || $excelItem->annual <= 0) {
                    continue;
                }
                $item = Item::query()->with('skus')->whereRaw('lower(item_no) = ?', [mb_strtolower($excelItem->itemNo)])->first();
                if ($item === null) {
                    $report[] = "{$excelItem->itemNo}：CrossWalker に品番が無いため取り込みませんでした。";

                    continue;
                }

                $category = $excelItem->category === null ? null : RatioCategory::forCode($excelItem->category);
                if ($category !== null && ! ItemRatioCategory::query()->where('item_no', $item->item_no)->exists()) {
                    ItemRatioCategory::create(['item_no' => $item->item_no, 'ratio_category_id' => $category->id, 'source' => ChangeSource::Excel, 'changed_by_user_id' => $userId]);
                }

                if ($excelItem->unitPrice !== null && ! ItemUnitPrice::query()->where('item_no', $item->item_no)->exists()) {
                    ItemUnitPrice::create(['item_no' => $item->item_no, 'unit_price' => $excelItem->unitPrice, 'source' => ChangeSource::Excel, 'changed_by_user_id' => $userId]);
                }

                if (SalesTarget::query()->forItem($fiscalYear, $item->item_no)->exists()) {
                    $report[] = "{$item->item_no}：{$fiscalYear}年度の目標がすでにあるため取り込みませんでした。";

                    continue;
                }

                $overrides = [];
                $unmatched = [];
                foreach ($excelItem->skus as $excelSku) {
                    $sku = self::findSku($item, $excelSku);
                    if ($sku === null) {
                        $unmatched[] = trim("{$excelSku->color} {$excelSku->power}");

                        continue;
                    }
                    if (! $excelSku->isFormula) {
                        $overrides[$sku->id] = (int) round($excelSku->annual);
                    }
                }

                SalesTarget::create([
                    'fiscal_year' => $fiscalYear,
                    'item_no' => $item->item_no,
                    'annual_quantity' => (int) round($excelItem->annual),
                    'monthly_quantities' => null,
                    'sku_quantities' => $overrides === [] ? null : $overrides,
                    'note' => "現行Excel（{$fileName}）から取り込み",
                    'source' => ChangeSource::Excel,
                    'changed_by_user_id' => $userId,
                ]);
                $report[] = sprintf('%s：年間 %d、SKUの上書き %d件を取り込みました。', $item->item_no, (int) round($excelItem->annual), count($overrides))
                    .($unmatched === [] ? '' : '（CrossWalker に無いSKU：'.implode('、', $unmatched).'）');
            }

            return $report;
        });
    }

    /**
     * 比率を合計で割って100%にそろえ、1万分率の整数に丸める。丸めの端数は、いちばん大きい月で合わせる（K-106）。
     *
     * @param  list<float>  $values
     * @return list<int>
     */
    public static function normalize(array $values): array
    {
        $total = array_sum($values);
        $normalized = array_map(fn (float $value): int => (int) round($value / $total * MonthlySalesRatio::TOTAL), $values);
        $largest = array_keys($normalized, max($normalized), true)[0];
        $normalized[$largest] += MonthlySalesRatio::TOTAL - array_sum($normalized);

        return $normalized;
    }

    /**
     * Excelのカラー・度数に当たるSKU。TQカラーNo・TQサイズで探し、見つからなければSKUコードの末尾（「-1-15」など）で探す。
     */
    private static function findSku(Item $item, ExcelSkuTarget $excelSku): ?Sku
    {
        $size = $excelSku->power === null ? 0 : (int) round(((float) $excelSku->power) * 10);
        $bySizeAndColor = $item->skus->first(fn (Sku $sku): bool => $sku->tq_color_no === $excelSku->color && (int) $sku->tq_size === $size);
        if ($bySizeAndColor !== null) {
            return $bySizeAndColor;
        }
        $suffix = $excelSku->power === null ? "-{$excelSku->color}" : sprintf('-%s-%02d', $excelSku->color, $size);

        return $item->skus->first(fn (Sku $sku): bool => str_ends_with(mb_strtolower($sku->sku_code), mb_strtolower($suffix)));
    }
}
