<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveRatioCategoryRequest;
use App\Models\RatioCategory;
use Illuminate\Http\JsonResponse;

/**
 * 月別販売比率の区分を足す・名前を変える（バックログ B-110、決定記録 K-061）。足した区分の比率は、月別販売比率の画面で入れる。
 */
class RatioCategoryController extends Controller
{
    public function store(SaveRatioCategoryRequest $request): JsonResponse
    {
        $category = RatioCategory::create([
            'name' => $request->validated('name'),
            'position' => (int) RatioCategory::query()->max('position') + 1,
            'changed_by_user_id' => $request->user()?->id,
        ]);

        return response()->json(['data' => $this->toArray($category)], 201);
    }

    /**
     * 名前だけを変える。最初からある4区分も変えられ、印（code）は変わらないため初期値の引き方や取り込みには影響しない。
     */
    public function update(SaveRatioCategoryRequest $request, RatioCategory $ratioCategory): JsonResponse
    {
        $ratioCategory->update(['name' => $request->validated('name'), 'changed_by_user_id' => $request->user()?->id]);

        return response()->json(['data' => $this->toArray($ratioCategory)]);
    }

    /**
     * @return array{id: int, code: string|null, name: string}
     */
    private function toArray(RatioCategory $category): array
    {
        return ['id' => $category->id, 'code' => $category->code?->value, 'name' => $category->name];
    }
}
