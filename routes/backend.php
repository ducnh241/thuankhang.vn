<?php

// Route khu vực quản trị (/backend). Được nạp qua bootstrap/app.php với middleware group 'web'.

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Backend\CategoryController;
use App\Http\Controllers\Backend\CustomerController;
use App\Http\Controllers\Backend\DashboardController;
use App\Http\Controllers\Backend\GroupController;
use App\Http\Controllers\Backend\HostingController;
use App\Http\Controllers\Backend\MenuController;
use App\Http\Controllers\Backend\PageController;
use App\Http\Controllers\Backend\PaymentController;
use App\Http\Controllers\Backend\ProfileController;
use App\Http\Controllers\Backend\ProjectController;
use App\Http\Controllers\Backend\RenewalController;
use App\Http\Controllers\Backend\ReportController;
use App\Http\Controllers\Backend\SettingController;
use App\Http\Controllers\Backend\TagController;
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

        // Dự án (quyền: project, project/add, project/edit, project/delete)
        Route::get('project', [ProjectController::class, 'index'])->name('projects.index')->can('project');
        Route::get('project/create', [ProjectController::class, 'create'])->name('projects.create')->can('project/add');
        Route::post('project', [ProjectController::class, 'store'])->name('projects.store')->can('project/add');
        Route::get('project/{project}', [ProjectController::class, 'show'])->name('projects.show')->can('project')->whereNumber('project');
        Route::get('project/{project}/edit', [ProjectController::class, 'edit'])->name('projects.edit')->can('project/edit');
        Route::put('project/{project}', [ProjectController::class, 'update'])->name('projects.update')->can('project/edit');
        Route::delete('project/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy')->can('project/delete');

        // Báo cáo doanh thu (quyền: report)
        Route::get('report/revenue', [ReportController::class, 'revenue'])->name('reports.revenue')->can('report');

        // Nhắc gia hạn (quyền: renewal, renewal/renew, renewal/template)
        Route::get('renewal', [RenewalController::class, 'index'])->name('renewals.index')->can('renewal');
        Route::get('renewal/template', [RenewalController::class, 'editTemplate'])->name('renewals.template')->can('renewal/template');
        Route::put('renewal/template', [RenewalController::class, 'updateTemplate'])->name('renewals.template.update')->can('renewal/template');
        Route::post('renewal/{project}/renew', [RenewalController::class, 'renew'])->name('renewals.renew')->can('renewal/renew');
        Route::post('renewal/{project}/cancel', [RenewalController::class, 'cancel'])->name('renewals.cancel')->can('renewal/renew');

        // Tag dự án (quyền: tag, tag/add|edit|delete) — thêm/sửa trong hộp thoại ở trang danh sách
        Route::get('tag', [TagController::class, 'index'])->name('tags.index')->can('tag');
        Route::post('tag', [TagController::class, 'store'])->name('tags.store')->can('tag/add');
        Route::post('tag/bulk-destroy', [TagController::class, 'bulkDestroy'])->name('tags.bulk-destroy')->can('tag/delete');
        Route::put('tag/{tag}', [TagController::class, 'update'])->name('tags.update')->can('tag/edit');
        Route::delete('tag/{tag}', [TagController::class, 'destroy'])->name('tags.destroy')->can('tag/delete');

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

        // Hosting (quyền: hosting, hosting/add|edit|delete)
        Route::get('hosting', [HostingController::class, 'index'])->name('hostings.index')->can('hosting');
        Route::get('hosting/create', [HostingController::class, 'create'])->name('hostings.create')->can('hosting/add');
        Route::post('hosting', [HostingController::class, 'store'])->name('hostings.store')->can('hosting/add');
        Route::get('hosting/{hosting}/edit', [HostingController::class, 'edit'])->name('hostings.edit')->can('hosting/edit');
        Route::put('hosting/{hosting}', [HostingController::class, 'update'])->name('hostings.update')->can('hosting/edit');
        Route::delete('hosting/{hosting}', [HostingController::class, 'destroy'])->name('hostings.destroy')->can('hosting/delete');

        // Khách hàng (quyền: customer, customer/add|edit|delete)
        Route::get('customer', [CustomerController::class, 'index'])->name('customers.index')->can('customer');
        Route::get('customer/create', [CustomerController::class, 'create'])->name('customers.create')->can('customer/add');
        Route::post('customer', [CustomerController::class, 'store'])->name('customers.store')->can('customer/add');
        Route::post('customer/quick-create', [CustomerController::class, 'quickStore'])->name('customers.quick-store')->can('customer/add');
        Route::get('customer/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit')->can('customer/edit');
        Route::put('customer/{customer}', [CustomerController::class, 'update'])->name('customers.update')->can('customer/edit');
        Route::delete('customer/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy')->can('customer/delete');

        // Thanh toán (quyền: payment, payment/add|edit|delete)
        Route::get('payment', [PaymentController::class, 'index'])->name('payments.index')->can('payment');
        Route::post('payment', [PaymentController::class, 'store'])->name('payments.store')->can('payment/add');
        Route::post('payment/bulk-destroy', [PaymentController::class, 'bulkDestroy'])->name('payments.bulk-destroy')->can('payment/delete');
        Route::put('payment/{payment}', [PaymentController::class, 'update'])->name('payments.update')->can('payment/edit');
        Route::delete('payment/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy')->can('payment/delete');

        // Danh mục mọi loại trên một trang: /category?type=project&id=3 (?new: thêm mới) — Category::KINDS.
        // Gate 'category[/add|/edit|/delete]' nhận {kind} → quyền project_category/…, news_category/…; trang chỉ hiện
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
        Route::get('project-category', fn () => redirect()->route('backend.categories.index', ['type' => 'project'])); // URL cũ

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
    });
});
