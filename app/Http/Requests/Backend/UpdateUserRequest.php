<?php

namespace App\Http\Requests\Backend;

use App\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    /** Kiểm tra quyền quản lý tài khoản đích trước khi validate (trả 403 thay vì lỗi form). */
    public function authorize(): bool
    {
        return $this->user()->canManage($this->route('user'));
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'confirmed', Password::min(8)],
            'group_id' => ['bail', 'nullable', 'integer', 'exists:groups,id', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value && ! $this->user()->canAssignGroup(Group::find($value))) {
                    $fail('Bạn không được gán nhóm có quyền cao hơn quyền của mình.');
                }
            }],
            'status' => ['boolean'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['status' => $this->boolean('status')]);
    }
}
