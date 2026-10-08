<?php

namespace App\Http\Requests;

use App\Models\InventoryTrendSetting;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryTrendSettingRequest extends FormRequest
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
            'check_month_offset' => ['required', 'integer', 'between:'.InventoryTrendSetting::MIN_CHECK_MONTH_OFFSET.','.InventoryTrendSetting::MAX_CHECK_MONTH_OFFSET],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'check_month_offset' => '判定する月',
        ];
    }
}
