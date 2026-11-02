<?php

namespace App\Support;

/**
 * Đọc / hiển thị số tiền kiểu Việt Nam: "2.499.999,99" (chấm = nghìn, phẩy = phần lẻ).
 * Chấp nhận thêm "12,500,000" và "12500000".
 */
class Money
{
    /**
     * Chuỗi người dùng nhập → số dạng "2499999.99" để lưu.
     * Trả về null nếu không phải số (để validate báo lỗi thay vì âm thầm thành 0).
     */
    public static function parse(?string $input): ?string
    {
        $value = str_replace(' ', '', trim((string) $input));
        if ($value === '') {
            return '0';
        }

        // Phần lẻ: dấu phẩy cuối theo sau 1–2 chữ số ("1.234,5", "2.499.999,99")
        $decimals = '';
        if (preg_match('/^(.*),(\d{1,2})$/', $value, $m)) {
            [$value, $decimals] = [$m[1], $m[2]];
        }

        // Phần nguyên: chỉ còn chữ số và dấu phân cách nghìn (chấm hoặc phẩy)
        if (! preg_match('/^\d{1,3}([.,]?\d{3})*$|^\d+$/', $value)) {
            return null;
        }

        $integer = preg_replace('/\D/', '', $value);

        return $decimals === '' ? $integer : $integer.'.'.$decimals;
    }

    /** Số → "2.499.999,99"; số chẵn thì không hiện phần lẻ ("12.500.000"). */
    public static function format(float|int|string|null $amount): string
    {
        $amount = (float) $amount;
        $decimals = abs($amount - round($amount)) >= 0.005 ? 2 : 0;

        return number_format($amount, $decimals, ',', '.');
    }
}
