<?php

// Route website (cửa hàng). Khu vực quản trị nằm ở routes/backend.php. Mọi trang ghi nhớ ?ref=CODE (CaptureReferralCode).

use App\Http\Controllers\Frontend\CategoryController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\ProductController;
use App\Http\Controllers\Frontend\SearchController;
use App\Http\Middleware\CaptureReferralCode;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::middleware(CaptureReferralCode::class)->group(function () {
    Route::get('/', HomeController::class)->name('home');
    // Đường dẫn khớp Category::KINDS['product']['path'] / Product::PATH (link menu, link trong admin)
    Route::get(Category::KINDS['product']['path'].'/{slug}', CategoryController::class)->name('shop.category');
    Route::get(Product::PATH.'/{slug}', ProductController::class)->name('shop.product');
    Route::get('tim-kiem', SearchController::class)->name('shop.search');
});
