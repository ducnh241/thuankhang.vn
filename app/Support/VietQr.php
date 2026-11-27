<?php

namespace App\Support;

use App\Models\Order;

/**
 * Thông tin chuyển khoản hiện cho khách ở trang đơn / lệnh nạp ví (Cài đặt → tab Thanh toán) và mã QR (27/11/2026):
 *   - có mã ngân hàng + số tài khoản → LUÔN tạo mã VietQR có sẵn đúng số tiền và nội dung (mã đơn / mã lệnh nạp) — ảnh từ
 *     img.vietqr.io; ảnh QR công ty đã tải lên (nếu có) làm dự phòng khi ảnh VietQR không tải được (JS đổi ảnh);
 *   - thiếu mã ngân hàng nhưng đã tải ảnh QR → dùng ảnh đó (mã tĩnh: khách tự nhập số tiền + nội dung).
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

    /**
     * Mã QR hiện cho khách: ['url', 'static' (true = ảnh tĩnh, khách tự nhập tiền), 'fallback' (ảnh tĩnh dự phòng khi ảnh
     * VietQR lỗi, hoặc null)]; null khi không có mã nào.
     */
    public static function qr(int $amount, string $content, ?array $bank = null): ?array
    {
        $bank ??= self::bank();
        if ($dynamic = self::imageUrlFor($amount, $content, $bank)) {
            return ['url' => $dynamic, 'static' => false, 'fallback' => $bank['qr_image']];
        }

        return $bank['qr_image'] ? ['url' => $bank['qr_image'], 'static' => true, 'fallback' => null] : null;
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
