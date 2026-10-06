<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryDateResource;
use App\Models\Inventory;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InventoryDateController extends Controller
{
    /**
     * 取得済みの在庫基準日の一覧（新しい順）。日ごとのSKU数と、最後に取得した日時。
     */
    public function index(): AnonymousResourceCollection
    {
        $dates = Inventory::query()
            ->selectRaw('stock_date, count(distinct sku_id) as sku_count, max(updated_at) as fetched_at')
            ->groupBy('stock_date')
            ->orderByDesc('stock_date')
            ->paginate(31);

        return InventoryDateResource::collection($dates);
    }
}
