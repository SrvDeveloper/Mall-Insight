<?php

namespace Tests\Feature\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Enums\RatioCategoryCode;
use App\Models\Item;
use App\Models\ItemRatioCategory;
use App\Models\ItemSelection;
use App\Models\MonthlySalesRatio;
use App\Models\RatioCategory;
use App\Models\SalesLine;
use App\Models\SalesTarget;
use App\Models\Sku;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesTargetControllerTest extends TestCase
{
    use RefreshDatabase;

    /** 老眼の比率（4月～翌3月）。4月10%・5月20%、残りの10か月は7% */
    private const READING = [1000, 2000, 700, 700, 700, 700, 700, 700, 700, 700, 700, 700];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // ログインしていないとAPIを呼べない（B-009）
        $this->user = User::factory()->create(['name' => '目標 担当']);
        $this->actingAs($this->user);

        // 今日は 2026-10-08。今年度は 2026年度（2026年4月～2027年3月）、構成比の期間は 2025-10-09～2026-10-08
        $this->travelTo('2026-10-08 09:00:00');
        MonthlySalesRatio::create(['ratio_category_id' => $this->categoryId(RatioCategoryCode::Reading), 'ratios' => self::READING, 'source' => ChangeSource::Excel]);
    }

    /**
     * 最初からある区分（マイグレーションで作る4区分）のID。
     */
    private function categoryId(RatioCategoryCode $code): int
    {
        return RatioCategory::forCode($code)->id;
    }

    /**
     * @param  array<string, int>  $sales  SKUコード => 直近12か月の販売数
     * @param  int  $unitPrice  販売実績の1個あたりの金額（税込）
     */
    private function item(string $itemNo, array $sales, string $category = '老眼鏡', int $unitPrice = 1000): Item
    {
        $item = Item::factory()->create(['item_no' => $itemNo, 'category' => $category]);
        foreach (array_keys($sales) as $position => $code) {
            $sku = Sku::factory()->for($item)->create(['sku_code' => $code, 'position' => $position]);
            if ($sales[$code] > 0) {
                SalesLine::create(['source' => 'boss', 'source_order_id' => "{$code}-1", 'sales_date' => '2026-09-01', 'mall' => 'rakuten', 'sku_id' => $sku->id, 'warehouse' => 'boss_own', 'quantity' => $sales[$code], 'amount' => $sales[$code] * $unitPrice]);
            }
        }

        return $item;
    }

    /**
     * @param  list<string>  $itemNos
     */
    private function select(array $itemNos): void
    {
        $selection = ItemSelection::create(['confirmed_at' => now(), 'ranking_from' => '2025-10-09', 'ranking_to' => '2026-10-08']);
        foreach ($itemNos as $rank => $itemNo) {
            $selection->items()->create(['item_no' => $itemNo, 'sales_rank' => $rank + 1, 'is_candidate' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function target(string $itemNo, int $annual, array $attributes = []): SalesTarget
    {
        return SalesTarget::create(['fiscal_year' => 2026, 'item_no' => $itemNo, 'annual_quantity' => $annual, 'source' => ChangeSource::Screen, ...$attributes]);
    }

    public function test_splits_the_annual_target_into_months_by_the_ratio_and_into_skus_by_recent_sales(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30, 'fl-01-1-15' => 10]);
        $this->select(['fl-01']);
        $this->target('fl-01', 1200);

        $this->getJson('/api/v1/sales-targets')
            ->assertOk()
            ->assertJsonPath('meta.fiscal_year', 2026)
            ->assertJsonPath('meta.current_fiscal_year', 2026)
            ->assertJsonPath('meta.months.0', '2026-04')
            ->assertJsonPath('meta.months.11', '2027-03')
            ->assertJsonPath('meta.composition_from', '2025-10-09')
            ->assertJsonPath('meta.ratio_categories.0.ratio.ratios', self::READING)
            ->assertJsonPath('meta.ratio_categories.1.ratio', null)
            ->assertJsonPath('data.0.status', 'calculated')
            ->assertJsonPath('data.0.ratio_category_id', $this->categoryId(RatioCategoryCode::Reading))
            ->assertJsonPath('data.0.ratio_category_name', '老眼')
            ->assertJsonPath('data.0.is_ratio_category_set', false)
            // 品番：4月 1200×10% = 120、5月 1200×20% = 240
            ->assertJsonPath('data.0.monthly.0', 120)
            ->assertJsonPath('data.0.monthly.1', 240)
            ->assertJsonPath('data.0.sku_total', 1200)
            // SKU：販売数 30:10 で 900・300、4月は 120×900/1200 = 90
            ->assertJsonPath('data.0.skus.*.annual', [900, 300])
            ->assertJsonPath('data.0.skus.0.monthly.0', 90)
            ->assertJsonPath('data.0.skus.1.monthly.1', 60);
    }

    public function test_uses_overridden_skus_and_months_and_lets_the_sku_total_exceed_the_item_target(): void
    {
        $item = $this->item('fl-01', ['fl-01-1-10' => 30, 'fl-01-1-15' => 10]);
        $this->select(['fl-01']);
        $first = $item->skus()->orderBy('position')->first();
        $this->target('fl-01', 1200, ['sku_quantities' => [$first->id => 1000], 'monthly_quantities' => [150, null, null, null, null, null, null, null, null, null, null, null]]);

        $this->getJson('/api/v1/sales-targets')
            ->assertJsonPath('data.0.month_overridden.0', true)
            ->assertJsonPath('data.0.month_overridden.1', false)
            ->assertJsonPath('data.0.monthly.0', 150)
            // 上書きした SKU は 1000、していない SKU は構成比の 300。合計 1300 は品番の 1200 を超えてよい（K-107）
            ->assertJsonPath('data.0.skus.*.override_annual', [1000, null])
            ->assertJsonPath('data.0.skus.*.default_annual', [900, 300])
            ->assertJsonPath('data.0.skus.*.annual', [1000, 300])
            ->assertJsonPath('data.0.sku_total', 1300)
            // 4月は品番の 150 を 1000/1200 で割り振る
            ->assertJsonPath('data.0.skus.0.monthly.0', 125);
    }

    public function test_explains_why_a_target_cannot_be_split_instead_of_showing_zero(): void
    {
        $this->item('no-target', ['no-target-1' => 5]);
        $this->item('instant', ['instant-1' => 5], 'インスタントグラス');
        $this->item('sunglass', ['sunglass-1' => 5], 'サングラス');
        $this->item('unsold', ['unsold-1' => 0, 'unsold-2' => 0]);
        $this->select(['no-target', 'instant', 'sunglass', 'unsold']);
        $this->target('instant', 100);
        $this->target('sunglass', 100);
        $this->target('unsold', 100);

        $this->getJson('/api/v1/sales-targets')
            ->assertJsonPath('data.*.status', ['no_target', 'no_category', 'no_ratio', 'no_composition'])
            ->assertJsonPath('data.1.ratio_category_id', null)
            ->assertJsonPath('data.2.ratio_category_name', 'PCサングラス')
            ->assertJsonPath('data.2.monthly', null)
            ->assertJsonPath('data.3.skus.*.annual', [null, null])
            ->assertJsonPath('data.3.sku_total', null)
            ->assertJsonPath('data.3.status_label', '直近12か月の販売実績が無く、SKUへの構成比を出せません。SKUごとの数を入力してください');
    }

    public function test_uses_the_category_set_for_the_item_leaves_out_discontinued_skus_and_lists_other_items_with_targets(): void
    {
        $item = $this->item('fl-01', ['fl-01-1-10' => 10]);
        Sku::factory()->for($item)->inactive()->create(['sku_code' => 'fl-01-9-10', 'position' => 9]);
        $this->item('zz-other', ['zz-other-1' => 10]);
        $this->select(['fl-01']);
        $this->target('fl-01', 100);
        $this->target('zz-other', 50);
        ItemRatioCategory::create(['item_no' => 'fl-01', 'ratio_category_id' => $this->categoryId(RatioCategoryCode::PhotochromicReading), 'source' => ChangeSource::Screen]);

        $this->getJson('/api/v1/sales-targets')
            ->assertJsonPath('data.*.item_no', ['fl-01', 'zz-other'])
            ->assertJsonPath('data.*.is_target_item', [true, false])
            ->assertJsonPath('data.0.ratio_category_name', '調光老眼')
            ->assertJsonPath('data.0.is_ratio_category_set', true)
            ->assertJsonPath('data.0.status', 'no_ratio')
            ->assertJsonPath('data.0.skus.*.sku_code', ['fl-01-1-10']);
    }

    public function test_records_each_change_as_a_new_version_and_lists_the_history_newest_first(): void
    {
        $item = $this->item('fl-01', ['fl-01-1-10' => 30, 'fl-01-1-15' => 10]);
        $sku = $item->skus()->orderBy('position')->first();
        $this->target('fl-01', 1200, ['source' => ChangeSource::Excel, 'note' => '現行Excelから取り込み']);

        $this->postJson('/api/v1/sales-targets', [
            'fiscal_year' => 2026,
            'item_no' => 'fl-01',
            'annual_quantity' => 1500,
            'monthly_quantities' => [null, 300, null, null, null, null, null, null, null, null, null, null],
            'sku_quantities' => [$sku->id => 800],
            'note' => '新色を追加',
        ])->assertCreated()
            ->assertJsonPath('data.annual_quantity', 1500)
            ->assertJsonPath('data.monthly_quantities.1', 300)
            ->assertJsonPath("data.sku_quantities.{$sku->id}", 800)
            ->assertJsonPath('data.changed_by', '目標 担当')
            ->assertJsonPath('data.source_label', '画面');

        $this->getJson('/api/v1/sales-targets/history?fiscal_year=2026&item_no=fl-01')
            ->assertOk()
            ->assertJsonPath('data.*.annual_quantity', [1500, 1200])
            ->assertJsonPath('data.1.source_label', '現行Excelから取り込み');
        $this->assertSame(2, SalesTarget::count());
    }

    public function test_stores_blank_months_and_skus_as_not_overridden(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30]);

        $this->postJson('/api/v1/sales-targets', ['fiscal_year' => 2026, 'item_no' => 'fl-01', 'annual_quantity' => 100, 'monthly_quantities' => array_fill(0, 12, null), 'sku_quantities' => []])
            ->assertCreated();

        $target = SalesTarget::firstOrFail();
        $this->assertNull($target->monthly_quantities);
        $this->assertNull($target->sku_quantities);
    }

    public function test_rejects_a_missing_target_wrong_months_and_skus_of_another_item(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30]);
        $other = $this->item('fl-02', ['fl-02-1-10' => 30]);

        $this->postJson('/api/v1/sales-targets', ['fiscal_year' => 2026, 'item_no' => 'fl-01', 'annual_quantity' => 0, 'monthly_quantities' => [1, 2]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['annual_quantity', 'monthly_quantities']);
        $this->postJson('/api/v1/sales-targets', ['fiscal_year' => 2026, 'item_no' => 'fl-01', 'annual_quantity' => 10, 'sku_quantities' => [$other->skus()->first()->id => 5]])
            ->assertUnprocessable()
            ->assertJsonPath('errors.sku_quantities.0', 'SKUの数は、この品番のSKUにだけ入れられます。');
        $this->postJson('/api/v1/sales-targets', ['fiscal_year' => 2026, 'item_no' => 'unknown', 'annual_quantity' => 10])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_no');
    }

    public function test_changes_the_ratio_of_a_category_only_when_it_totals_one_hundred_percent(): void
    {
        $reading = $this->categoryId(RatioCategoryCode::Reading);
        $this->postJson('/api/v1/monthly-sales-ratios', ['ratio_category_id' => $reading, 'ratios' => array_fill(0, 12, 830)])
            ->assertUnprocessable()
            ->assertJsonPath('errors.ratios.0', '12か月の合計を100%にしてください（今の合計は99.60%です）。');

        $ratios = [870, 830, 830, 830, 830, 830, 830, 830, 830, 830, 830, 830];
        $this->postJson('/api/v1/monthly-sales-ratios', ['ratio_category_id' => $reading, 'ratios' => $ratios])
            ->assertCreated()
            ->assertJsonPath('data.ratios', $ratios)
            ->assertJsonPath('data.changed_by', '目標 担当');

        $this->getJson('/api/v1/sales-targets')->assertJsonPath('meta.ratio_categories.0.ratio.ratios', $ratios);
        $this->assertSame(2, MonthlySalesRatio::count());
    }

    public function test_changes_the_ratio_category_of_an_item(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30]);

        $sunglasses = $this->categoryId(RatioCategoryCode::PcSunglasses);
        $this->putJson('/api/v1/item-ratio-categories/fl-01', ['ratio_category_id' => $sunglasses])
            ->assertOk()
            ->assertJsonPath('data.ratio_category_name', 'PCサングラス');
        $this->putJson('/api/v1/item-ratio-categories/fl-01', ['ratio_category_id' => 9999])->assertUnprocessable();
        $this->putJson('/api/v1/item-ratio-categories/missing', ['ratio_category_id' => $sunglasses])->assertNotFound();

        $this->assertSame($sunglasses, ItemRatioCategory::firstOrFail()->ratio_category_id);
    }

    public function test_adds_a_category_with_its_own_ratios_and_uses_it_for_an_item(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30]);
        $this->select(['fl-01']);
        $this->target('fl-01', 1200);

        $id = $this->postJson('/api/v1/ratio-categories', ['name' => ' ブルーライトカット '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'ブルーライトカット')
            ->assertJsonPath('data.code', null)
            ->json('data.id');
        $this->postJson('/api/v1/monthly-sales-ratios', ['ratio_category_id' => $id, 'ratios' => [...array_fill(0, 11, 800), 1200]])->assertCreated();
        $this->putJson('/api/v1/item-ratio-categories/fl-01', ['ratio_category_id' => $id])->assertOk();

        $this->getJson('/api/v1/sales-targets')
            ->assertJsonPath('meta.ratio_categories.*.name', ['老眼', 'PCサングラス', '調光老眼', '既成近眼', 'ブルーライトカット'])
            ->assertJsonPath('meta.ratio_categories.4.ratio.ratios.11', 1200)
            ->assertJsonPath('data.0.ratio_category_name', 'ブルーライトカット')
            // 3月は 1200×12% = 144
            ->assertJsonPath('data.0.monthly.11', 144);
    }

    public function test_renames_a_category_without_changing_how_initial_categories_are_chosen(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30]);
        $this->select(['fl-01']);
        $reading = $this->categoryId(RatioCategoryCode::Reading);

        $this->patchJson("/api/v1/ratio-categories/{$reading}", ['name' => 'リーディンググラス'])
            ->assertOk()
            ->assertJsonPath('data.code', 'reading');
        $this->patchJson("/api/v1/ratio-categories/{$reading}", ['name' => 'PCサングラス'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/ratio-categories', ['name' => ''])->assertUnprocessable();

        // 老眼鏡の品番は、名前を変えても同じ区分（印が reading の区分）を初期値にする
        $this->getJson('/api/v1/sales-targets')
            ->assertJsonPath('data.0.ratio_category_id', $reading)
            ->assertJsonPath('data.0.ratio_category_name', 'リーディンググラス');
    }

    public function test_lists_targets_of_the_previous_year_that_can_be_carried_over(): void
    {
        $item = $this->item('fl-01', ['fl-01-1-10' => 30]);
        $this->item('fl-02', ['fl-02-1-10' => 30]);
        $this->target('fl-01', 1200, ['monthly_quantities' => [150, ...array_fill(0, 11, null)], 'sku_quantities' => [$item->skus()->first()->id => 1000]]);
        $this->target('fl-02', 800);
        SalesTarget::create(['fiscal_year' => 2027, 'item_no' => 'fl-02', 'annual_quantity' => 900, 'source' => ChangeSource::Screen]);

        $this->getJson('/api/v1/sales-targets/carry-over?fiscal_year=2027')
            ->assertOk()
            ->assertJsonPath('meta.from_fiscal_year', 2026)
            ->assertJsonPath('data', [
                ['item_no' => 'fl-01', 'annual_quantity' => 1200, 'monthly_override_count' => 1, 'sku_override_count' => 1, 'current_annual_quantity' => null],
                ['item_no' => 'fl-02', 'annual_quantity' => 800, 'monthly_override_count' => 0, 'sku_override_count' => 0, 'current_annual_quantity' => 900],
            ]);
    }

    public function test_carries_over_the_chosen_items_with_the_chosen_contents_as_new_versions(): void
    {
        $item = $this->item('fl-01', ['fl-01-1-10' => 30]);
        $this->item('fl-02', ['fl-02-1-10' => 30]);
        $skuId = $item->skus()->first()->id;
        $this->target('fl-01', 1200, ['monthly_quantities' => [150, ...array_fill(0, 11, null)], 'sku_quantities' => [$skuId => 1000]]);
        $this->target('fl-02', 800);

        $this->postJson('/api/v1/sales-targets/carry-over', ['fiscal_year' => 2027, 'item_nos' => ['fl-01'], 'include_monthly' => false, 'include_skus' => true])
            ->assertCreated()
            ->assertJsonPath('data.count', 1);

        $carried = SalesTarget::query()->where('fiscal_year', 2027)->sole();
        $this->assertSame('fl-01', $carried->item_no);
        $this->assertSame(1200, $carried->annual_quantity);
        $this->assertNull($carried->monthly_quantities);
        $this->assertSame([$skuId => 1000], $carried->skuOverrides());
        $this->assertSame('2026年度から引き継ぎ', $carried->note);
        $this->assertSame(ChangeSource::CarryOver, $carried->source);
        $this->assertSame($this->user->id, $carried->changed_by_user_id);
        // 前年度の目標はそのまま
        $this->assertSame(2, SalesTarget::query()->where('fiscal_year', 2026)->count());

        $this->getJson('/api/v1/sales-targets?fiscal_year=2027')
            ->assertJsonPath('data.0.target.source_label', '前年度から引き継ぎ')
            ->assertJsonPath('data.0.target.annual_quantity', 1200);
    }

    public function test_does_not_carry_over_items_without_a_target_in_the_previous_year(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30]);

        $this->postJson('/api/v1/sales-targets/carry-over', ['fiscal_year' => 2027, 'item_nos' => ['fl-01'], 'include_monthly' => true, 'include_skus' => true])
            ->assertUnprocessable()
            ->assertJsonPath('errors.item_nos.0', 'fl-01 は2026年度の目標が無いため引き継げません。');
        $this->postJson('/api/v1/sales-targets/carry-over', ['fiscal_year' => 2027, 'item_nos' => [], 'include_monthly' => true, 'include_skus' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item_nos');

        $this->assertSame(0, SalesTarget::count());
    }

    public function test_estimates_sales_amounts_from_the_registered_or_actual_unit_price(): void
    {
        $this->item('fl-01', ['fl-01-1-10' => 30, 'fl-01-1-15' => 10, 'fl-01-1-20' => 0], unitPrice: 4000);
        $this->item('fl-02', ['fl-02-1-10' => 10], unitPrice: 3000);
        $this->item('fl-03', ['fl-03-1-10' => 0]);
        $this->select(['fl-01', 'fl-02', 'fl-03']);
        $fl01 = Item::query()->where('item_no', 'fl-01')->firstOrFail()->skus()->orderBy('position')->get();
        $this->target('fl-01', 1200, ['sku_quantities' => [$fl01[2]->id => 100]]);
        $this->target('fl-02', 100);
        $this->target('fl-03', 50, ['sku_quantities' => [Item::query()->where('item_no', 'fl-03')->firstOrFail()->skus()->first()->id => 50]]);
        $this->putJson('/api/v1/item-unit-prices/fl-02', ['unit_price' => 5500, 'amazon_unit_price' => null, 'boss_unit_price' => null])->assertOk()->assertJsonPath('data.unit_price', 5500);

        $this->getJson('/api/v1/sales-targets')
            // fl-01：SKU の平均単価 4000。売れていない SKU（上書き 100）は品番の平均単価 4000
            ->assertJsonPath('data.0.unit_price', null)
            ->assertJsonPath('data.0.actual_unit_price', 4000)
            ->assertJsonPath('data.0.skus.*.price_basis', ['sku_actual', 'sku_actual', 'item_actual'])
            ->assertJsonPath('data.0.skus.*.annual_amount', [3600000, 1200000, 400000])
            ->assertJsonPath('data.0.annual_amount', 5200000)
            // 4月は (90 + 30 + 10) × 4000
            ->assertJsonPath('data.0.monthly_amounts.0', 520000)
            ->assertJsonPath('data.0.has_unpriced_sku', false)
            // fl-02：登録した単価 5500 を使う
            ->assertJsonPath('data.1.unit_price', 5500)
            ->assertJsonPath('data.1.skus.0.price_basis_label', '登録した単価')
            ->assertJsonPath('data.1.annual_amount', 550000)
            // fl-03：販売実績も登録も無く、単価を出せない
            ->assertJsonPath('data.2.skus.0.unit_price', null)
            ->assertJsonPath('data.2.annual_amount', null)
            ->assertJsonPath('data.2.has_unpriced_sku', true);

        // 全体の単価を消すと平均単価に戻る。モール別の単価だけでは、販売目標の金額は変わらない
        $this->putJson('/api/v1/item-unit-prices/fl-02', ['unit_price' => null, 'amazon_unit_price' => 2000, 'boss_unit_price' => null])->assertOk();
        $this->getJson('/api/v1/sales-targets')->assertJsonPath('data.1.unit_price', null)->assertJsonPath('data.1.annual_amount', 300000);
    }
}
