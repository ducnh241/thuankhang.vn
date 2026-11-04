<?php

// Route website (cửa hàng). Khu vực quản trị nằm ở routes/backend.php. Mọi trang ghi nhớ ?ref=CODE (CaptureReferralCode).

use App\Http\Controllers\Frontend\AccountController;
use App\Http\Controllers\Frontend\Auth\LoginController;
use App\Http\Controllers\Frontend\Auth\RegisterController;
use App\Http\Controllers\Frontend\CartController;
use App\Http\Controllers\Frontend\CategoryController;
use App\Http\Controllers\Frontend\CheckoutController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\OrderController;
use App\Http\Controllers\Frontend\ProductController;
use App\Http\Controllers\Frontend\SearchController;
use App\Http\Middleware\CaptureReferralCode;
use App\Http\Middleware\EnsureMemberActive;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

Route::middleware(CaptureReferralCode::class)->group(function () {
    Route::get('/', HomeController::class)->name('home');
    // Đường dẫn khớp Category::KINDS['product']['path'] / Product::PATH (link menu, link trong admin)
    Route::get(Category::KINDS['product']['path'].'/{slug}', CategoryController::class)->name('shop.category');
    Route::get(Product::PATH.'/{slug}', ProductController::class)->name('shop.product');
    Route::get('tim-kiem', SearchController::class)->name('shop.search');

    // Giỏ hàng (session; khách chưa đăng nhập vẫn dùng được)
    Route::get('gio-hang', [CartController::class, 'index'])->name('shop.cart');
    Route::post('gio-hang', [CartController::class, 'store'])->name('shop.cart.store');
    Route::patch('gio-hang/{product}', [CartController::class, 'update'])->name('shop.cart.update')->whereNumber('product');
    Route::delete('gio-hang/{product}', [CartController::class, 'destroy'])->name('shop.cart.destroy')->whereNumber('product');

    // Thành viên (guard "member", bảng members — tách khỏi tài khoản quản trị)
    Route::middleware('guest:member')->group(function () {
        Route::get('dang-ky', [RegisterController::class, 'create'])->name('member.register');
        Route::post('dang-ky', [RegisterController::class, 'store'])->name('member.register.store')->middleware('throttle:10,1');
        Route::get('dang-nhap', [LoginController::class, 'create'])->name('member.login');
        Route::post('dang-nhap', [LoginController::class, 'store'])->name('member.login.store');
    });
    Route::middleware(['auth:member', EnsureMemberActive::class])->group(function () {
        Route::get('tai-khoan', AccountController::class)->name('member.account');
        Route::get('thanh-toan', [CheckoutController::class, 'show'])->name('shop.checkout');
        Route::post('thanh-toan', [CheckoutController::class, 'store'])->name('shop.checkout.store')->middleware('throttle:10,1');
        Route::get('don-hang', [OrderController::class, 'index'])->name('member.orders.index');
        Route::get('don-hang/{order}', [OrderController::class, 'show'])->name('member.orders.show');
        Route::post('dang-xuat', [LoginController::class, 'destroy'])->name('member.logout');
    });
});

// Tra mã giới thiệu (form đăng ký gọi khi gõ): chỉ trả tên rút gọn, giới hạn tần suất chống dò mã
Route::get('ma-gioi-thieu/{code}', [RegisterController::class, 'lookup'])->name('member.referrer')->middleware('throttle:30,1');
