<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /** Túi lỗi riêng: trang Tài khoản có 2 form, lỗi đổi mật khẩu không làm form thông tin bị coi là "chưa lưu". */
    protected $errorBag = 'password';

    public function rules(): array
    {
        return [
            'current_password' => ['bail', 'required', 'string', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
