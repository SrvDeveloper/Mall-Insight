<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Enums\Channel;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemSelection;
use App\Models\ItemUnitPrice;
use App\Services\Pricing\UnitPriceCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 品番の単価（バックログ B-110、決定記録 K-063）。全体・Amazon・BOSSの単価（税込）を登録し、直近12か月の平均単価と並べて見る。
 */
class ItemUnitPriceController extends Controller
{
    /**
     * CrossWalker の全品番の、登録した単価と直近12か月の平均単価（品番番号の順）。
     */
    public function index(): JsonResponse
    {
        $items = Item::query()->with('skus')->orderBy('item_no')->get();
        $catalog = UnitPriceCatalog::build($items, CarbonImmutable::now());
        $targetItemNos = ItemSelection::current()?->items->pluck('item_no')->all() ?? [];

        return response()->json([
            'data' => $items->map(function (Item $item) use ($catalog, $targetItemNos): array {
                $registered = $catalog->registered($item->item_no);

                return [
                    'item_no' => $item->item_no,
                    'brand' => $item->brand,
                    'category' => $item->category,
                    'status' => $item->status->value,
                    'status_label' => $item->status->label(),
                    'is_target_item' => in_array($item->item_no, $targetItemNos, true),
                    'registered' => $registered === null ? null : [
                        'unit_price' => $registered->unit_price,
                        'amazon_unit_price' => $registered->amazon_unit_price,
                        'boss_unit_price' => $registered->boss_unit_price,
                        'source_label' => $registered->source->label(),
                        'changed_by' => $registered->changedBy?->name,
                        'changed_at' => $registered->updated_at->toIso8601String(),
                    ],
                    'actual' => collect([UnitPriceCatalog::ALL, ...array_map(fn (Channel $channel): string => $channel->value, Channel::cases())])
                        ->mapWithKeys(function (string $key) use ($catalog, $item): array {
                            $total = $catalog->itemTotal($item->item_no, $key);

                            return [$key => ['quantity' => $total->quantity, 'unit_price' => $total->averagePrice() === null ? null : round($total->averagePrice())]];
                        })->all(),
                ];
            })->all(),
            'meta' => ['sales_from' => $catalog->from->toDateString(), 'sales_to' => $catalog->to->toDateString()],
        ]);
    }

    /**
     * 品番の単価を登録・変更する。空にした単価は登録を消し、販売実績の平均単価を使う。3つとも空なら登録をまるごと消す。
     */
    public function update(Request $request, string $itemNo): JsonResponse
    {
        abort_unless(Item::query()->where('item_no', $itemNo)->exists(), 404);
        $rule = ['present', 'nullable', 'integer', 'between:1,10000000'];
        $validated = $request->validate(
            ['unit_price' => $rule, 'amazon_unit_price' => $rule, 'boss_unit_price' => $rule],
            [],
            ['unit_price' => '全体の単価', 'amazon_unit_price' => 'Amazonの単価', 'boss_unit_price' => 'BOSSの単価'],
        );

        if (array_filter($validated, fn (mixed $value): bool => $value !== null) === []) {
            ItemUnitPrice::query()->where('item_no', $itemNo)->delete();

            return response()->json(['data' => ['item_no' => $itemNo, 'unit_price' => null, 'amazon_unit_price' => null, 'boss_unit_price' => null]]);
        }

        $price = ItemUnitPrice::query()->updateOrCreate(
            ['item_no' => $itemNo],
            [...$validated, 'source' => ChangeSource::Screen, 'changed_by_user_id' => $request->user()?->id],
        );

        return response()->json(['data' => $price->only(['item_no', 'unit_price', 'amazon_unit_price', 'boss_unit_price'])]);
    }
}
