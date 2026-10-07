<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 入荷予定の登録・変更（決定記録 K-043）。SKUへの割り振りは任意で、その品番のSKUだけ、合計は入荷予定数以内。
 */
class SaveInboundPlanRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $itemId = Item::query()->where('item_no', (string) $this->input('item_no'))->value('id');

        return [
            'item_no' => ['required', 'string', 'exists:items,item_no'],
            'arrival_month' => ['required', 'date_format:Y-m'],
            'quantity' => ['required', 'integer', 'min:1', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'allocations' => ['present', 'array'],
            'allocations.*.sku_id' => ['required', 'integer', 'distinct', Rule::exists('skus', 'id')->where('item_id', $itemId ?? 0)],
            'allocations.*.quantity' => ['required', 'integer', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $allocated = array_sum(array_column($this->input('allocations', []), 'quantity'));
                if ($allocated > (int) $this->input('quantity')) {
                    $validator->errors()->add('allocations', "SKUへの割り振りの合計（{$allocated}）が入荷予定数（{$this->input('quantity')}）を超えています。");
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'item_no' => '品番',
            'arrival_month' => '入荷予定月',
            'quantity' => '入荷予定数',
            'note' => 'メモ',
            'allocations' => 'SKUへの割り振り',
            'allocations.*.sku_id' => 'SKU',
            'allocations.*.quantity' => '割り振る数',
        ];
    }
}
