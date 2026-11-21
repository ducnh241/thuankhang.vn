<?php

// Route website (cửa hàng). Khu vực quản trị nằm ở routes/backend.php. Mọi trang ghi nhớ ?ref=CODE (CaptureReferralCode).

use App\Http\Controllers\Frontend\AccountController;
use App\Http\Controllers\Frontend\Auth\LoginController;
use App\Http\Controllers\Frontend\Auth\RegisterController;
use App\Http\Controllers\Frontend\CartController;
use App\Http\Controllers\Frontend\CategoryController;
use App\Http\Controllers\Frontend\CheckoutController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\KycController;
use App\Http\Controllers\Frontend\ManifestController;
use App\Http\Controllers\Frontend\OrderController;
use App\Http\Controllers\Frontend\ProductController;
use App\Http\Controllers\Frontend\ProductReviewController;
use App\Http\Controllers\Frontend\ProfileController;
use App\Http\Controllers\Frontend\SearchController;
use App\Http\Controllers\Frontend\TeamController;
use App\Http\Controllers\Frontend\WalletActionController;
use App\Http\Controllers\Frontend\WalletController;
use App\Http\Controllers\Frontend\WalletTopupController;
use App\Http\Middleware\CaptureReferralCode;
use App\Http\Middleware\EnsureMemberActive;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

// Web App Manifest: cho phép thêm website vào màn hình chính và mở như ứng dụng riêng (iOS / Android)
Route::get('manifest.webmanifest', ManifestController::class)->name('pwa.manifest');

