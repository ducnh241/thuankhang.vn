<?php

namespace Tests\Feature;

use App\Models\Group;
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
            // Khung trang theo layout Admin Pro của template: sidebar tối (thu gọn được), header trắng, thanh trang có tiêu đề
            ->assertSee('<div class="app-sidebar max-lg:hidden" id="app-sidebar">', false)
            ->assertSee('<header class="app-header">', false)
            ->assertSee('<title>Dashboard - '.config('app.name').'</title>', false)
            ->assertSee('<h1 class="page-topbar-title">Chào buổi', false) // thanh trên cùng: lời chào + ngày
            ->assertSee('<span class="pro-sider-brand">'.config('app.name').'</span>', false)
            ->assertSee('src="'.asset('assets/backend/img/logo.svg').'"', false) // logo của template ở đầu sidebar và header mobile
            ->assertSee('aria-current="page"', false)
            // Header: nút xem website (tab mới); menu tài khoản: tài khoản, đăng xuất bằng form POST
            ->assertSee('id="account-menu"', false)
            ->assertSee('href="'.route('backend.profile.edit').'"', false)
            ->assertSee('href="'.config('app.frontend_url').'" target="_blank" rel="noopener"', false)
            ->assertSee('Xem website')
            ->assertSee('<form id="logout-form" method="POST" action="'.route('backend.logout').'" class="hidden">', false)
            // Asset mới, không còn markup / asset của giao diện cũ
            ->assertSee('assets/backend/build/assets/app-', false)
            ->assertSee('assets/backend/img/favicon.svg', false)
            ->assertDontSee('assets/frontend/build', false) // trang quản trị không nạp CSS / JS website
            ->assertDontSee('data-sidebar=', false)
            ->assertDontSee('ov-user-menu', false);
    }

    public function test_dashboard_sections_follow_permissions(): void
    {
        $onlyAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access')->create()->id]);
        $this->actingAs($onlyAccess)->get('/backend')->assertOk()
            ->assertSee('chưa được cấp quyền xem mục nào')
            ->assertDontSee('data-stat=', false)->assertDontSee('Hoạt động gần đây');

        $pages = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'page')->create()->id]);
        $this->actingAs($pages)->get('/backend')->assertOk()
            ->assertSee('data-stat="pages"', false)->assertDontSee('data-stat="users"', false)->assertDontSee('Hoạt động gần đây');

        $this->actingAs(User::factory()->superAdmin()->create())->get('/backend')->assertOk()
            ->assertSee('data-stat="users"', false)->assertSee('data-stat="groups"', false)
            ->assertSee('Hoạt động gần đây')->assertSee(route('backend.audit-logs.index'), false)
            ->assertDontSee('chưa được cấp quyền xem mục nào');
    }

    public function test_login_page_branding(): void
    {
        $this->get('/backend/login')->assertOk()
            ->assertSee('<title>Đăng nhập - '.config('app.name').'</title>', false)
            ->assertDontSee('login-brand', false) // không còn khối thương hiệu bên trái: form nằm giữa trang
            ->assertSee('© '.date('Y').' '.config('app.name'), false)
            ->assertDontSee('assets/backend/img/logo.svg', false) // không hiện logo + tên hệ thống trên form
            ->assertDontSee('lucide-command', false)
            ->assertDontSee('Ready to launch')
            ->assertDontSee('ENG');
    }

    public function test_logo_config(): void
    {
        // Đường dẫn trong public/ → asset(); bỏ trống → chỉ hiện chữ cái đầu tên app
        // (trang đăng nhập không còn logo → kiểm tra ở logo của thanh bên trang quản trị)
        $admin = User::factory()->superAdmin()->create();
        config(['app.logo' => 'assets/backend/img/logo.png']);
        $this->actingAs($admin)->get('/backend')->assertSee('src="'.asset('assets/backend/img/logo.png').'"', false);

        // URL đầy đủ thì dùng nguyên
        config(['app.logo' => 'https://cdn.example.com/logo.png']);
        $this->actingAs($admin)->get('/backend')->assertSee('src="https://cdn.example.com/logo.png"', false);

        config(['app.logo' => '', 'app.name' => 'ProCMS']);
        $this->actingAs($admin)->get('/backend')->assertDontSee('<img src="'.asset('assets/backend/img/logo.png').'"', false)
            ->assertSee('<span class="app-logo-letter text-lg" >P</span>', false);
        // Mặc định: logo của template Admin Pro, có sẵn trong public/
        $this->assertSame('assets/backend/img/logo.svg', (require config_path('app.php'))['logo'] ?? null);
        $this->assertFileExists(public_path('assets/backend/img/logo.svg'));
    }
}
