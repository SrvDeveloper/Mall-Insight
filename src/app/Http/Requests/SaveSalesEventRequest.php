<?php

namespace App\Http\Requests;

use App\Enums\Mall;
use App\Models\SalesEvent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * イベントの登録・変更（B-129、決定記録 K-112）。期間は31日まで。同じ名前のイベントを、同じチャネルで期間を重ねて登録しない
 * （同じイベントを2回登録すると、倍率の候補を求めるときに2回数えてしまうため）。
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
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'malls' => ['required', 'array', 'min:1'],
            'malls.*' => ['required', 'distinct', Rule::enum(Mall::class)],
            'note' => ['nullable', 'string', 'max:500'],
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
                $startsOn = CarbonImmutable::parse($this->input('starts_on'));
                $endsOn = CarbonImmutable::parse($this->input('ends_on'));
                if ($startsOn->diffInDays($endsOn) + 1 > SalesEvent::MAX_DAYS) {
                    $validator->errors()->add('ends_on', '期間は'.SalesEvent::MAX_DAYS.'日までにしてください。それより長い売れ方の変化は、季節性で扱います。');

                    return;
                }
                $event = new SalesEvent(['name' => $this->input('name'), 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'malls' => $this->input('malls')]);
                $current = $this->route('salesEvent');
                $overlapping = SalesEvent::query()
                    ->whereDate('starts_on', '<=', $endsOn->toDateString())
                    ->whereDate('ends_on', '>=', $startsOn->toDateString())
                    ->when($current instanceof SalesEvent, fn ($query) => $query->whereKeyNot($current->id))
                    ->get()
                    ->first(fn (SalesEvent $other): bool => $other->nameKey() === $event->nameKey() && array_intersect(array_map(fn ($channel) => $channel->value, $other->channels()), array_map(fn ($channel) => $channel->value, $event->channels())) !== []);
                if ($overlapping !== null) {
                    $validator->errors()->add('starts_on', "同じ名前のイベント（{$overlapping->starts_on->format('Y/n/j')}〜{$overlapping->ends_on->format('Y/n/j')}）と期間が重なっています。");
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
            'starts_on' => '初日',
            'ends_on' => '最終日',
            'malls' => 'モール',
            'malls.*' => 'モール',
            'note' => 'メモ',
        ];
    }
}
