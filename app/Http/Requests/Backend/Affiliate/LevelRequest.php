<?php

namespace App\Http\Requests\Backend\Affiliate;

use App\Models\Level;
use App\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Lưu cả bảng cấp bậc một lần (A2). Ràng buộc (đặc tả 6.2): tên, thứ tự duy nhất; xếp theo thứ tự thì mốc tăng dần
 * ngặt, cấp đầu tiên mốc 0 (cấp gốc, luôn bật, không là VP). Cấp đã có không xóa được — chỉ tắt.
 */
class LevelRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'levels' => ['required', 'array', 'min:2', 'max:30'],
            'levels.*' => ['array'],
            'levels.*.id' => ['nullable', 'integer'],
            'levels.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'levels.*.sort_order' => ['required', 'integer', 'min:0', 'max:999', 'distinct'],
            'levels.*.threshold_amount' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'levels.*.is_vp' => ['nullable', 'boolean'],
            'levels.*.active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'levels' => 'bảng cấp bậc', 'levels.*.name' => 'tên cấp', 'levels.*.sort_order' => 'thứ tự', 'levels.*.threshold_amount' => 'mốc doanh số',
        ];
    }

    public function messages(): array
    {
        return [
            'levels.min' => 'Cần ít nhất 2 cấp (cấp gốc và một cấp hưởng hoa hồng).',
            'levels.*.name.distinct' => 'Tên cấp bị trùng.',
            'levels.*.sort_order.distinct' => 'Thứ tự bị trùng.',
            'levels.*.threshold_amount.integer' => 'Mốc doanh số là số tiền nguyên (đồng).',
        ];
    }

    /** Ô tiền "3.000.000" → 3000000; không đọc được thì giữ nguyên để rule integer báo lỗi. */
    protected function prepareForValidation(): void
    {
        $levels = $this->input('levels');
        if (! is_array($levels)) {
            return;
        }
        foreach ($levels as $i => $row) {
            if (is_array($row) && is_string($row['threshold_amount'] ?? null) && trim($row['threshold_amount']) !== '') {
                $parsed = Money::parse($row['threshold_amount']);
                if ($parsed !== null && ! str_contains($parsed, '.')) {
                    $levels[$i]['threshold_amount'] = $parsed;
                }
            }
        }
        $this->merge(['levels' => $levels]);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $rows = collect($this->input('levels'))->map(fn ($row, $i) => [...$row, '_key' => $i]);

            // Mọi cấp đang có phải còn trong form (không xóa cấp), id lạ bị chặn
            $existing = Level::pluck('name', 'id');
            $ids = $rows->pluck('id')->filter()->map(fn ($id) => (int) $id);
            foreach ($rows as $row) {
                if (! empty($row['id']) && ! $existing->has((int) $row['id'])) {
                    $validator->errors()->add("levels.{$row['_key']}.name", 'Cấp này không còn tồn tại, tải lại trang.');
                }
            }
            if ($ids->duplicates()->isNotEmpty() || $existing->keys()->diff($ids)->isNotEmpty()) {
                $validator->errors()->add('levels', 'Không xóa được cấp đã có — tắt cấp thay vì xóa. Tải lại trang để xem đủ các cấp.');
            }

            // Theo thứ tự: cấp đầu mốc 0, các cấp sau mốc lớn hơn cấp trước
            $sorted = $rows->sortBy(fn ($row) => (int) $row['sort_order'])->values();
            $first = $sorted->first();
            if ((int) $first['threshold_amount'] !== 0) {
                $validator->errors()->add("levels.{$first['_key']}.threshold_amount", 'Cấp đầu tiên (thứ tự nhỏ nhất) là cấp gốc "Chưa kích hoạt", mốc phải bằng 0.');
            } else {
                if (! filter_var($first['active'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $validator->errors()->add("levels.{$first['_key']}.active", 'Cấp gốc luôn bật.');
                }
                if (filter_var($first['is_vp'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    $validator->errors()->add("levels.{$first['_key']}.is_vp", 'Cấp gốc không thể là cấp VP tỉnh.');
                }
            }
            foreach ($sorted->slice(1) as $i => $row) {
                $prev = $sorted[$i - 1];
                if ((int) $row['threshold_amount'] <= (int) $prev['threshold_amount']) {
                    $validator->errors()->add("levels.{$row['_key']}.threshold_amount",
                        'Mốc phải lớn hơn mốc của cấp trước “'.$prev['name'].'” ('.Money::format($prev['threshold_amount']).' đ).');
                }
            }
            // Step 3: chặn tắt cấp đang có thành viên (đặc tả 6.2) khi đã có bảng thành viên.
        }];
    }

    /** Các dòng đã chuẩn hóa: [id|null, name, sort_order, threshold_amount, is_vp, active]. */
    public function levels(): array
    {
        return collect($this->validated('levels'))->map(fn ($row) => [
            'id' => empty($row['id']) ? null : (int) $row['id'],
            'name' => trim($row['name']),
            'sort_order' => (int) $row['sort_order'],
            'threshold_amount' => (int) $row['threshold_amount'],
            'is_vp' => (bool) ($row['is_vp'] ?? false),
            'active' => (bool) ($row['active'] ?? false),
        ])->sortBy('sort_order')->values()->all();
    }
}
