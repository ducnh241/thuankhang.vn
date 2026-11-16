<?php

// Route khu vực quản trị (/backend). Được nạp qua bootstrap/app.php với middleware group 'web'.

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Backend\Affiliate\AffiliateSettingController;
use App\Http\Controllers\Backend\Affiliate\CommissionRateController;
use App\Http\Controllers\Backend\Affiliate\KpiTierController;
use App\Http\Controllers\Backend\Affiliate\LevelController;
use App\Http\Controllers\Backend\AuditLogController;
use App\Http\Controllers\Backend\CategoryController;
use App\Http\Controllers\Backend\DashboardController;
use App\Http\Controllers\Backend\FileController;
use App\Http\Controllers\Backend\GroupController;
use App\Http\Controllers\Backend\KycController;
use App\Http\Controllers\Backend\MemberController;
use App\Http\Controllers\Backend\MenuController;
use App\Http\Controllers\Backend\NotificationSettingController;
use App\Http\Controllers\Backend\OrderController;
use App\Http\Controllers\Backend\PageController;
use App\Http\Controllers\Backend\PickupPointController;
use App\Http\Controllers\Backend\ProductController;
use App\Http\Controllers\Backend\ProductReviewController;
use App\Http\Controllers\Backend\ProfileController;
use App\Http\Controllers\Backend\SettingController;
use App\Http\Controllers\Backend\UserController;
use App\Models\Category;
use App\Models\Menu;
use Illuminate\Support\Facades\Route;