Route::middleware(CaptureReferralCode::class)->group(function () {
    Route::get('/', HomeController::class)->name('home');
    // Đường dẫn khớp Category::KINDS['product']['path'] / Product::PATH (link menu, link trong admin)
    Route::get(Category::KINDS['product']['path'].'/{slug}', CategoryController::class)->name('shop.category');
    Route::get(Product::PATH.'/{slug}', ProductController::class)->name('shop.product');
    Route::get('tim-kiem', SearchController::class)->name('shop.search');

    // Giỏ hàng (session; khách chưa đăng nhập vẫn dùng được)
    Route::get('gio-hang', [CartController::class, 'index'])->name('shop.cart');
    Route::post('gio-hang', [CartController::class, 'store'])->name('shop.cart.store');
    // {line} = khóa dòng giỏ: "productId" hoặc "productId:variantId"
    Route::patch('gio-hang/{line}', [CartController::class, 'update'])->name('shop.cart.update')->where('line', '\d+(:\d+)?');
    Route::delete('gio-hang/{line}', [CartController::class, 'destroy'])->name('shop.cart.destroy')->where('line', '\d+(:\d+)?');

    // Thành viên (guard "member", bảng members — tách khỏi tài khoản quản trị)
    Route::middleware('guest:member')->group(function () {
        Route::get('dang-ky', [RegisterController::class, 'create'])->name('member.register');
        Route::post('dang-ky', [RegisterController::class, 'store'])->name('member.register.store')->middleware('throttle:10,1');
        Route::get('dang-nhap', [LoginController::class, 'create'])->name('member.login');
        Route::post('dang-nhap', [LoginController::class, 'store'])->name('member.login.store');
    });
    Route::middleware(['auth:member', EnsureMemberActive::class])->group(function () {
        Route::get('tai-khoan', AccountController::class)->name('member.account');
        // Thành viên trực tiếp (F1) của tôi: họ tên + cấp hiện tại
        Route::get('tai-khoan/thanh-vien', TeamController::class)->name('member.team');
        Route::get('tai-khoan/vi', WalletController::class)->name('member.wallet');
        // Ví tái tiêu dùng: lịch sử giao dịch, chuyển tiền cho thành viên khác (mã giới thiệu + mật khẩu, cần KYC), chuyển
        // từ ví rút tiền sang (một chiều), nạp tiền (QR công ty + bill → kế toán xác nhận)
        Route::get('tai-khoan/vi/giao-dich', [WalletActionController::class, 'history'])->name('member.wallet.history');
        Route::get('tai-khoan/vi/chuyen-tien', [WalletActionController::class, 'transferForm'])->name('member.wallet.transfer');
        Route::post('tai-khoan/vi/chuyen-tien/xac-nhan', [WalletActionController::class, 'transferPreview'])->name('member.wallet.transfer.preview')->middleware('throttle:20,1');
        Route::post('tai-khoan/vi/chuyen-tien', [WalletActionController::class, 'transfer'])->name('member.wallet.transfer.store')->middleware('throttle:10,1');
        Route::get('tai-khoan/vi/chuyen-vi', [WalletActionController::class, 'convertForm'])->name('member.wallet.convert');
        Route::post('tai-khoan/vi/chuyen-vi', [WalletActionController::class, 'convert'])->name('member.wallet.convert.store')->middleware('throttle:10,1');
        Route::get('tai-khoan/vi/nap-tien', [WalletTopupController::class, 'index'])->name('member.topups.index');
        Route::post('tai-khoan/vi/nap-tien', [WalletTopupController::class, 'store'])->name('member.topups.store')->middleware('throttle:10,1');
        Route::get('tai-khoan/vi/nap-tien/{topup}', [WalletTopupController::class, 'show'])->name('member.topups.show');
        Route::post('tai-khoan/vi/nap-tien/{topup}/da-chuyen-khoan', [WalletTopupController::class, 'transferred'])->name('member.topups.transferred')->middleware('throttle:10,1');
        Route::get('tai-khoan/vi/nap-tien/{topup}/bill', [WalletTopupController::class, 'bill'])->name('member.topups.bill');
        Route::post('tai-khoan/vi/nap-tien/{topup}/huy', [WalletTopupController::class, 'cancel'])->name('member.topups.cancel')->middleware('throttle:10,1');
        // Sửa thông tin tài khoản (họ tên, ảnh mã QR nhận tiền)
        Route::get('tai-khoan/thong-tin', [ProfileController::class, 'edit'])->name('member.profile.edit');
        Route::put('tai-khoan/thong-tin', [ProfileController::class, 'update'])->name('member.profile.update')->middleware('throttle:10,1');
        Route::get('tai-khoan/ma-qr', [ProfileController::class, 'qr'])->name('member.qr');
        // KYC (S10): form + trạng thái; ảnh CCCD của chính mình (side = front | back)
        Route::get('tai-khoan/kyc', [KycController::class, 'show'])->name('member.kyc');
        Route::post('tai-khoan/kyc', [KycController::class, 'store'])->name('member.kyc.store')->middleware('throttle:10,1');
        Route::get('tai-khoan/kyc/anh/{side}', [KycController::class, 'image'])->name('member.kyc.image')->whereIn('side', ['front', 'back']);
        Route::get('thanh-toan', [CheckoutController::class, 'show'])->name('shop.checkout');
        Route::post('thanh-toan', [CheckoutController::class, 'store'])->name('shop.checkout.store')->middleware('throttle:10,1');
        Route::post(Product::PATH.'/{slug}/danh-gia', [ProductReviewController::class, 'store'])->name('shop.product.review')->middleware('throttle:10,1');
        Route::get('don-hang', [OrderController::class, 'index'])->name('member.orders.index');
        Route::get('don-hang/{order}', [OrderController::class, 'show'])->name('member.orders.show');
        Route::post('don-hang/{order}/da-chuyen-khoan', [OrderController::class, 'confirmTransfer'])->name('member.orders.transferred')->middleware('throttle:10,1');
        Route::get('don-hang/{order}/bill', [OrderController::class, 'bill'])->name('member.orders.bill');
        Route::post('don-hang/{order}/huy', [OrderController::class, 'cancel'])->name('member.orders.cancel')->middleware('throttle:10,1');
        Route::post('dang-xuat', [LoginController::class, 'destroy'])->name('member.logout');
    });
});

// Tra mã giới thiệu (form đăng ký gọi khi gõ): chỉ trả tên rút gọn, giới hạn tần suất chống dò mã
Route::get('ma-gioi-thieu/{code}', [RegisterController::class, 'lookup'])->name('member.referrer')->middleware('throttle:30,1');
