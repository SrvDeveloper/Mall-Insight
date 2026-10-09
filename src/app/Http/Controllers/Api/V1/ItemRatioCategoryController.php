<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\ItemRatioCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ItemRatioCategoryController extends Controller
{
    /**
     * 品番に使う月別販売比率の区分を変更する（バックログ B-110、K-049②・K-058）。
     */
    public function update(Request $request, string $itemNo): JsonResponse
    {
        abort_unless(Item::query()->where('item_no', $itemNo)->exists(), 404);
        $validated = $request->validate(['ratio_category_id' => ['required', 'integer', 'exists:ratio_categories,id']], [], ['ratio_category_id' => '区分']);

        $setting = ItemRatioCategory::query()->updateOrCreate(
            ['item_no' => $itemNo],
            ['ratio_category_id' => (int) $validated['ratio_category_id'], 'source' => ChangeSource::Screen, 'changed_by_user_id' => $request->user()?->id],
        );

        return response()->json(['data' => ['item_no' => $setting->item_no, 'ratio_category_id' => $setting->ratio_category_id, 'ratio_category_name' => $setting->ratioCategory->name]]);
    }
}
