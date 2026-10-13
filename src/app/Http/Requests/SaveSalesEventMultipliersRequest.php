<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Models\SalesEventPeriod;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * イベントの期間の品番ごとの倍率を、担当者が入れる（B-129、K-112・K-114）。送った内容でまるごと置き換え、送らなかった品番はシステムの候補に戻す。
 * 終わった期間の倍率は需要予測に使わないため、入れられない。チャネルはイベントのモールのチャネルだけ。
 */
class SaveSalesEventMultipliersRequest extends FormRequest
{
    /** 入れられる倍率の上限 */
    public const MAX_MULTIPLIER = 50;

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
        /** @var SalesEventPeriod $event */
        $event = $this->route('salesEventPeriod');

        return [
            'multipliers' => ['present', 'array'],
            'multipliers.*.item_no' => ['required', 'string', 'max:255'],
            'multipliers.*.channel' => ['required', Rule::in(array_map(fn (Channel $channel): string => $channel->value, $event->channels()))],
            'multipliers.*.multiplier' => ['required', 'numeric', 'min:0', 'max:'.self::MAX_MULTIPLIER, 'decimal:0,2'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var SalesEventPeriod $event */
                $event = $this->route('salesEventPeriod');
                if ($event->hasEndedBefore(today()->toImmutable())) {
                    $validator->errors()->add('multipliers', '終わった期間の倍率は、需要予測に使わないため変えられません。');

                    return;
                }
                $keys = array_map(fn (array $row): string => ($row['item_no'] ?? '').'|'.($row['channel'] ?? ''), $this->input('multipliers', []));
                if (count($keys) !== count(array_unique($keys))) {
                    $validator->errors()->add('multipliers', '同じ品番・チャネルの倍率が2つあります。');
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
            'multipliers.*.item_no' => '品番',
            'multipliers.*.channel' => 'チャネル',
            'multipliers.*.multiplier' => '倍率',
        ];
    }
}
