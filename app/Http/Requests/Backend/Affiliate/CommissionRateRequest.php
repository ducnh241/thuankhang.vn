<?php

namespace App\Http\Requests\Backend\Affiliate;

use App\Models\CommissionRate;
use App\Models\Level;
use App\Support\AffiliateSettings;
use App\Support\Percent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Validator;

/**
 * Lưu phiên bản ma trận hoa hồng mới (A3): tỉ lệ 0–100 (2 số lẻ) cho mọi cấp hưởng × F1/F2, giờ hiệu lực từ bây
 * giờ trở đi. F1 + F2 của một cấp vượt ngưỡng cảnh báo (tham số chung) thì phải tích xác nhận.
 */
class CommissionRateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'rates' => ['required', 'array'],
            'rates.*' => ['array'],
            'rates.*.*' => ['nullable'],
            'effective_from' => ['required', 'date_format:Y-m-d H:i:s,Y-m-d H:i,Y-m-d\TH:i'],
            'confirm_over' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'effective_from.required' => 'Chọn thời điểm hiệu lực.',
            'effective_from.date_format' => 'Thời điểm hiệu lực không hợp lệ.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $warning = (float) AffiliateSettings::get('commission_warning_percent');
            $over = [];
            foreach (Level::earning() as $level) {
                $sum = 0.0;
                foreach (array_keys(CommissionRate::TIERS) as $tier) {
                    $rate = Percent::parse($this->input("rates.{$level->id}.{$tier}"));
                    if ($rate === null) {
                        $validator->errors()->add("rates.{$level->id}.{$tier}", 'Nhập tỉ lệ từ 0 đến 100, tối đa 2 chữ số lẻ.');
                    }
                    $sum += (float) $rate;
                }
                if ($sum > $warning) {
                    $over[] = $level->name.' ('.Percent::format($sum).'%)';
                }
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $at = $this->effectiveFrom();
            if ($at->lt(now()->startOfMinute())) {
                $validator->errors()->add('effective_from', 'Thời điểm hiệu lực không được ở quá khứ (đơn đã thanh toán giữ tỉ lệ cũ).');
            } elseif ($at->gt(now()->addYear())) {
                $validator->errors()->add('effective_from', 'Thời điểm hiệu lực tối đa 1 năm tới.');
            } elseif (CommissionRate::where('effective_from', $at)->exists()) {
                $validator->errors()->add('effective_from', 'Đã có phiên bản hiệu lực đúng thời điểm này, chọn giờ khác.');
            }

            $latest = CommissionRate::versions()->first();
            if ($latest && $this->rates() == array_map(fn ($r) => array_map(fn ($v) => Percent::parse($v), $r), $latest['rates'])) {
                $validator->errors()->add('rates', 'Tỉ lệ không khác phiên bản mới nhất, không có gì để lưu.');
            }

            if ($over && ! $this->boolean('confirm_over')) {
                $validator->errors()->add('confirm_over', 'F1 + F2 vượt ngưỡng '.Percent::format($warning).'% ở: '.implode(', ', $over).'. Tích xác nhận để lưu.');
            }
        }];
    }

    /** Giờ hiệu lực; chọn đúng phút hiện tại = ngay bây giờ (giữ cả giây để lưu hai lần trong một phút không trùng). */
    public function effectiveFrom(): Carbon
    {
        $value = str_replace('T', ' ', $this->input('effective_from'));
        $at = Carbon::createFromFormat(strlen($value) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s', $value)->startOfMinute();

        return $at->equalTo(now()->startOfMinute()) ? now()->startOfSecond() : $at;
    }

    /** [level_id][tier] => "10.00" cho mọi cấp hưởng. */
    public function rates(): array
    {
        $rates = [];
        foreach (Level::earning() as $level) {
            foreach (array_keys(CommissionRate::TIERS) as $tier) {
                $rates[$level->id][$tier] = Percent::parse($this->input("rates.{$level->id}.{$tier}"));
            }
        }

        return $rates;
    }
}
