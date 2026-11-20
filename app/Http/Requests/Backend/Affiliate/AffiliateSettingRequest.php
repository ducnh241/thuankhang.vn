<?php

namespace App\Http\Requests\Backend\Affiliate;

use App\Models\Member;
use App\Support\AffiliateSettings;
use App\Support\Money;
use App\Support\Percent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/** Tham số chung (A5): ràng buộc lấy từ AffiliateSettings::FIELDS (trừ các khóa sửa ở trang khác, nhóm null). */
class AffiliateSettingRequest extends FormRequest
{
    public static function fieldKeys(): array
    {
        return array_keys(array_filter(AffiliateSettings::FIELDS, fn ($field) => $field[0] !== null));
    }

    public function rules(): array
    {
        $rules = [];
        foreach (self::fieldKeys() as $key) {
            [, , $type, , $constraints] = AffiliateSettings::FIELDS[$key];
            $rules[$key] = [...$constraints, ...match ($type) {
                'percent' => [function (string $attribute, mixed $value, \Closure $fail) {
                    if (Percent::parse($value) === null) {
                        $fail('Nhập tỉ lệ từ 0 đến 100, tối đa 2 chữ số lẻ.');
                    }
                }],
                'money', 'int' => ['integer', ...($type === 'money' ? ['min:0'] : [])],
                default => ['string'],
            }];
        }

        return $rules;
    }

    public function attributes(): array
    {
        return collect(self::fieldKeys())->mapWithKeys(fn ($key) => [$key => mb_strtolower(AffiliateSettings::FIELDS[$key][1])])->all();
    }

    public function messages(): array
    {
        return [
            'root_member_code.regex' => 'Mã giới thiệu chỉ gồm chữ in hoa và số.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            // Thuế TNCN + ví tái tiêu dùng không vượt 100% hoa hồng (phần còn lại vào ví rút tiền)
            $split = array_map(fn ($key) => Percent::parse($this->input($key)), ['pit_percent', 'reconsume_percent']);
            if (! in_array(null, $split, true) && (float) $split[0] + (float) $split[1] > 100) {
                $validator->errors()->add('reconsume_percent', 'Thuế TNCN + ví tái tiêu dùng không được vượt quá 100%.');
            }

            // Ông tổng: mã phải là của một thành viên có thật và không có người giới thiệu (gốc của một cây)
            $code = $this->input('root_member_code');
            if (! $validator->errors()->has('root_member_code') && is_string($code) && $code !== '') {
                $root = Member::findByReferralCode($code);
                if (! $root) {
                    $validator->errors()->add('root_member_code', 'Không có thành viên nào có mã giới thiệu này.');
                } elseif ($root->referrer_id !== null) {
                    $validator->errors()->add('root_member_code', 'Thành viên này có người giới thiệu nên không thể là ông tổng (ông tổng đứng đầu cây).');
                }
            }
        }];
    }

    /** Tiền "500.000" → 500000; mã / tên tự in hoa, bỏ khoảng trắng thừa. */
    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (self::fieldKeys() as $key) {
            $value = $this->input($key);
            if (! is_string($value)) {
                continue;
            }
            $value = trim($value);
            $data[$key] = match (AffiliateSettings::FIELDS[$key][2]) {
                'money' => ($parsed = Money::parse($value)) !== null && ! str_contains($parsed, '.') && $value !== '' ? $parsed : ($value === '' ? null : $value),
                'code' => $value === '' ? null : Str::upper(str_replace(' ', '', $value)),
                'name' => $value === '' ? null : Str::upper(preg_replace('/\s+/u', ' ', $value)),
                default => $value === '' ? null : $value,
            };
        }
        $this->merge($data);
    }

    /** Giá trị đã chuẩn hóa để lưu (percent "5.00", số nguyên, chuỗi, null). */
    public function settings(): array
    {
        $values = [];
        foreach (self::fieldKeys() as $key) {
            $value = $this->validated($key);
            $values[$key] = match (AffiliateSettings::FIELDS[$key][2]) {
                'percent' => $value === null ? null : Percent::parse($value),
                'money', 'int' => $value === null ? null : (int) $value,
                default => $value,
            };
        }

        return $values;
    }
}
