<?php

namespace App\Http\Requests;

use App\Models\SalesEvent;
use App\Models\SalesEventPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * イベントの期間の登録・変更（B-129、決定記録 K-114）。初日・最終日（31日まで）・メモ。同じイベントの期間どうしは重ねない
 * （同じ日を2回数えると、倍率の候補を求めるときに2回数えてしまうため）。
 */
class SaveSalesEventPeriodRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 期間の入力の規則。イベントの登録で最初の期間を一緒に入れるときは、$prefix に「period.」を付ける。
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function periodRules(string $prefix = ''): array
    {
        $required = $prefix === '' ? 'required' : "required_with:{$prefix}starts_on,{$prefix}ends_on";

        return [
            "{$prefix}starts_on" => [$required, 'date_format:Y-m-d'],
            "{$prefix}ends_on" => [$required, 'date_format:Y-m-d', "after_or_equal:{$prefix}starts_on"],
            "{$prefix}note" => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * 期間が31日を超えていれば、入力エラーにする。
     *
     * @param  array<string, mixed>  $period
     */
    public static function validateLength(Validator $validator, array $period, string $prefix = ''): bool
    {
        $days = CarbonImmutable::parse((string) $period['starts_on'])->diffInDays(CarbonImmutable::parse((string) $period['ends_on'])) + 1;
        if ($days > SalesEventPeriod::MAX_DAYS) {
            $validator->errors()->add("{$prefix}ends_on", '期間は'.SalesEventPeriod::MAX_DAYS.'日までにしてください。それより長い売れ方の変化は、季節係数で扱います。');

            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return self::periodRules();
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! self::validateLength($validator, $this->only(['starts_on', 'ends_on']))) {
                    return;
                }
                $current = $this->route('salesEventPeriod');
                $eventId = $current instanceof SalesEventPeriod ? $current->sales_event_id : $this->route('salesEvent')?->id;
                $overlapping = SalesEventPeriod::query()
                    ->where('sales_event_id', $eventId)
                    ->whereDate('starts_on', '<=', (string) $this->input('ends_on'))
                    ->whereDate('ends_on', '>=', (string) $this->input('starts_on'))
                    ->when($current instanceof SalesEventPeriod, fn ($query) => $query->whereKeyNot($current->id))
                    ->first();
                if ($overlapping !== null) {
                    $validator->errors()->add('starts_on', "このイベントの別の期間（{$overlapping->starts_on->format('Y/n/j')}〜{$overlapping->ends_on->format('Y/n/j')}）と重なっています。");
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
            'starts_on' => '初日',
            'ends_on' => '最終日',
            'note' => 'メモ',
        ];
    }

    /**
     * 期間を登録するイベント（変更のときは期間のイベント）。
     */
    public function salesEvent(): ?SalesEvent
    {
        $period = $this->route('salesEventPeriod');

        return $period instanceof SalesEventPeriod ? $period->salesEvent : $this->route('salesEvent');
    }
}
