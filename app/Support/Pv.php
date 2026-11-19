<?php

namespace App\Support;

/**
 * Điểm PV hiển thị doanh số nhóm: 1 PV = 1.000.000 đ, làm tròn xuống, tối đa 2 số lẻ, bỏ số 0 thừa.
 * 50.000.000 → "50"; 14.900.000 → "14,9"; 14.155.000 → "14,15"; 9.999 → "0".
 */
class Pv
{
    public const UNIT = 1_000_000;

    public static function format(int $amount): string
    {
        $hundredths = intdiv(max(0, $amount), self::UNIT / 100);

        return rtrim(rtrim(number_format($hundredths / 100, 2, ',', '.'), '0'), ',');
    }
}
