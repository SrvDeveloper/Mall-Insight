<?php

namespace App\Http\Requests;

use App\Enums\Mall;
use App\Models\SalesEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * イベントの登録・変更（B-129、決定記録 K-112・K-114）。名前・モール・メモを入れる。期間はイベントに別に登録する。
 * 名前（全角・半角、英字の大文字・小文字、空白の違いは同じとみなす）は重ねない。登録するときは、最初の期間を一緒に入れられる。
 */
class SaveSalesEventRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:100'],
            'malls' => ['required', 'array', 'min:1'],
            'malls.*' => ['required', 'distinct', Rule::enum(Mall::class)],
            'note' => ['nullable', 'string', 'max:500'],
            'period' => ['nullable', 'array'],
            ...SaveSalesEventPeriodRequest::periodRules('period.'),
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
                $current = $this->route('salesEvent');
                $key = SalesEvent::normalizeName((string) $this->input('name'));
                $duplicate = SalesEvent::query()
                    ->when($current instanceof SalesEvent, fn ($query) => $query->whereKeyNot($current->id))
                    ->get()
                    ->first(fn (SalesEvent $other): bool => SalesEvent::normalizeName($other->name) === $key);
                if ($duplicate !== null) {
                    $validator->errors()->add('name', "同じ名前のイベント（{$duplicate->name}）が登録されています。期間はそのイベントに追加してください。");
                }
                if ($this->filled('period')) {
                    SaveSalesEventPeriodRequest::validateLength($validator, (array) $this->input('period'), 'period.');
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
            'name' => '名前',
            'malls' => 'モール',
            'malls.*' => 'モール',
            'note' => 'メモ',
            'period.starts_on' => '初日',
            'period.ends_on' => '最終日',
            'period.note' => '期間のメモ',
        ];
    }
}
