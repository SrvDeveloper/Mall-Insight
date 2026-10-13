<?php

namespace App\Http\Requests;

use App\Services\Actuals\SalesUnit;
use App\Services\Forecast\ForecastTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 需要予測の画面（B-131、K-123）の並び。月ごとか日ごとか、予測し直す起点（何か月前の1日か）、日ごとの期間、品番（SKU別の画面）。
 */
class ShowForecastTimelineRequest extends FormRequest
{
    /** 日ごとに選べる、今日から先の日数（K-123）。 */
    public const MAX_FUTURE_DAYS = 90;

    /** 日ごとに一度に出せる日数。 */
    public const MAX_DAYS = 400;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unit' => ['nullable', Rule::enum(SalesUnit::class)],
            'offset' => ['nullable', 'integer', Rule::in(ForecastTimeline::OFFSETS)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'item_no' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->unit() !== SalesUnit::Day) {
                return;
            }
            [$from, $to] = $this->period();
            $today = CarbonImmutable::today();
            if ($to->gt($today->addDays(self::MAX_FUTURE_DAYS - 1))) {
                $validator->errors()->add('to', '日ごとの予測は、今日から'.self::MAX_FUTURE_DAYS.'日先までです。');
            }
            if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('from', '日ごとに出せるのは'.self::MAX_DAYS.'日までです。');
            }
        }];
    }

    public function unit(): SalesUnit
    {
        return SalesUnit::tryFrom((string) $this->input('unit')) ?? SalesUnit::Month;
    }

    public function offset(): int
    {
        return (int) $this->input('offset', 0);
    }

    /**
     * 日ごとの期間。指定が無ければ過去30日・今日・これからの30日。
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(): array
    {
        $today = CarbonImmutable::today();
        $from = $this->input('from');
        $to = $this->input('to');

        return [
            is_string($from) && $from !== '' ? CarbonImmutable::parse($from) : $today->subDays(30),
            is_string($to) && $to !== '' ? CarbonImmutable::parse($to) : $today->addDays(30),
        ];
    }

    public function itemNo(): ?string
    {
        $itemNo = (string) $this->input('item_no');

        return $itemNo === '' ? null : $itemNo;
    }
}
