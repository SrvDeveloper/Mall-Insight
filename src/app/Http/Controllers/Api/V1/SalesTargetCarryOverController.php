<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ChangeSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarryOverSalesTargetsRequest;
use App\Models\SalesTarget;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 年間販売目標を前年度から引き継ぐ（バックログ B-110、決定記録 K-060）。品番と、写す内容（月の手直し・SKUの上書き）を選んで写す。
 * 年間の数は必ず写す。写した目標は新しい版として記録し、どちらの年度の前の版も残す（原則3）。
 */
class SalesTargetCarryOverController extends Controller
{
    /**
     * 引き継げる品番（前年度に目標のある品番）と、引き継ぐ年度にすでに目標があるか。
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['fiscal_year' => ['required', 'integer', 'between:2001,2100']]);
        $fiscalYear = (int) $validated['fiscal_year'];
        $current = SalesTarget::currentFor($fiscalYear);

        return response()->json([
            'data' => SalesTarget::currentFor($fiscalYear - 1)->sortKeys()->values()->map(fn (SalesTarget $target): array => [
                'item_no' => $target->item_no,
                'annual_quantity' => $target->annual_quantity,
                'monthly_override_count' => count(array_filter($target->monthlyOverrides(), fn (?int $value): bool => $value !== null)),
                'sku_override_count' => count($target->skuOverrides()),
                'current_annual_quantity' => $current->get($target->item_no)?->annual_quantity,
            ])->all(),
            'meta' => ['from_fiscal_year' => $fiscalYear - 1, 'fiscal_year' => $fiscalYear],
        ]);
    }

    public function store(CarryOverSalesTargetsRequest $request): JsonResponse
    {
        $fiscalYear = $request->integer('fiscal_year');
        $from = $fiscalYear - 1;
        $previous = SalesTarget::currentFor($from);
        $itemNos = $request->validated('item_nos');
        $missing = array_values(array_diff($itemNos, $previous->keys()->all()));
        if ($missing !== []) {
            throw ValidationException::withMessages(['item_nos' => sprintf('%s は%d年度の目標が無いため引き継げません。', implode('、', $missing), $from)]);
        }

        DB::transaction(function () use ($request, $previous, $itemNos, $fiscalYear, $from): void {
            foreach ($itemNos as $itemNo) {
                $source = $previous->get($itemNo);
                SalesTarget::create([
                    'fiscal_year' => $fiscalYear,
                    'item_no' => $itemNo,
                    'annual_quantity' => $source->annual_quantity,
                    'monthly_quantities' => $request->boolean('include_monthly') ? $source->monthly_quantities : null,
                    'sku_quantities' => $request->boolean('include_skus') ? $source->sku_quantities : null,
                    'note' => "{$from}年度から引き継ぎ",
                    'source' => ChangeSource::CarryOver,
                    'changed_by_user_id' => $request->user()?->id,
                ]);
            }
        });

        return response()->json(['data' => ['fiscal_year' => $fiscalYear, 'count' => count($itemNos)]], 201);
    }
}
