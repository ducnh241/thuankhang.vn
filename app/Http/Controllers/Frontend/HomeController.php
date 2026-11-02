<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        // Thanh điều hướng lấy từ menu "main" (quản lý ở backend); chưa có menu thì view dùng các mục mặc định
        return view('frontend.home', ['menu' => Menu::tree('main')]);
    }
}
