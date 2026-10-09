<?php

namespace App\Http\Requests;

use App\Models\Item;
use App\Models\Sku;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SaveSalesTargetRequest extends FormRequest
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
            'fiscal_year' => ['required', 'integer', 'between:2000,2100'],
            'item_no' => ['required', 'string', 'exists:items,item_no'],
            'annual_quantity' => ['required', 'integer', 'between:1,10000000'],
            'monthly_quantities' => ['nullable', 'array', 'size:12'],
            'monthly_quantities.*' => ['nullable', 'integer', 'between:0,10000000'],
            'sku_quantities' => ['nullable', 'array', $this->skusOfItem(...)],
            'sku_quantities.*' => ['nullable', 'integer', 'between:0,10000000'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * SKUの上書きは、その品番のSKUにだけ入れられる。
     */
    private function skusOfItem(string $attribute, mixed $value, Closure $fail): void
    {
        $item = Item::query()->where('item_no', $this->input('item_no'))->first();
        if ($item === null || ! is_array($value)) {
            return;
        }
        $skuIds = array_map('intval', array_keys($value));
        $known = Sku::query()->where('item_id', $item->id)->whereIn('id', $skuIds)->count();
        if ($known !== count(array_unique($skuIds))) {
            $fail('SKUの数は、この品番のSKUにだけ入れられます。');
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'fiscal_year' => '年度',
            'item_no' => '品番',
            'annual_quantity' => '年間販売目標',
            'monthly_quantities' => '月間販売目標',
            'monthly_quantities.*' => '月間販売目標',
            'sku_quantities' => 'SKUの数',
            'sku_quantities.*' => 'SKUの数',
            'note' => 'メモ',
        ];
    }

    /**
     * 手で直した月（4月～翌3月）。空欄は null（年間×月別販売比率を使う）。すべて空欄なら null。
     *
     * @return list<int|null>|null
     */
    public function monthlyQuantities(): ?array
    {
        $values = $this->validated('monthly_quantities');
        if ($values === null) {
            return null;
        }
        $months = array_map(fn (mixed $value): ?int => $value === null ? null : (int) $value, array_values($values));

        return array_filter($months, fn (?int $value): bool => $value !== null) === [] ? null : $months;
    }

    /**
     * 手で上書きしたSKUの年間の数（SKU ID => 数量）。空欄のSKUは上書きしない。
     *
     * @return array<int, int>|null
     */
    public function skuQuantities(): ?array
    {
        $values = [];
        foreach ($this->validated('sku_quantities') ?? [] as $skuId => $quantity) {
            if ($quantity !== null) {
                $values[(int) $skuId] = (int) $quantity;
            }
        }

        return $values === [] ? null : $values;
    }
}
