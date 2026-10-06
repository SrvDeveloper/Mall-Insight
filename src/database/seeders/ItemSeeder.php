<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Models\Sku;
use Illuminate\Database\Seeder;

/**
 * 画面確認用のサンプル品番・SKU。本番データは CrossWalker から取得する（B-002）。
 */
class ItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Item::factory()->count(20)->withSkus(['1', '2', '3'], ['10', '15', '20', '25'])->create();
        Item::factory()->count(5)->withSkus(['1', '2', '3', '4'], ['0'])->create(['category' => 'サングラス']);
        Item::factory()->inactive()->withSkus(['1'], ['10', '15'])->create();

        Sku::factory()->count(3)->unassigned()->create();
    }
}
