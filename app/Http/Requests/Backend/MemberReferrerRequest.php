<?php

namespace App\Http\Requests\Backend;

use App\Models\Member;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Sửa người giới thiệu của thành viên (A10, quyền member/referrer): nhập mã giới thiệu của người mới (để trống = không
 * có người giới thiệu), bắt buộc lý do. Chặn: chính mình, người trong tuyến dưới của mình (tạo vòng), tài khoản bị khóa.
 */
class MemberReferrerRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'referral_code' => ['nullable', 'string', 'max:20'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'reason.required' => 'Nhập lý do sửa người giới thiệu.',
            'reason.min' => 'Lý do quá ngắn (ít nhất 5 ký tự).',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($code = $this->input('referral_code'))) {
            $this->merge(['referral_code' => Str::upper(trim($code))]);
        }
        if (is_string($reason = $this->input('reason'))) {
            $this->merge(['reason' => trim($reason)]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || ! $this->filled('referral_code')) {
                return;
            }
            /** @var Member $member */
            $member = $this->route('member');
            $referrer = Member::findByReferralCode($this->input('referral_code'));
            $error = match (true) {
                ! $referrer => 'Không có thành viên nào có mã giới thiệu này.',
                $referrer->id === $member->id => 'Không thể tự giới thiệu chính mình.',
                $referrer->isLocked() => 'Thành viên có mã này đang bị khóa.',
                in_array($referrer->id, $member->downlineIds(), true) => 'Người này đang ở tuyến dưới của thành viên — chọn sẽ tạo vòng giới thiệu.',
                $referrer->id === $member->referrer_id => 'Đây đã là người giới thiệu hiện tại.',
                default => null,
            };
            if ($error) {
                $validator->errors()->add('referral_code', $error);
            }
        }];
    }

    public function newReferrer(): ?Member
    {
        return $this->filled('referral_code') ? Member::findByReferralCode($this->validated('referral_code')) : null;
    }
}
