<?php

namespace App\Providers;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\User;
use App\Observers\SystemAuditObserver;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerPermissionGates();
        // {user} trên URL (sửa / lưu / xóa người dùng): tài khoản ẩn coi như không tồn tại → 404, kể cả Root admin
        Route::bind('user', fn (string $value) => User::visible()->whereKey($value)->firstOrFail());
        Paginator::defaultView('vendor.pagination.backend');

        $this->registerSystemAudit();
    }

    /**
     * Nhật ký hệ thống (audit_logs): thay đổi trên mọi model quản trị (AuditLog::SUBJECTS) + đăng nhập / đăng xuất.
     * Model mới của các step sau: thêm vào AuditLog::SUBJECTS là được ghi log.
     */
    protected function registerSystemAudit(): void
    {
        foreach (array_keys(AuditLog::SUBJECTS) as $model) {
            $model::observe(SystemAuditObserver::class);
        }

        Event::listen(Login::class, fn (Login $event) => $event->user instanceof User
            && AuditLog::record('login', $event->user, null, $event->remember ? 'Ghi nhớ đăng nhập' : null, $event->user));
        Event::listen(Logout::class, fn (Logout $event) => $event->user instanceof User
            && AuditLog::record('logout', $event->user, null, null, $event->user));
        // Người thử chưa đăng nhập: không gắn người thực hiện; chỉ ghi email đã gõ, không ghi mật khẩu.
        // Đối tượng = tài khoản có email đó (nếu có) để lọc được "ai đang bị dò mật khẩu".
        Event::listen(Failed::class, fn (Failed $event) => AuditLog::record(
            'login_failed', $event->user instanceof User ? $event->user : null, null,
            'Email: '.Str::limit((string) ($event->credentials['email'] ?? ''), 255),
        ));
    }

    /**
     * Sinh Gate từ config/permission.php: 'post', 'post/add', ...
     * Root admin pass mọi Gate.
     */
    protected function registerPermissionGates(): void
    {
        Gate::before(fn (User $user) => $user->isSuperAdmin() ? true : null);

        foreach (config('permission') as $module => $config) {
            Gate::define($module, fn (User $user) => $user->hasPermission($module));

            foreach ($config['items'] ?? [] as $action => $label) {
                $key = $module.'/'.$action;
                Gate::define($key, fn (User $user) => $user->hasPermission($key));
            }
        }

        // Danh mục nhiều loại dùng chung route /backend/category/{kind}: 'category', 'category/add'… nhận kind
        // và quy về quyền của loại đó (news_category/add…)
        foreach (['', '/add', '/edit', '/delete'] as $action) {
            Gate::define('category'.$action, fn (User $user, string $kind) => isset(Category::KINDS[$kind])
                && $user->hasPermission(Category::KINDS[$kind]['permission'].$action));
        }
    }
}
