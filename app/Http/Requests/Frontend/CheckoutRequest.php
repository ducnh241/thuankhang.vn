<?php

namespace App\Http\Requests\Frontend;

use App\Models\Member;
use App\Models\Order;
use App\Models\PickupPoint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Checkout (S5): người nhận, hình thức nhận hàng. Giao tận nơi → tỉnh + địa chỉ; nhận tại VP → điểm đang bật.
 */
class CheckoutRequest extends FormRequest
{
    public function rules(): array
    {
        $ship = $this->input('delivery_type') === Order::DELIVERY_SHIP;

        return [
            'recipient_name' => ['required', 'string', 'max:100'],
            'recipient_phone' => ['required', 'string', 'max:20'],
            'delivery_type' => ['required', Rule::in(array_keys(Order::DELIVERIES))],
            'province' => [Rule::requiredIf($ship), 'nullable', 'string', Rule::in(array_keys(PickupPoint::provinces()))],
            'address' => [Rule::requiredIf($ship), 'nullable', 'string', 'max:300'],
            'pickup_point_id' => [Rule::requiredIf(! $ship && $this->input('delivery_type') === Order::DELIVERY_PICKUP), 'nullable', 'integer'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipient_name.required' => 'Nhập tên người nhận.',
            'recipient_phone.required' => 'Nhập số điện thoại người nhận.',
            'delivery_type.required' => 'Chọn hình thức nhận hàng.',
            'delivery_type.in' => 'Chọn hình thức nhận hàng.',
            'province.required' => 'Chọn tỉnh / thành.',
            'province.in' => 'Tỉnh / thành không hợp lệ.',
            'address.required' => 'Nhập địa chỉ nhận hàng.',
            'pickup_point_id.required' => 'Chọn điểm nhận hàng.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['recipient_name', 'address', 'note'] as $field) {
            if (is_string($value = $this->input($field))) {
                $data[$field] = trim(preg_replace('/\s+/u', ' ', $value));
            }
        }
        if (is_string($phone = $this->input('recipient_phone')) && ($normalized = Member::normalizePhone($phone))) {
            $data['recipient_phone'] = $normalized;
        }
        $this->merge($data);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('recipient_phone') && Member::normalizePhone($this->input('recipient_phone')) === null) {
                $validator->errors()->add('recipient_phone', 'Số di động không hợp lệ (10 số, bắt đầu bằng 03, 05, 07, 08, 09).');
            }
            if ($this->input('delivery_type') === Order::DELIVERY_PICKUP && ! $validator->errors()->has('pickup_point_id')
                && ! PickupPoint::available()->whereKey((int) $this->input('pickup_point_id'))->exists()) {
                $validator->errors()->add('pickup_point_id', 'Điểm nhận hàng này không còn nhận đơn. Vui lòng chọn điểm khác.');
            }
        }];
    }
}
