<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /** Tham số query dạng chuỗi; ?q[]=x (mảng) coi như không có — ép (string) mảng làm trang lỗi 500. */
    protected function queryString(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? trim($value) : '';
    }
}
