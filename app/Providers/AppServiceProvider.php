<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use App\Observers\AuditObserver;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

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

        // Lịch sử thay đổi của module dự án
        foreach ([Project::class, Payment::class, Customer::class, Hosting::class] as $model) {
            $model::observe(AuditObserver::class);
        }
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
        // và quy về quyền của loại đó (project_category/add, news_category/add…)
        foreach (['', '/add', '/edit', '/delete'] as $action) {
            Gate::define('category'.$action, fn (User $user, string $kind) => isset(Category::KINDS[$kind])
                && $user->hasPermission(Category::KINDS[$kind]['permission'].$action));
        }
    }
}
