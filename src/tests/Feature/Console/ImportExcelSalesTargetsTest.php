<?php

namespace Tests\Feature\Console;

use App\Enums\RatioCategoryCode;
use App\Models\Item;
use App\Models\ItemRatioCategory;
use App\Models\ItemUnitPrice;
use App\Models\MonthlySalesRatio;
use App\Models\RatioCategory;
use App\Models\SalesTarget;
use App\Models\Sku;
use App\Services\SalesTarget\Excel\ExcelSalesTargetImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 現行の販売試算Excel（docs/参考/在庫確保販売試算_検証用.xlsx）を、販売目標の初期値として取り込む（B-110）。
 */
class ImportExcelSalesTargetsTest extends TestCase
{
    use RefreshDatabase;

    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-08 09:00:00');
        $this->path = base_path('../docs/参考/在庫確保販売試算_検証用.xlsx');
        // Excel のカラー・度数に合わせた SKU を持つ品番
        Item::factory()->withSkus(['1', '2', '3'], ['5', '10', '15', '20', '25'])->create(['item_no' => 'fll-3004', 'category' => '老眼鏡']);
        $ph = Item::factory()->create(['item_no' => 'ph5099', 'category' => '調光サングラス']);
        foreach (['1', '2', '12'] as $position => $color) {
            Sku::factory()->for($ph)->create(['sku_code' => "ph5099-{$color}", 'tq_color_no' => $color, 'tq_size' => '0', 'position' => $position]);
        }
    }

    public function test_imports_the_ratios_categories_annual_targets_and_hand_entered_sku_values(): void
    {
        $this->artisan('sales-targets:import-excel', ['path' => $this->path])
            ->expectsOutput('2026年度の販売目標の初期値を取り込みました。')
            ->expectsOutputToContain('月別販売比率（老眼）を取り込みました（Excelの合計 100.46% を100%にそろえました）。')
            ->expectsOutputToContain('fll-3004：年間 1700、SKUの上書き 13件を取り込みました。')
            ->expectsOutputToContain('flc-002：CrossWalker に品番が無いため取り込みませんでした。')
            ->assertSuccessful();

        // 比率は合計で割って 100% にそろえ、1万分率に丸める（K-106）
        $reading = MonthlySalesRatio::query()->where('ratio_category_id', RatioCategory::forCode(RatioCategoryCode::Reading)->id)->firstOrFail();
        $this->assertSame([705, 697, 1006, 796, 758, 1196, 995, 896, 995, 606, 554, 796], $reading->ratios);
        $this->assertSame(4, MonthlySalesRatio::count());

        // 区分は、今の月の必要数の数式が参照している比率（K-049②）
        $this->assertSame(['fll-3004' => '老眼', 'ph5099' => 'PCサングラス'], ItemRatioCategory::query()->with('ratioCategory')->orderBy('item_no')->get()->mapWithKeys(fn ($row) => [$row->item_no => $row->ratioCategory->name])->all());

        // 手で入れた SKU の値だけを上書きとして取り込む（数式の値は取り込まない、K-107）
        $target = SalesTarget::query()->where('item_no', 'fll-3004')->firstOrFail();
        $this->assertSame(2026, $target->fiscal_year);
        $this->assertSame(1700, $target->annual_quantity);
        $overrides = $target->skuOverrides();
        $this->assertCount(13, $overrides);
        $this->assertSame(230, $overrides[Sku::query()->where('sku_code', 'fll-3004-1-5')->value('id')]);
        $this->assertArrayNotHasKey(Sku::query()->where('sku_code', 'fll-3004-2-15')->value('id'), $overrides);

        // 単価は SKU の行の年間売上想定額÷年間販売想定数（K-062）
        $this->assertSame(['fll-3004' => 3080, 'ph5099' => 3300], ItemUnitPrice::query()->orderBy('item_no')->pluck('unit_price', 'item_no')->all());

        // 度数の無い品番は、カラーだけで SKU に当てる
        $this->assertSame([250, 330], array_values(SalesTarget::query()->where('item_no', 'ph5099')->firstOrFail()->skuOverrides()));
    }

    public function test_does_not_import_twice(): void
    {
        $this->artisan('sales-targets:import-excel', ['path' => $this->path, '--fiscal-year' => '2026'])->assertSuccessful();

        $this->artisan('sales-targets:import-excel', ['path' => $this->path, '--fiscal-year' => '2026'])
            ->expectsOutputToContain('月別販売比率（老眼）はすでに登録があるため取り込みませんでした。')
            ->expectsOutputToContain('fll-3004：2026年度の目標がすでにあるため取り込みませんでした。')
            ->assertSuccessful();

        $this->assertSame(2, SalesTarget::count());
        $this->assertSame(4, MonthlySalesRatio::count());
    }

    public function test_fails_for_a_missing_file(): void
    {
        $this->artisan('sales-targets:import-excel', ['path' => '/nowhere.xlsx'])->expectsOutput('ファイルがありません：/nowhere.xlsx')->assertFailed();
    }

    public function test_normalizes_ratios_to_one_hundred_percent_and_puts_the_rounding_into_the_largest_month(): void
    {
        $normalized = ExcelSalesTargetImporter::normalize([0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.05, 0.03, 0.03]);

        // 合計 101% を 100% にそろえると 9.90%・4.95%・2.97%。丸めると合計 99.99% になるため、いちばん大きい月（先頭）に 0.01% を足す
        $this->assertSame([991, 990, 990, 990, 990, 990, 990, 990, 990, 495, 297, 297], $normalized);
        $this->assertSame(10000, array_sum($normalized));
    }
}
