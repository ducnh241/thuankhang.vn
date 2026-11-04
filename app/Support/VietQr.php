<?php

namespace App\Support;

use App\Models\Order;

/**
 * Mã QR chuyển khoản theo chuẩn VietQR (ảnh từ img.vietqr.io): tài khoản công ty (Tham số chung), số tiền = tổng đơn,
 * nội dung = mã đơn. Chưa cấu hình ngân hàng / số tài khoản thì không có QR.
 */
class VietQr
{
    public static function bank(): array
    {
        return [
            'code' => AffiliateSettings::get('bank_code'),
            'account' => AffiliateSettings::get('bank_account'),
            'name' => AffiliateSettings::get('bank_account_name'),
        ];
    }

    public static function imageUrl(Order $order): ?string
    {
        $bank = self::bank();
        if (! $bank['code'] || ! $bank['account']) {
            return null;
        }

        return 'https://img.vietqr.io/image/'.rawurlencode($bank['code']).'-'.rawurlencode($bank['account']).'-compact2.png?'
            .http_build_query(array_filter(['amount' => $order->total_amount, 'addInfo' => $order->code, 'accountName' => $bank['name']]));
    }
}
