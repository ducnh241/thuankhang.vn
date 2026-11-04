<?php

namespace App\Http\Requests\Backend;

use App\Models\Member;
use App\Models\PickupPoint;
use App\Support\AffiliateSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Thêm / sửa điểm nhận hàng (A7). Chủ điểm: thành viên đang ở cấp VP tỉnh, chưa làm chủ điểm khác. Số điểm ĐANG BẬT mỗi
 * tỉnh không vượt "Số VP tối đa mỗi tỉnh" (Tham số chung; trống = không giới hạn).
 */
class PickupPointRequest extends FormRequest
{
    public function rules(): array
    {
        $point = $this->route('pickup_point');

        return [
            'name' => ['required', 'string', 'max:150'],
            'province' => ['required', 'string', Rule::in(array_keys(PickupPoint::provinces()))],
            'address' => ['required', 'string', 'max:300'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+().\s-]{8,30}$/'],
            'opening_hours' => ['nullable', 'string', 'max:150'],
            'owner_member_id' => ['nullable', 'integer', Rule::unique('pickup_points', 'owner_member_id')->ignore($point)->withoutTrashed()],
            'active' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-9999', 'max:9999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tên điểm', 'province' => 'tỉnh / thành', 'address' => 'địa chỉ', 'phone' => 'số điện thoại',
            'opening_hours' => 'giờ mở cửa', 'owner_member_id' => 'tài khoản VP', 'sort_order' => 'thứ tự',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nhập tên điểm nhận hàng.',
            'province.required' => 'Chọn tỉnh / thành.',
            'province.in' => 'Tỉnh / thành không hợp lệ.',
            'address.required' => 'Nhập địa chỉ.',
            'phone.regex' => 'Số điện thoại chỉ gồm số, dấu cách và các dấu + - . ( ).',
            'owner_member_id.unique' => 'Thành viên này đã là chủ một điểm nhận hàng khác.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->filled('owner_member_id')) {
                $owner = Member::with('level')->find((int) $this->input('owner_member_id'));
                if (! $owner) {
                    $validator->errors()->add('owner_member_id', 'Không tìm thấy thành viên.');
                } elseif (! $owner->level?->is_vp) {
                    $validator->errors()->add('owner_member_id', 'Chỉ gán được thành viên đang ở cấp VP tỉnh (hiện: '.$owner->level?->name.').');
                } elseif ($owner->isLocked()) {
                    $validator->errors()->add('owner_member_id', 'Thành viên này đang bị khóa.');
                }
            }

            $max = AffiliateSettings::get('max_vp_per_province');
            if ($max && $this->boolean('active')) {
                $point = $this->route('pickup_point');
                $count = PickupPoint::where('province', $this->input('province'))->where('active', true)
                    ->when($point, fn ($q) => $q->whereKeyNot($point->id))->count();
                if ($count >= $max) {
                    $validator->errors()->add('province', PickupPoint::provinces()[$this->input('province')]." đã có {$count} điểm đang bật (tối đa {$max} — Tham số chung). Tắt bớt điểm khác hoặc lưu điểm này ở trạng thái tắt.");
                }
            }
        }];
    }

    public function pointData(): array
    {
        $trim = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;

        return [
            'name' => trim($this->validated('name')),
            'province' => $this->validated('province'),
            'address' => trim($this->validated('address')),
            'phone' => $trim($this->validated('phone')),
            'opening_hours' => $trim($this->validated('opening_hours')),
            'owner_member_id' => $this->filled('owner_member_id') ? (int) $this->validated('owner_member_id') : null,
            'active' => $this->boolean('active'),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
        ];
    }
}
