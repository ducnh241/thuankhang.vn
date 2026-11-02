<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_layout_for_logged_in_user(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Nguyễn Đức']);

        $this->actingAs($user)
            ->get('/backend')
            ->assertOk()
            ->assertSee($user->email)
            ->assertSee('Nguyễn Đức')
            ->assertDontSee('Arham')
            // Khung trang mới (template Ant Design): sidebar tối, header chỉ trên mobile, thanh trên cùng có tiêu đề
            ->assertSee('<div class="app-sidebar">', false)
            ->assertSee('<div class="app-header lg:hidden">', false)
            ->assertSee('<title>Dashboard - '.config('app.name').'</title>', false)
            ->assertSee('<h1 class="page-topbar-title">Chào buổi', false) // thanh trên cùng: lời chào + ngày
            ->assertSee('<a class="sidebar-user-brand" href="'.route('backend.dashboard').'">'.config('app.name').'</a>', false)
            ->assertSee('src="'.config('app.logo').'"', false) // logo ở header mobile
            ->assertSee('aria-current="page"', false)
            // Menu tài khoản: tài khoản, xem website (tab mới), đăng xuất bằng form POST
            ->assertSee('id="account-menu"', false)
            ->assertSee('href="'.route('backend.profile.edit').'"', false)
            ->assertSee('href="'.config('app.frontend_url').'" target="_blank" rel="noopener"', false)
            ->assertSee('Xem website')
            ->assertSee('<form id="logout-form" method="POST" action="'.route('backend.logout').'" class="hidden">', false)
            // Asset mới, không còn markup / asset của giao diện cũ
            ->assertSee('assets/admin/build/assets/app-', false)
            ->assertSee('assets/admin/img/favicon.svg', false)
            ->assertDontSee('assets/backend/', false)
            ->assertDontSee('data-sidebar=', false)
            ->assertDontSee('ov-user-menu', false);
    }

    public function test_login_page_branding(): void
    {
        $this->get('/backend/login')->assertOk()
            ->assertSee('<title>Đăng nhập - '.config('app.name').'</title>', false)
            ->assertSee('Hệ thống quản trị website, dự án và khách hàng.')
            ->assertSee('© '.date('Y').' '.config('app.name'), false)
            ->assertSee('src="'.config('app.logo').'"', false)
            ->assertDontSee('lucide-command', false)
            ->assertDontSee('Ready to launch')
            ->assertDontSee('ENG');
    }

    public function test_logo_config(): void
    {
        // Đường dẫn trong public/ → asset(); bỏ trống → chỉ hiện chữ cái đầu tên app
        config(['app.logo' => 'assets/backend/img/logo.png']);
        $this->get('/backend/login')->assertSee('src="'.asset('assets/backend/img/logo.png').'"', false);

        config(['app.logo' => '', 'app.name' => 'ProCMS']);
        $this->get('/backend/login')->assertDontSee('<img src=', false)->assertSee('>P</span>', false);
    }
}
