<?php

namespace App\Http\Requests;

use App\Enums\Channel;
use App\Models\ForecastAdjustment;
use App\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * 手動での予測補正の登録（B-109、決定記録 K-132）。品番・チャネル・始める月・終える月（空なら取り消すまで）・倍率（%）・理由。
 * 始める月は今月から（過去の月の予測は補正しない）。同じ品番・チャネルの取り消していない補正と月を重ねない。
 * 補正を変えるときは、変える補正（replaces）を取り消して新しい補正を登録する（変える前の補正も記録として残す）。
 */
class SaveForecastAdjustmentRequest extends FormRequest
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
            'item_no' => ['required', 'string', 'max:255', Rule::exists(Item::class, 'item_no')],
            'channel' => ['required', Rule::enum(Channel::class)],
            'starts_on' => ['required', 'date_format:Y-m'],
            'ends_on' => ['nullable', 'date_format:Y-m'],
            'rate_percent' => ['required', 'integer', 'min:'.ForecastAdjustment::MIN_PERCENT, 'max:'.ForecastAdjustment::MAX_PERCENT, 'not_in:100'],
            'reason' => ['required', 'string', 'max:200'],
            'replaces' => ['nullable', 'integer', Rule::exists(ForecastAdjustment::class, 'id')->whereNull('cancelled_at')],
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
                $from = $this->startsOn();
                $to = $this->endsOn();
                if ($from->lt(CarbonImmutable::today()->startOfMonth())) {
                    $validator->errors()->add('starts_on', '始める月は今月より後にしてください。過去の月の予測は補正しません。');

                    return;
                }
                if ($to !== null && $to->lt($from)) {
                    $validator->errors()->add('ends_on', '終える月は始める月と同じか、それより後にしてください。');

                    return;
                }
                $replaces = $this->replaced();
                if ($replaces !== null && ($replaces->item_no !== $this->input('item_no') || $replaces->channel->value !== $this->input('channel'))) {
                    $validator->errors()->add('replaces', '変える補正と、品番・チャネルが違います。');

                    return;
                }
                $overlapping = ForecastAdjustment::query()
                    ->active()
                    ->where('item_no', $this->input('item_no'))
                    ->where('channel', $this->input('channel'))
                    ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from->toDateString()))
                    ->when($to !== null, fn ($query) => $query->whereDate('starts_on', '<=', $to->toDateString()))
                    ->when($replaces !== null, fn ($query) => $query->whereKeyNot($replaces->id))
                    ->first();
                if ($overlapping !== null) {
                    $period = $overlapping->starts_on->format('Y/n').'〜'.($overlapping->ends_on?->format('Y/n') ?? '');
                    $validator->errors()->add('starts_on', "このチャネルの別の補正（{$period}、{$overlapping->rate_percent}%）と月が重なっています。その補正を変えるか、取り消してください。");
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
            'item_no' => '品番',
            'channel' => 'チャネル',
            'starts_on' => '始める月',
            'ends_on' => '終える月',
            'rate_percent' => '倍率',
            'reason' => '理由',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rate_percent.not_in' => '100%は補正しないのと同じです。補正をやめるときは取り消してください。',
        ];
    }

    public function startsOn(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->input('starts_on').'-01')->startOfDay();
    }

    public function endsOn(): ?CarbonImmutable
    {
        $endsOn = $this->input('ends_on');

        return $endsOn === null || $endsOn === '' ? null : CarbonImmutable::createFromFormat('Y-m-d', $endsOn.'-01')->startOfDay();
    }

    /**
     * 変える（取り消す）補正。
     */
    public function replaced(): ?ForecastAdjustment
    {
        $id = $this->input('replaces');

        return $id === null ? null : ForecastAdjustment::query()->find($id);
    }
}
