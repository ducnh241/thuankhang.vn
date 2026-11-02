<?php

// Route frontend (website). Khu vực quản trị nằm ở routes/backend.php.

use App\Http\Controllers\Frontend\HomeController;
use Illuminate\Support\Facades\Route;

// Trang chủ: trang giới thiệu (chép từ nhánh main), thanh điều hướng lấy từ menu "main"
Route::get('/', HomeController::class)->name('home');
