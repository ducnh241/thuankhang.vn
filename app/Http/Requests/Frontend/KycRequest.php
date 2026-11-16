<?php

namespace App\Http\Requests\Frontend;

use App\Models\MemberKyc;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Hồ sơ KYC của thành viên (S10): họ tên theo CCCD, số CCCD (12 số), ngày sinh, ngân hàng + số tài khoản + tên chủ tài
 * khoản, ảnh 2 mặt CCCD. Tên chủ tài khoản phải trùng họ tên trên CCCD (so không dấu). 1 CCCD = 1 tài khoản = 1 số tài
 * khoản ngân hàng: CCCD / số tài khoản đang nằm trong hồ sơ chờ duyệt / đã duyệt của thành viên khác thì chặn.
 */
class KycRequest extends FormRequest
{
    public function rules(): array
    {
        $image = ['required', 'file', 'mimes:'.implode(',', MemberKyc::IMAGE_TYPES), 'max:'.MemberKyc::maxKilobytes(), 'dimensions:min_width=300,min_height=200'];

        return [
            'full_name' => ['required', 'string', 'max:100'],
            'id_number' => ['required', 'string', 'regex:/^\d{12}$/'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before:today'],
            'bank_code' => ['required', 'string', Rule::in(array_keys(config('banks')))],
            'bank_account_number' => ['required', 'string', 'regex:/^\d{6,20}$/'],
            'bank_account_name' => ['required', 'string', 'max:100'],
            'front_image' => $image,
            'back_image' => $image,
        ];
    }

    public function attributes(): array
    {
        return [
            'full_name' => 'họ tên', 'id_number' => 'số CCCD', 'birth_date' => 'ngày sinh', 'bank_code' => 'ngân hàng',
            'bank_account_number' => 'số tài khoản', 'bank_account_name' => 'tên chủ tài khoản',
            'front_image' => 'ảnh mặt trước CCCD', 'back_image' => 'ảnh mặt sau CCCD',
        ];
    }

    public function messages(): array
    {
        $size = MemberKyc::maxKilobytes() >= 1024 ? round(MemberKyc::maxKilobytes() / 1024, 1).' MB' : MemberKyc::maxKilobytes().' KB';
        $imageMessages = [];
        foreach (['front_image' => 'mặt trước', 'back_image' => 'mặt sau'] as $field => $side) {
            $imageMessages += [
                "$field.required" => "Chọn ảnh $side CCCD.",
                "$field.file" => "Ảnh $side CCCD tải lên không thành công. Hãy chọn lại ảnh.",
                "$field.uploaded" => "Ảnh $side CCCD tải lên không thành công (ảnh quá lớn?). Mỗi ảnh tối đa $size.",
                "$field.mimes" => "Ảnh $side CCCD phải là ảnh JPG, PNG hoặc WEBP.",
                "$field.max" => "Ảnh $side CCCD quá lớn. Mỗi ảnh tối đa $size.",
                "$field.dimensions" => "Ảnh $side CCCD quá nhỏ, không đọc được. Hãy chụp lại rõ hơn.",
            ];
        }

        return [
            'full_name.required' => 'Nhập họ tên theo CCCD.',
            'id_number.required' => 'Nhập số CCCD.',
            'id_number.regex' => 'Số CCCD gồm đúng 12 chữ số.',
            'birth_date.required' => 'Nhập ngày sinh.',
            'birth_date.date_format' => 'Ngày sinh không hợp lệ.',
            'birth_date.after' => 'Ngày sinh không hợp lệ.',
            'birth_date.before' => 'Ngày sinh không hợp lệ.',
            'bank_code.required' => 'Chọn ngân hàng.',
            'bank_code.in' => 'Chọn ngân hàng trong danh sách.',
            'bank_account_number.required' => 'Nhập số tài khoản.',
            'bank_account_number.regex' => 'Số tài khoản chỉ gồm chữ số (6–20 số).',
            'bank_account_name.required' => 'Nhập tên chủ tài khoản.',
            ...$imageMessages,
        ];
    }

    /** Gọn khoảng trắng; số CCCD / số tài khoản bỏ khoảng trắng, dấu chấm, gạch; tên chủ tài khoản in hoa không dấu. */
    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($name = $this->input('full_name'))) {
            $data['full_name'] = Str::squish($name);
        }
        foreach (['id_number', 'bank_account_number'] as $field) {
            if (is_string($value = $this->input($field))) {
                $data[$field] = preg_replace('/[\s.\-]/', '', $value);
            }
        }
        if (is_string($holder = $this->input('bank_account_name'))) {
            $data['bank_account_name'] = MemberKyc::normalizeName($holder);
        }
        if (is_string($bank = $this->input('bank_code'))) {
            $data['bank_code'] = Str::upper(trim($bank));
        }
        $this->merge($data);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            $errors = $validator->errors();
            $memberId = (int) $this->user('member')->id;

            if (! $errors->hasAny(['full_name', 'bank_account_name'])
                && MemberKyc::normalizeName($this->input('full_name')) !== MemberKyc::normalizeName($this->input('bank_account_name'))) {
                $errors->add('bank_account_name', 'Tên chủ tài khoản phải trùng với họ tên trên CCCD (tài khoản chính chủ).');
            }
            if (! $errors->has('id_number')
                && MemberKyc::heldByOthers($memberId)->where('id_number_hash', MemberKyc::hashIdNumber($this->input('id_number')))->exists()) {
                $errors->add('id_number', 'Số CCCD này đã được dùng để xác minh một tài khoản khác.');
            }
            if (! $errors->hasAny(['bank_code', 'bank_account_number'])
                && MemberKyc::heldByOthers($memberId)->where('bank_code', $this->input('bank_code'))->where('bank_account_number', $this->input('bank_account_number'))->exists()) {
                $errors->add('bank_account_number', 'Số tài khoản này đã được dùng để xác minh một tài khoản khác.');
            }
        }];
    }
}
