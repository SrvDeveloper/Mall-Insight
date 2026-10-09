<?php

namespace App\Http\Requests;

use App\Models\MonthlySalesRatio;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveMonthlySalesRatioRequest extends FormRequest
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
            'ratio_category_id' => ['required', 'integer', 'exists:ratio_categories,id'],
            'ratios' => ['required', 'array', 'size:12', $this->totalsHundredPercent(...)],
            'ratios.*' => ['required', 'integer', 'between:0,10000'],
        ];
    }

    /**
     * 12か月の合計は100%（10000）でなければ保存できない（K-106）。
     */
    private function totalsHundredPercent(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }
        $total = array_sum(array_map('intval', $value));
        if ($total !== MonthlySalesRatio::TOTAL) {
            $fail(sprintf('12か月の合計を100%%にしてください（今の合計は%s%%です）。', number_format($total / 100, 2)));
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'ratio_category_id' => '区分',
            'ratios' => '月別販売比率',
            'ratios.*' => '月別販売比率',
        ];
    }
}
