<?php

namespace App\Http\Requests\Frontend;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\Validator;

/**
 * Đăng ký thành viên (S3): họ tên, số di động (tên đăng nhập), email (tuỳ chọn), mật khẩu, mã người giới thiệu
 * (tuỳ chọn — điền sẵn từ cookie ?ref=). Mã phải là của thành viên đang hoạt động.
 */
class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', Rule::unique('members', 'phone')],
            'email' => ['nullable', 'string', 'email', 'max:150', Rule::unique('members', 'email')],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
            'referral_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function attributes(): array
    {
        return ['full_name' => 'họ tên', 'phone' => 'số điện thoại', 'email' => 'email', 'password' => 'mật khẩu', 'referral_code' => 'mã người giới thiệu'];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'Nhập họ tên.',
            'phone.required' => 'Nhập số điện thoại.',
            'phone.unique' => 'Số điện thoại này đã đăng ký. Hãy đăng nhập.',
            'email.unique' => 'Email này đã được dùng cho tài khoản khác.',
            'password.required' => 'Nhập mật khẩu.',
            'password.confirmed' => 'Mật khẩu nhập lại không khớp.',
        ];
    }

    /** Số điện thoại chuẩn hóa (0xxxxxxxxx) trước khi kiểm tra trùng; họ tên gọn khoảng trắng; email chữ thường. */
    protected function prepareForValidation(): void
    {
        $data = [];
        if (is_string($phone = $this->input('phone')) && ($normalized = Member::normalizePhone($phone))) {
            $data['phone'] = $normalized;
        }
        if (is_string($name = $this->input('full_name'))) {
            $data['full_name'] = trim(preg_replace('/\s+/u', ' ', $name));
        }
        if (is_string($email = $this->input('email'))) {
            $data['email'] = trim($email) === '' ? null : Str::lower(trim($email));
        }
        if (is_string($code = $this->input('referral_code'))) {
            $data['referral_code'] = Str::upper(trim($code));
        }
        $this->merge($data);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('phone') && Member::normalizePhone($this->input('phone')) === null) {
                $validator->errors()->add('phone', 'Số di động không hợp lệ (10 số, bắt đầu bằng 03, 05, 07, 08, 09).');
            }
            if (! $validator->errors()->has('referral_code') && $this->filled('referral_code')) {
                $referrer = Member::findByReferralCode($this->input('referral_code'));
                if (! $referrer) {
                    $validator->errors()->add('referral_code', 'Mã giới thiệu không tồn tại. Kiểm tra lại hoặc để trống.');
                } elseif ($referrer->isLocked()) {
                    $validator->errors()->add('referral_code', 'Mã giới thiệu này đang tạm khóa. Hãy dùng mã khác hoặc để trống.');
                }
            }
        }];
    }
}
