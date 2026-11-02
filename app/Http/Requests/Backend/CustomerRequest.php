<?php

namespace App\Http\Requests\Backend;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CustomerRequest extends FormRequest
{
    public function rules(): array
    {
        $current = $this->route('customer');

        return [
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string'],
            'referrer_customer_id' => [
                'nullable', 'integer',
                // KH còn hoạt động, hoặc đúng người giới thiệu hiện tại (kể cả đã xóa)
                Rule::exists('customers', 'id')->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $current?->referrer_customer_id)),
                function (string $attribute, mixed $value, \Closure $fail) use ($current) {
                    if (! $value || ! $current) {
                        return;
                    }
                    if ((int) $value === $current->id) {
                        $fail('Khách hàng không thể tự giới thiệu chính mình.');
                    } elseif (in_array((int) $value, Customer::downlineIds($current->id), true)) {
                        $fail('Không chọn được người này: họ đang được khách hàng này giới thiệu (sẽ tạo vòng lặp A → B → A).');
                    }
                },
            ],
        ];
    }

    public function attributes(): array
    {
        return ['name' => 'tên khách hàng', 'referrer_customer_id' => 'người giới thiệu', 'phone' => 'điện thoại', 'address' => 'địa chỉ'];
    }
}
