<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveMonthlySalesRatioRequest;
use App\Http\Resources\MonthlySalesRatioResource;
use App\Models\MonthlySalesRatio;
use Illuminate\Http\JsonResponse;

class MonthlySalesRatioController extends Controller
{
    /**
     * 区分の月別販売比率を変更する（バックログ B-110、K-049②・K-106）。新しい行として記録し、前の比率は残す。
     */
    public function store(SaveMonthlySalesRatioRequest $request): JsonResponse
    {
        $ratio = MonthlySalesRatio::create([
            'ratio_category_id' => $request->integer('ratio_category_id'),
            'ratios' => array_map('intval', array_values($request->validated('ratios'))),
            'source' => ChangeSource::Screen,
            'changed_by_user_id' => $request->user()?->id,
        ]);

        return MonthlySalesRatioResource::make($ratio->load('changedBy'))->response()->setStatusCode(201);
    }
}
