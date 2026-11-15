<?php

namespace App\Http\Requests\Backend;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

class StoreUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'confirmed', User::passwordRule()],
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
