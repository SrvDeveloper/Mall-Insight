<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnregisteredSkuResource;
use App\Models\Sku;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UnregisteredSkuController extends Controller
{
    /**
     * 販売実績か在庫に出てくるのに、どの品番にも属していないSKUの一覧（バックログ B-010）。直近12か月の販売数量が多い順（決定記録 K-029）。
     * 販売実績・在庫に最初と最後に出てきた日は、期間に関係なく保存済みのデータ全体から求める。
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1']]);

        $salesTo = today();
        $salesFrom = $salesTo->copy()->subYear()->addDay();

        $skus = Sku::query()
            ->whereNull('item_id')
            ->where(fn (Builder $query) => $query->whereHas('salesLines')->orWhereHas('inventories'))
            ->withSum(['salesLines as recent_sales_quantity' => fn (Builder $query) => $query
                ->where('sales_date', '>=', $salesFrom->toDateString())
                ->where('sales_date', '<', $salesTo->copy()->addDay()->toDateString())], 'quantity')
            ->withMin('salesLines as first_sold_on', 'sales_date')
            ->withMax('salesLines as last_sold_on', 'sales_date')
            ->withMin('inventories as first_stocked_on', 'stock_date')
            ->withMax('inventories as last_stocked_on', 'stock_date')
            ->orderByRaw('coalesce(recent_sales_quantity, 0) desc')
            ->orderBy('sku_code')
            ->paginate(50);

        return UnregisteredSkuResource::collection($skus)->additional([
            'meta' => [
                'sales_from' => $salesFrom->toDateString(),
                'sales_to' => $salesTo->toDateString(),
            ],
        ]);
    }
}
