<?php

namespace App\Support;

/**
 * Tỉ lệ % kiểu Việt Nam, tối đa 2 chữ số lẻ: nhập "10", "10,5" hoặc "10.5"; hiển thị "10,5".
 */
class Percent
{
    /** Chuỗi người dùng nhập → "10.50" để lưu; không hợp lệ (chữ, quá 2 số lẻ, ngoài 0–100) → null. */
    public static function parse(mixed $input): ?string
    {
        if (! is_string($input) && ! is_int($input) && ! is_float($input)) {
            return null;
        }
        $value = str_replace([' ', ','], ['', '.'], trim((string) $input));
        if (! preg_match('/^\d{1,3}(\.\d{1,2})?$/', $value) || (float) $value > 100) {
            return null;
        }

        return number_format((float) $value, 2, '.', '');
    }

    /** "10.50" → "10,5"; "20.00" → "20". */
    public static function format(float|int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');
    }
}