// Khu vực quản trị: mọi thứ nằm dưới /backend.
// Khách chưa đăng nhập vào /backend/* sẽ bị đẩy về route 'login' (/backend/login),
// đăng nhập xong quay lại đúng URL ban đầu nhờ redirect()->intended().
Route::prefix('backend')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [LoginController::class, 'create'])->name('login');
        Route::post('login', [LoginController::class, 'store'])->name('login.store');
        // Bước 2 khi tài khoản bật xác thực 2 lớp: nhập mã từ ứng dụng xác thực / mã khôi phục
        Route::get('login/two-factor', [TwoFactorChallengeController::class, 'create'])->name('login.two-factor');
        Route::post('login/two-factor', [TwoFactorChallengeController::class, 'store'])->name('login.two-factor.store')->middleware('throttle:20,1');
    });

    Route::middleware(['auth', 'backend.access'])->name('backend.')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        // Tài khoản của chính mình
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
        // Xác thực 2 lớp của chính mình: bật (tạo khóa) → xác nhận bằng mã → tạo lại mã khôi phục / tắt (đều cần mật khẩu)
        Route::post('profile/two-factor', [ProfileController::class, 'enableTwoFactor'])->name('profile.two-factor.enable')->middleware('throttle:10,1');
        Route::post('profile/two-factor/confirm', [ProfileController::class, 'confirmTwoFactor'])->name('profile.two-factor.confirm')->middleware('throttle:10,1');
        Route::post('profile/two-factor/recovery-codes', [ProfileController::class, 'regenerateRecoveryCodes'])->name('profile.two-factor.recovery')->middleware('throttle:10,1');
        Route::delete('profile/two-factor', [ProfileController::class, 'disableTwoFactor'])->name('profile.two-factor.disable')->middleware('throttle:10,1');
        // Token API của chính tài khoản (dùng cho skill Claude / công cụ ngoài đăng sản phẩm)
        Route::post('profile/api-tokens', [ProfileController::class, 'createToken'])->name('profile.tokens.store')->middleware('throttle:10,1');
        Route::delete('profile/api-tokens/{token}', [ProfileController::class, 'revokeToken'])->name('profile.tokens.destroy')->whereNumber('token');

        // Cấu hình affiliate (A2–A5): mỗi trang một form, quyền affiliate_level|commission|kpi|setting (+ /edit)
        Route::prefix('affiliate')->name('affiliate.')->group(function () {
            Route::get('levels', [LevelController::class, 'index'])->name('levels.index')->can('affiliate_level');
            Route::put('levels', [LevelController::class, 'update'])->name('levels.update')->can('affiliate_level/edit');
            Route::get('commission', [CommissionRateController::class, 'index'])->name('commission.index')->can('affiliate_commission');
            Route::post('commission', [CommissionRateController::class, 'store'])->name('commission.store')->can('affiliate_commission/edit');
            Route::get('kpi', [KpiTierController::class, 'index'])->name('kpi.index')->can('affiliate_kpi');
            Route::put('kpi', [KpiTierController::class, 'update'])->name('kpi.update')->can('affiliate_kpi/edit');
            Route::get('settings', [AffiliateSettingController::class, 'edit'])->name('settings.edit')->can('affiliate_setting');
            Route::put('settings', [AffiliateSettingController::class, 'update'])->name('settings.update')->can('affiliate_setting/edit');
        });

        // Thành viên website (A10; quyền: member, member/lock, member/activate, member/referrer)
        Route::get('member', [MemberController::class, 'index'])->name('members.index')->can('member');
        Route::get('member/{member}', [MemberController::class, 'show'])->name('members.show')->can('member')->whereNumber('member');
        Route::post('member/{member}/lock', [MemberController::class, 'lock'])->name('members.lock')->can('member/lock');
        Route::post('member/{member}/activate', [MemberController::class, 'activate'])->name('members.activate')->can('member/activate');
        Route::put('member/{member}/referrer', [MemberController::class, 'updateReferrer'])->name('members.referrer')->can('member/referrer');

        // KYC thành viên (A11; quyền: kyc, kyc/view_document — xem ảnh CCCD, kyc/review — duyệt / từ chối)
        Route::get('kyc', [KycController::class, 'index'])->name('kyc.index')->can('kyc');
        Route::get('kyc/{kyc}', [KycController::class, 'show'])->name('kyc.show')->can('kyc')->whereNumber('kyc');
        Route::get('kyc/{kyc}/image/{side}', [KycController::class, 'image'])->name('kyc.image')->can('kyc/view_document')->whereNumber('kyc')->whereIn('side', ['front', 'back']);
        Route::post('kyc/{kyc}/approve', [KycController::class, 'approve'])->name('kyc.approve')->can('kyc/review')->whereNumber('kyc');
        Route::post('kyc/{kyc}/reject', [KycController::class, 'reject'])->name('kyc.reject')->can('kyc/review')->whereNumber('kyc');

        // Đơn hàng (A8; quyền: order, order/confirm_payment, order/status, order/cancel). URL theo mã đơn.
        Route::get('order', [OrderController::class, 'index'])->name('orders.index')->can('order');
        Route::get('order/{order}', [OrderController::class, 'show'])->name('orders.show')->can('order');
        Route::post('order/{order}/confirm-payment', [OrderController::class, 'confirmPayment'])->name('orders.confirm-payment')->can('order/confirm_payment');
        Route::post('order/{order}/reject-payment', [OrderController::class, 'rejectPayment'])->name('orders.reject-payment')->can('order/confirm_payment');
        Route::post('order/{order}/status', [OrderController::class, 'status'])->name('orders.status')->can('order/status');
        Route::post('order/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel')->can('order/cancel');

        // Đánh giá sản phẩm (quyền: review, review/moderate)
        Route::get('review', [ProductReviewController::class, 'index'])->name('reviews.index')->can('review');
        Route::post('review/{review}/visibility', [ProductReviewController::class, 'visibility'])->name('reviews.visibility')->can('review/moderate');

        // VP tỉnh / điểm nhận hàng (A7; quyền: pickup_point, pickup_point/add|edit|delete). Xóa = xóa mềm.
        Route::get('pickup-point', [PickupPointController::class, 'index'])->name('pickup-points.index')->can('pickup_point');
        Route::get('pickup-point/create', [PickupPointController::class, 'create'])->name('pickup-points.create')->can('pickup_point/add');
        Route::post('pickup-point', [PickupPointController::class, 'store'])->name('pickup-points.store')->can('pickup_point/add');
        Route::get('pickup-point/{pickup_point}/edit', [PickupPointController::class, 'edit'])->name('pickup-points.edit')->can('pickup_point/edit');
        Route::put('pickup-point/{pickup_point}', [PickupPointController::class, 'update'])->name('pickup-points.update')->can('pickup_point/edit');
        Route::delete('pickup-point/{pickup_point}', [PickupPointController::class, 'destroy'])->name('pickup-points.destroy')->can('pickup_point/delete');

        // Sản phẩm (quyền: product, product/add|edit|delete — chung với danh mục sản phẩm). Xóa = xóa mềm.
        Route::get('product', [ProductController::class, 'index'])->name('products.index')->can('product');
        Route::get('product/create', [ProductController::class, 'create'])->name('products.create')->can('product/add');
        Route::post('product', [ProductController::class, 'store'])->name('products.store')->can('product/add');
        Route::post('product/bulk-destroy', [ProductController::class, 'bulkDestroy'])->name('products.bulk-destroy')->can('product/delete');
        Route::get('product/{product}/edit', [ProductController::class, 'edit'])->name('products.edit')->can('product/edit');
        Route::put('product/{product}', [ProductController::class, 'update'])->name('products.update')->can('product/edit');
        Route::delete('product/{product}', [ProductController::class, 'destroy'])->name('products.destroy')->can('product/delete');

        // Trang nội dung website (quyền: page, page/add|edit|delete) — đường dẫn trên website /page/{slug}
        Route::get('page', [PageController::class, 'index'])->name('pages.index')->can('page');
        Route::get('page/create', [PageController::class, 'create'])->name('pages.create')->can('page/add');
        Route::post('page', [PageController::class, 'store'])->name('pages.store')->can('page/add');
        Route::get('page/{page}/edit', [PageController::class, 'edit'])->name('pages.edit')->can('page/edit');
        Route::put('page/{page}', [PageController::class, 'update'])->name('pages.update')->can('page/edit');
        Route::delete('page/{page}', [PageController::class, 'destroy'])->name('pages.destroy')->can('page/delete');

        // Quản lý file tải lên (quyền: file, file/upload, file/delete) — ảnh / tài liệu lấy link dùng ở trang, mô tả sản phẩm
        Route::get('file', [FileController::class, 'index'])->name('files.index')->can('file');
        Route::post('file', [FileController::class, 'store'])->name('files.store')->can('file/upload');
        // Ảnh chèn từ trình soạn thảo: ai soạn được sản phẩm / trang / cài đặt đều gọi được (controller kiểm FileUpload::EDITOR_ABILITIES)
        Route::post('file/editor-image', [FileController::class, 'editorImage'])->name('files.editor-image')->middleware('throttle:60,1');
        Route::post('file/bulk-destroy', [FileController::class, 'bulkDestroy'])->name('files.bulk-destroy')->can('file/delete');
        Route::delete('file/{file}', [FileController::class, 'destroy'])->name('files.destroy')->can('file/delete')->whereNumber('file');

        // Menu website (quyền: menu, menu/add|edit|delete): vị trí cố định trong code (Menu::POSITIONS), một trang cây
        // + form như Danh mục — /menu?position=main&id=3 (&new: thêm mới); kéo thả: POST menu/item/{item}/move
        Route::get('menu', [MenuController::class, 'index'])->name('menus.index')->can('menu');
        Route::post('menu/{position}/item', [MenuController::class, 'store'])->name('menus.store')->can('menu/add')
            ->whereIn('position', array_keys(Menu::POSITIONS));
        Route::put('menu/item/{item}', [MenuController::class, 'update'])->name('menus.update')->can('menu/edit');
        Route::post('menu/item/{item}/move', [MenuController::class, 'move'])->name('menus.move')->can('menu/edit');
        Route::delete('menu/item/{item}', [MenuController::class, 'destroy'])->name('menus.destroy')->can('menu/delete');
        // URL cũ (tạo / sửa cả menu) → trang mới
        Route::get('menu/create', [MenuController::class, 'legacy'])->can('menu');
        Route::get('menu/{menu}/edit', [MenuController::class, 'legacy'])->can('menu');

        // Cài đặt website (quyền: setting, setting/edit) — một form, 5 tab (?tab=general|payment|social|seo|tracking)
        Route::get('setting', [SettingController::class, 'edit'])->name('settings.edit')->can('setting');
        Route::put('setting', [SettingController::class, 'update'])->name('settings.update')->can('setting/edit');
        // Tab "Thông báo" của Cài đặt: Telegram + email báo đơn hàng cho kế toán (sửa / gửi thử: thêm điều kiện Root admin)
        Route::get('setting/notification', [NotificationSettingController::class, 'edit'])->name('settings.notification.edit')->can('setting');
        Route::put('setting/notification', [NotificationSettingController::class, 'update'])->name('settings.notification.update')->can('setting/edit');
        Route::post('setting/notification/test/{channel}', [NotificationSettingController::class, 'test'])->name('settings.notification.test')
            ->can('setting/edit')->whereIn('channel', ['telegram', 'mail'])->middleware('throttle:6,1');

        // Danh mục mọi loại trên một trang: /category?type=project&id=3 (?new: thêm mới) — Category::KINDS.
        // Gate 'category[/add|/edit|/delete]' nhận {kind} → quyền news_category/…; trang chỉ hiện
        // các loại được xem (controller kiểm). Kéo thả trên cây: POST …/move.
        Route::get('category', [CategoryController::class, 'index'])->name('categories.index');
        Route::whereIn('kind', array_keys(Category::KINDS))->group(function () {
            Route::post('category/{kind}', [CategoryController::class, 'store'])->name('categories.store')->can('category/add', 'kind');
            Route::put('category/{kind}/{category}', [CategoryController::class, 'update'])->name('categories.update')->can('category/edit', 'kind');
            Route::post('category/{kind}/{category}/move', [CategoryController::class, 'move'])->name('categories.move')->can('category/edit', 'kind');
            Route::delete('category/{kind}/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy')->can('category/delete', 'kind');
            // URL cũ (trang riêng từng loại, trang thêm / sửa) → trang gộp
            Route::get('category/{kind}', [CategoryController::class, 'legacy'])->name('categories.kind')->can('category', 'kind');
            Route::get('category/{kind}/create', [CategoryController::class, 'legacy'])->name('categories.create')->can('category/add', 'kind');
            Route::get('category/{kind}/{category}/edit', [CategoryController::class, 'legacy'])->name('categories.edit')->can('category/edit', 'kind');
        });

        // Người dùng (quyền: user, user/add, user/edit, user/delete)
        Route::get('users', [UserController::class, 'index'])->name('users.index')->can('user');
        Route::get('users/create', [UserController::class, 'create'])->name('users.create')->can('user/add');
        Route::post('users', [UserController::class, 'store'])->name('users.store')->can('user/add');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit')->can('user/edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update')->can('user/edit');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy')->can('user/delete');
        // Người dùng mất thiết bị xác thực: người quản lý được tài khoản đó tắt xác thực 2 lớp giúp
        Route::delete('users/{user}/two-factor', [UserController::class, 'resetTwoFactor'])->name('users.two-factor.reset')->can('user/edit');

        // Nhóm quyền (quyền: group, group/add, group/edit, group/delete)
        Route::get('groups', [GroupController::class, 'index'])->name('groups.index')->can('group');
        Route::get('groups/create', [GroupController::class, 'create'])->name('groups.create')->can('group/add');
        Route::post('groups', [GroupController::class, 'store'])->name('groups.store')->can('group/add');
        Route::get('groups/{group}/edit', [GroupController::class, 'edit'])->name('groups.edit')->can('group/edit');
        Route::put('groups/{group}', [GroupController::class, 'update'])->name('groups.update')->can('group/edit');
        Route::delete('groups/{group}', [GroupController::class, 'destroy'])->name('groups.destroy')->can('group/delete');

        // Nhật ký hệ thống (quyền: audit_log) — chỉ xem; bảng audit_logs chỉ ghi thêm
        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-logs.index')->can('audit_log');
    });
});
