<?php

namespace App\Support;

use App\Models\Order;

/**
 * Thông tin chuyển khoản hiện cho khách ở trang đơn (Cài đặt → tab Thanh toán) và mã QR:
 *   - công ty đã tải ảnh mã QR lên → dùng ảnh đó (mã tĩnh: khách tự nhập số tiền + nội dung);
 *   - chưa tải ảnh nhưng có mã ngân hàng + số tài khoản → tự tạo mã VietQR theo đơn (ảnh từ img.vietqr.io: số tiền =
 *     tổng đơn, nội dung = mã đơn).
 */
class VietQr
{
    /** ['bank_name', 'code', 'account', 'name', 'label' (tên ngân hàng để hiện: tên đã nhập, không có thì mã), 'qr_image' (URL ảnh đã tải)] */
    public static function bank(): array
    {
        $settings = SiteSettings::all();
        $text = fn (string $key) => $settings[$key] !== '' ? $settings[$key] : null;

        return [
            'bank_name' => $text('pay_bank_name'),
            'code' => $text('pay_bank_code'),
            'account' => $text('pay_bank_account'),
            'name' => $text('pay_account_name'),
            'label' => $text('pay_bank_name') ?? $text('pay_bank_code'),
            'qr_image' => SiteSettings::imageUrl($text('pay_qr')),
        ];
    }

    /** Đủ để khách chuyển khoản: có số tài khoản và biết ngân hàng nào (tên hoặc mã). */
    public static function configured(?array $bank = null): bool
    {
        $bank ??= self::bank();

        return (bool) $bank['account'] && (bool) $bank['label'];
    }

    /** Mã VietQR tự tạo theo đơn; null khi thiếu mã ngân hàng / số tài khoản. */
    public static function imageUrl(Order $order, ?array $bank = null): ?string
    {
        return self::imageUrlFor($order->total_amount, $order->code, $bank);
    }

    /** Mã VietQR tự tạo: số tiền + nội dung chuyển khoản (mã đơn / mã lệnh nạp ví); null khi thiếu mã ngân hàng / số tài khoản. */
    public static function imageUrlFor(int $amount, string $content, ?array $bank = null): ?string
    {
        $bank ??= self::bank();
        if (! $bank['code'] || ! $bank['account']) {
            return null;
        }

        return 'https://img.vietqr.io/image/'.rawurlencode($bank['code']).'-'.rawurlencode($bank['account']).'-compact2.png?'
            .http_build_query(array_filter(['amount' => $amount, 'addInfo' => $content, 'accountName' => $bank['name']]));
    }
}
