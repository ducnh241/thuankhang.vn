<?php

namespace App\Http\Requests\Backend;

use App\Models\Hosting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HostingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'ownership' => ['required', Rule::in(array_keys(Hosting::OWNERSHIPS))],
            'provider' => ['nullable', 'string', 'max:255'],
            'plan_name' => ['nullable', 'string', 'max:255'],
            'ip' => ['nullable', 'string', 'max:64'],
            'cpu' => ['nullable', 'integer', 'min:0', 'max:1024'],
            'ram_gb' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'disk_gb' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'register_date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01'],
            // (ngày đăng ký gửi sai kiểu — mảng — thì bỏ phép so, không để lỗi 500; ngày đăng ký đã tự báo lỗi)
            'expire_date' => ['nullable', 'date_format:Y-m-d', 'after:1900-01-01', 'before:2100-01-01', Rule::when(is_string($this->input('register_date')), 'after_or_equal:register_date')],
            'alert_days' => ['nullable', 'string', 'max:100', 'regex:/^\d{1,3}(,\d{1,3})*$/'],
            'is_active' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:5000'],
            'project_ids' => ['nullable', 'array'],
            'project_ids.*' => ['integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tên', 'ownership' => 'loại', 'provider' => 'nhà cung cấp', 'plan_name' => 'gói', 'ip' => 'IP',
            'cpu' => 'CPU', 'ram_gb' => 'RAM', 'disk_gb' => 'ổ cứng', 'register_date' => 'ngày đăng ký',
            'expire_date' => 'ngày hết hạn', 'alert_days' => 'mốc nhắc', 'is_active' => 'trạng thái', 'note' => 'ghi chú',
            'project_ids' => 'dự án', 'project_ids.*' => 'dự án',
        ];
    }

    public function messages(): array
    {
        return ['alert_days.regex' => 'Mốc nhắc là các số ngày cách nhau bằng dấu phẩy, ví dụ 30,15,7,1.'];
    }

    /** Mốc nhắc "30, 15,7" → "30,15,7"; RAM/ổ cứng nhận "1,5" (thập phân) và "1.000" (nghìn). */
    protected function prepareForValidation(): void
    {
        $merge = [];
        if (is_string($this->input('alert_days'))) {
            $merge['alert_days'] = preg_replace('/\s+/', '', $this->input('alert_days'));
        }
        foreach (['ram_gb', 'disk_gb'] as $field) {
            if (is_string($value = $this->input($field))) {
                $value = trim($value);
                // "1.000" / "2.048,5": dấu chấm chia nhóm 3 số là dấu nghìn (như ô tiền); "1.5" vẫn là 1,5
                if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
                    $value = str_replace('.', '', $value);
                }
                $merge[$field] = str_replace(',', '.', $value);
            }
        }
        $this->merge($merge);
    }

    /** Dữ liệu lưu vào bảng hostings (specs gom thành JSON, mốc nhắc sắp giảm dần, bỏ trùng). */
    public function hostingData(): array
    {
        $data = $this->safe()->except(['cpu', 'ram_gb', 'disk_gb', 'project_ids']);
        $data['specs'] = [
            'cpu' => (int) $this->validated('cpu'),
            'ram_gb' => (float) $this->validated('ram_gb'),
            'disk_gb' => (float) $this->validated('disk_gb'),
        ];
        $days = array_unique(array_filter(array_map('intval', explode(',', (string) ($data['alert_days'] ?? ''))), fn ($d) => $d > 0));
        rsort($days);
        $data['alert_days'] = $days ? implode(',', $days) : '';

        return $data;
    }
}
