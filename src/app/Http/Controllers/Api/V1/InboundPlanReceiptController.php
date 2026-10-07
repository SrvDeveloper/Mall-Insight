<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\InboundPlanResource;
use App\Models\InboundPlan;

class InboundPlanReceiptController extends Controller
{
    /**
     * 入荷済みにする。入荷した分は在庫（ZeroStockView）に反映されるため、在庫推移に入れなくなる。
     */
    public function store(InboundPlan $inboundPlan): InboundPlanResource
    {
        $inboundPlan->update(['received_at' => now()]);

        return InboundPlanResource::make($inboundPlan->load(['item', 'allocations.sku']));
    }

    /**
     * 入荷済みを取り消す。
     */
    public function destroy(InboundPlan $inboundPlan): InboundPlanResource
    {
        $inboundPlan->update(['received_at' => null]);

        return InboundPlanResource::make($inboundPlan->load(['item', 'allocations.sku']));
    }
}
