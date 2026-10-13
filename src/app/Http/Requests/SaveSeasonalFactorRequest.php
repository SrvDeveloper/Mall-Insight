<?php

namespace App\Http\Requests;

use App\Models\ItemSeasonalFactor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 品番の季節係数の登録（B-106、K-113）。1月～12月の12か月分を、0.1～5.0（小数2桁まで）で入れる。factors を null にすると登録をやめる。
 */
class SaveSeasonalFactorRequest extends FormRequest
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
            'factors' => ['present', 'nullable', 'array', 'size:12'],
            'factors.*' => ['required', 'numeric', 'min:'.ItemSeasonalFactor::MIN_FACTOR, 'max:'.ItemSeasonalFactor::MAX_FACTOR, 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'factors' => '季節係数',
            'factors.*' => '季節係数',
            'note' => 'メモ',
        ];
    }
}
