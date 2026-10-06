<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ItemIndexRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    /**
     * 品番一覧（所属SKUを含む）。品番コードの昇順。
     */
    public function index(ItemIndexRequest $request): AnonymousResourceCollection
    {
        $keyword = $request->validated('keyword');
        $status = $request->validated('status') ?? 'all';

        $items = Item::query()
            ->with('skus')
            ->when($status !== 'all', fn (Builder $query) => $query->where('status', $status))
            ->when($keyword, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->whereLike('item_no', "%{$keyword}%")
                    ->orWhereHas('skus', fn (Builder $query) => $query->whereLike('sku_code', "%{$keyword}%")),
            ))
            ->orderBy('item_no')
            ->paginate($request->integer('per_page', 25))
            ->withQueryString();

        return ItemResource::collection($items);
    }
}
