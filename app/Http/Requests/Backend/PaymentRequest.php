<?php

namespace App\Http\Requests\Backend;

use App\Models\Payment;
use App\Support\Money;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentRequest extends FormRequest
{
    /** Lỗi để riêng một "túi" để trang biết mở lại đúng hộp thoại thanh toán. */
    protected $errorBag = 'payment';

    public function rules(): array
    {
        return [
            'project_id' => ['required', 'integer', Rule::exists('projects', 'id')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999999'],
            'type' => ['required', Rule::in(array_keys(Payment::TYPES))],
            'status' => ['required', Rule::in(array_keys(Payment::STATUSES))],
            'paid_at' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'method' => ['nullable', Rule::in(array_keys(Payment::METHODS))],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'project_id' => 'dự án', 'amount' => 'số tiền', 'type' => 'loại', 'status' => 'trạng thái',
            'paid_at' => $this->input('status') === 'unpaid' ? 'ngày phát sinh' : 'ngày thu', 'method' => 'phương thức', 'note' => 'ghi chú',
        ];
    }

    public function messages(): array
    {
        return ['project_id.required' => 'Vui lòng chọn dự án.'];
    }

    /**
     * Số tiền nhập kiểu "2.500.000" / "2.499.999,99". Chỉ loại Phát sinh được để "chưa thu" (loại khác luôn là đã thu);
     * khoản chưa thu chưa có phương thức thanh toán.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('amount');
        if (is_string($raw)) {
            $this->merge(['amount' => Money::parse($raw) ?? $raw]);
        }

        $unpaid = $this->input('status') === 'unpaid' && in_array($this->input('type'), Payment::UNPAID_TYPES, true);
        $this->merge(['status' => $unpaid ? 'unpaid' : 'paid'] + ($unpaid ? ['method' => null] : []));
    }

    /** Quay lại form kèm id khoản đang sửa để mở lại đúng hộp thoại. */
    protected function failedValidation(Validator $validator): void
    {
        session()->flash('payment_dialog', $this->route('payment')?->id ?? 'new');

        parent::failedValidation($validator);
    }
}
