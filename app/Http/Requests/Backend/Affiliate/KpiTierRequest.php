<?php

namespace App\Http\Requests\Backend\Affiliate;

use App\Support\Money;
use App\Support\Percent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Lưu bảng bậc KPI (A4, chỉ lưu tham số — Phase 2 mới tính). Xếp theo "từ": "đến" > "từ"; chỉ bậc cuối được để trống
 * "đến" (không giới hạn); bậc sau bắt đầu từ "đến" của bậc trước trở lên (không chồng lấn).
 */
class KpiTierRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tiers' => ['nullable', 'array', 'max:20'],
            'tiers.*' => ['array'],
            'tiers.*.min_amount' => ['required', 'integer', 'min:0', 'max:1000000000000000'],
            'tiers.*.max_amount' => ['nullable', 'integer', 'min:1', 'max:1000000000000000'],
            'tiers.*.rate_percent' => ['required', 'string'],
            'tiers.*.min_f1' => ['required', 'integer', 'min:0', 'max:10000'],
            'kpi_pay_day' => ['required', 'integer', 'min:1', 'max:28'],
        ];
    }

    public function attributes(): array
    {
        return [
            'tiers.*.min_amount' => 'doanh số từ', 'tiers.*.max_amount' => 'doanh số đến', 'tiers.*.rate_percent' => 'tỉ lệ thưởng',
            'tiers.*.min_f1' => 'số F1 tối thiểu', 'kpi_pay_day' => 'ngày trả thưởng',
        ];
    }

    public function messages(): array
    {
        return [
            'tiers.*.min_amount.integer' => 'Nhập số tiền nguyên (đồng).',
            'tiers.*.max_amount.integer' => 'Nhập số tiền nguyên (đồng).',
        ];
    }

    protected function prepareForValidation(): void
    {
        $tiers = $this->input('tiers');
        if (! is_array($tiers)) {
            return;
        }
        foreach ($tiers as $i => $row) {
            foreach (['min_amount', 'max_amount'] as $field) {
                if (is_array($row) && is_string($row[$field] ?? null) && $row[$field] !== '') {
                    $parsed = Money::parse($row[$field]);
                    if ($parsed !== null && ! str_contains($parsed, '.')) {
                        $tiers[$i][$field] = $parsed;
                    }
                }
            }
        }
        $this->merge(['tiers' => $tiers]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $rows = collect($this->input('tiers', []))->map(fn ($row, $i) => [...$row, '_key' => $i])
                ->sortBy(fn ($row) => (int) $row['min_amount'])->values();
            foreach ($rows as $i => $row) {
                $key = "tiers.{$row['_key']}";
                if (Percent::parse($row['rate_percent']) === null) {
                    $validator->errors()->add("{$key}.rate_percent", 'Nhập tỉ lệ từ 0 đến 100, tối đa 2 chữ số lẻ.');
                }
                $max = $row['max_amount'] ?? null;
                if ($max !== null && (int) $max <= (int) $row['min_amount']) {
                    $validator->errors()->add("{$key}.max_amount", '"Đến" phải lớn hơn "từ".');
                }
                if ($max === null && $i < $rows->count() - 1) {
                    $validator->errors()->add("{$key}.max_amount", 'Chỉ bậc cao nhất được để trống "đến".');
                }
                $prev = $rows[$i - 1] ?? null;
                if ($prev && ($prev['max_amount'] ?? null) !== null && (int) $row['min_amount'] < (int) $prev['max_amount']) {
                    $validator->errors()->add("{$key}.min_amount", 'Chồng lên bậc trước (đến '.Money::format($prev['max_amount']).' đ).');
                }
            }
        }];
    }

    /** Các bậc đã chuẩn hóa, xếp theo "từ". */
    public function tiers(): array
    {
        return collect($this->validated('tiers') ?? [])->map(fn ($row) => [
            'min_amount' => (int) $row['min_amount'],
            'max_amount' => isset($row['max_amount']) ? (int) $row['max_amount'] : null,
            'rate_percent' => Percent::parse($row['rate_percent']),
            'min_f1' => (int) $row['min_f1'],
        ])->sortBy('min_amount')->values()->all();
    }
}
