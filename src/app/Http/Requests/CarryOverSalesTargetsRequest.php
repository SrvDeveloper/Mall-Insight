<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CarryOverSalesTargetsRequest extends FormRequest
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
            'fiscal_year' => ['required', 'integer', 'between:2001,2100'],
            'item_nos' => ['required', 'array', 'min:1'],
            'item_nos.*' => ['required', 'string', 'distinct'],
            'include_monthly' => ['required', 'boolean'],
            'include_skus' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fiscal_year' => '年度',
            'item_nos' => '引き継ぐ品番',
            'item_nos.*' => '品番',
            'include_monthly' => '月の手直し',
            'include_skus' => 'SKUの上書き',
        ];
    }
}
