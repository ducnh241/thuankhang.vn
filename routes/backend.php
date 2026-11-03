<?php

// Route khu vực quản trị (/backend). Được nạp qua bootstrap/app.php với middleware group 'web'.

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Backend\Affiliate\AffiliateSettingController;
use App\Http\Controllers\Backend\Affiliate\CommissionRateController;
use App\Http\Controllers\Backend\Affiliate\KpiTierController;
use App\Http\Controllers\Backend\Affiliate\LevelController;
use App\Http\Controllers\Backend\AuditLogController;
use App\Http\Controllers\Backend\CategoryController;
use App\Http\Controllers\Backend\DashboardController;
use App\Http\Controllers\Backend\GroupController;
use App\Http\Controllers\Backend\MenuController;
use App\Http\Controllers\Backend\PageController;
use App\Http\Controllers\Backend\ProductController;
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
    });

    Route::middleware(['auth', 'backend.access'])->name('backend.')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

        // Tài khoản của chính mình
        Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

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

        // Cài đặt website (quyền: setting, setting/edit) — một form, 4 tab (?tab=general|social|seo|tracking)
        Route::get('setting', [SettingController::class, 'edit'])->name('settings.edit')->can('setting');
        Route::put('setting', [SettingController::class, 'update'])->name('settings.update')->can('setting/edit');

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
