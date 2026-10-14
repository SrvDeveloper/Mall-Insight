<?php

namespace App\Http\Requests;

use App\Models\ItemSelection;
use App\Services\InventoryTrend\TrendScope;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemSelectionRequest extends FormRequest
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
        return [
            // 全体の対象品番は Amazon・BOSS を合わせたもので、確定しない（K-130）
            'scope' => ['required', Rule::enum(TrendScope::class)->except([TrendScope::Total])],
            'item_nos' => ['required', 'array', 'min:1', 'max:'.ItemSelection::MAX_ITEMS],
            'item_nos.*' => ['required', 'string', 'distinct', 'exists:items,item_no'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'scope' => 'チャネル',
            'item_nos' => '対象品番',
            'item_nos.*' => '品番',
            'note' => 'メモ',
        ];
    }
}
