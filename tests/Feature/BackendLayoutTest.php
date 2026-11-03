<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** Khung trang quản trị theo template Ant Design: sidebar, header mobile, menu tài khoản, thanh trên cùng, thông báo. */
class BackendLayoutTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create(['name' => 'Nguyễn Đức']);
    }

    /** Phần sidebar desktop của trang. */
    private function sidebar(string $html): string
    {
        $start = strpos($html, '<div class="app-sidebar">');
        $this->assertNotFalse($start, 'Không thấy sidebar');

        return substr($html, $start, strpos($html, '</aside>', $start) - $start);
    }

    public function test_sidebar_lists_groups_and_items_with_ant_icons(): void
    {
        $sidebar = $this->sidebar($this->actingAs($this->root())->get('/backend')->assertOk()->getContent());

        foreach (['OVERVIEW', 'WEBSITE', 'SYSTEM'] as $group) {
            $this->assertStringContainsString('<div class="ant-menu-item-group-title" title="'.$group.'">'.$group.'</div>', $sidebar);
        }
        foreach (['dashboard', 'folder', 'file-text', 'container', 'team', 'setting', 'history'] as $icon) {
            $this->assertStringContainsString('<use href="#i-'.$icon.'"></use>', $sidebar);
        }
        // Mỗi icon dùng trong sidebar đều có trong bộ icon nhúng
        $sprite = file_get_contents(resource_path('views/backend/partials/sprite.blade.php'));
        preg_match_all('/<use href="#i-([a-z0-9-]+)"/', $sidebar, $m);
        foreach (array_unique($m[1]) as $icon) {
            $this->assertStringContainsString('<symbol id="i-'.$icon.'"', $sprite);
        }
        // Dashboard đang mở: tô sáng + aria-current; mục khác không
        $this->assertMatchesRegularExpression('#<li class="ant-menu-item ant-menu-item-selected" role="menuitem"[^>]*>\s*<span[^>]*aria-label="dashboard"#', $sidebar);
        $this->assertStringContainsString('<a href="'.route('backend.dashboard').'"  aria-current="page" >', $sidebar);
        $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'));
        $this->assertSame(1, substr_count($sidebar, 'ant-menu-item-selected'));
    }

    public function test_parent_menu_opens_when_a_child_page_is_current(): void
    {
        $root = $this->root();

        // Ở trang Users: menu cha Users mở sẵn, mục con Users được chọn (Categories là một mục thường, không phải menu cha)
        $sidebar = $this->sidebar($this->actingAs($root)->get('/backend/users')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#<li class="ant-menu-submenu ant-menu-submenu-inline ant-menu-submenu-open ant-menu-submenu-selected" role="menuitem">\s*<div class="ant-menu-submenu-title" role="button" tabindex="0" aria-expanded="true"[^>]*><span[^>]*aria-label="team"#', $sidebar);
        $this->assertMatchesRegularExpression('#<li class="ant-menu-item ant-menu-item-only-child ant-menu-item-selected" role="menuitem" style="padding-left: 48px">\s*<a href="'.preg_quote(route('backend.users.index'), '#').'"\s*aria-current="page"#', $sidebar);
        $this->assertSame(1, substr_count($sidebar, 'ant-menu-sub ant-menu-inline ant-menu-hidden')); // Configuration (affiliate) đóng
        $this->assertStringContainsString('<a href="'.route('backend.categories.index').'" ><span class="mr-auto">Categories</span></a>', $sidebar);

        // Ở Dashboard: menu cha Users và Configuration đều đóng
        $sidebar = $this->sidebar($this->actingAs($root)->get('/backend')->getContent());
        $this->assertSame(2, substr_count($sidebar, 'ant-menu-sub ant-menu-inline ant-menu-hidden'));
        $this->assertStringNotContainsString('ant-menu-submenu-open', $sidebar);

        // Ở trang cấu hình affiliate: menu cha Configuration mở, mục con được chọn
        $sidebar = $this->sidebar($this->actingAs($root)->get('/backend/affiliate/kpi')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#ant-menu-submenu-open ant-menu-submenu-selected" role="menuitem">\s*<div class="ant-menu-submenu-title" role="button" tabindex="0" aria-expanded="true"[^>]*><span[^>]*aria-label="fund"#', $sidebar);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('backend.affiliate.kpi.index'), '#').'"\s*aria-current="page"#', $sidebar);
    }

    public function test_sidebar_only_shows_what_the_user_may_open(): void
    {
        $user = User::factory()->create(['group_id' => Group::factory()->withPermissions('page', 'menu')->create()->id]);

        $sidebar = $this->sidebar($this->actingAs($user)->get('/backend')->assertOk()->getContent());

        $this->assertStringContainsString(route('backend.pages.index'), $sidebar);
        $this->assertStringContainsString(route('backend.menus.index'), $sidebar);
        foreach (['backend.users.index', 'backend.groups.index', 'backend.settings.edit', 'backend.audit-logs.index', 'backend.categories.index'] as $route) {
            $this->assertStringNotContainsString(route($route), $sidebar);
        }
        // Nhóm không còn mục nào thì ẩn luôn tiêu đề nhóm
        $this->assertStringNotContainsString('title="SYSTEM"', $sidebar);
        $this->assertStringContainsString('title="WEBSITE"', $sidebar);
    }

    public function test_account_menu_and_mobile_header(): void
    {
        $root = $this->root();
        $html = $this->actingAs($root)->get('/backend')->getContent();

        // Avatar đầu sidebar mở menu tài khoản; chưa có ảnh thì dùng ảnh người mặc định như template
        $this->assertStringContainsString('<button type="button" class="sidebar-user-button" aria-haspopup="menu" aria-expanded="false" aria-label="Menu tài khoản" title="Nguyễn Đức · '.$root->email.'">', $html);
        $this->assertStringContainsString('<span class="ant-avatar ant-avatar-circle ant-avatar-image"><img src="'.asset('assets/admin/img/avatar.svg').'" alt="" /></span>', $html);
        $this->assertFileExists(public_path('assets/admin/img/avatar.svg'));
        // Không nạp font Inter từ Google (template thực tế hiển thị bằng font hệ thống)
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);

        $menu = substr($html, strpos($html, '<template id="account-menu">'));
        $menu = substr($menu, 0, strpos($menu, '</template>'));
        $this->assertStringContainsString('<div class="account-menu-name">Nguyễn Đức</div>', $menu);
        $this->assertStringContainsString('<div class="account-menu-email">'.$root->email.'</div>', $menu);
        $this->assertStringContainsString('<a href="'.route('backend.profile.edit').'" role="menuitem">', $menu);
        $this->assertStringContainsString('<a href="#" data-submit="logout-form" role="menuitem">Đăng xuất</a>', $menu);
        $this->assertStringNotContainsString('anticon', $menu); // mục menu chỉ có chữ, như template
        $this->assertMatchesRegularExpression('#<form id="logout-form" method="POST" action="'.preg_quote(route('backend.logout'), '#').'" class="hidden"><input type="hidden" name="_token"#', $html);

        // Header mobile: nút mở drawer + tên app về Dashboard
        $this->assertStringContainsString('<a class="trigger" href="#" role="button" aria-label="Mở menu" aria-expanded="false"', $html);
        $this->assertMatchesRegularExpression('#<a class="brand gap-2.5" href="'.preg_quote(route('backend.dashboard'), '#').'"\s*>.*?<strong>'.config('app.name').'</strong></a#s', $html);
    }

    public function test_initials_avatar_follows_template(): void
    {
        // 2 chữ cái đầu của 2 từ đầu tên, như template (users-store.js initials)
        $this->assertSame('NĐ', (new User(['name' => 'Nguyễn Đức']))->initials());
        $this->assertSame('NV', (new User(['name' => '  nguyễn   văn An ']))->initials());
        $this->assertSame('C', (new User(['name' => 'Content']))->initials());
        $this->assertSame('?', (new User(['name' => '']))->initials());

        $user = User::factory()->create(['name' => 'Nguyễn Đức']);
        $this->assertSame(
            '<span class="ant-avatar ant-avatar-circle user-initials ant-avatar-lg">NĐ</span>',
            trim(view('backend.partials.avatar', ['user' => $user, 'size' => 'lg'])->render())
        );
        $this->assertSame(
            '<span class="ant-avatar ant-avatar-circle user-initials">NĐ</span>',
            trim(view('backend.partials.avatar', ['user' => $user])->render())
        );
    }

    public function test_topbar_shows_title_breadcrumb_and_actions(): void
    {
        $this->actingAs($this->root());

        $html = Blade::render(<<<'BLADE'
            @extends('layouts.backend')
            @section('title', 'Sửa dự án')
            @section('breadcrumb')<a href="/backend/project" class="page-topbar-crumb">Dự án</a>@endsection
            @section('actions')<button type="submit" class="ant-btn ant-btn-primary">Lưu</button>@endsection
            @section('content')<p>Nội dung</p>@endsection
            BLADE, ['errors' => new ViewErrorBag]);

        $this->assertStringContainsString('<title>Sửa dự án - '.config('app.name').'</title>', $html);
        $this->assertMatchesRegularExpression('#<div class="page-topbar-left">\s*<a href="/backend/project" class="page-topbar-crumb">Dự án</a>\s*<span class="page-topbar-sep" aria-hidden="true"><span role="img" aria-label="right"[^>]*>.*?</span></span>\s*<h1 class="page-topbar-title">Sửa dự án</h1>\s*</div>\s*<div class="page-topbar-right"><button type="submit" class="ant-btn ant-btn-primary">Lưu</button></div>#s', $html);

        // Không khai báo breadcrumb / actions: chỉ có tiêu đề
        $html = Blade::render(<<<'BLADE'
            @extends('layouts.backend')
            @section('title', 'Khách hàng')
            @section('content')<p>Nội dung</p>@endsection
            BLADE, ['errors' => new ViewErrorBag]);
        $this->assertMatchesRegularExpression('#<div class="page-topbar-left">\s*<h1 class="page-topbar-title">Khách hàng</h1>\s*</div>\s*</div>#', $html);
        $this->assertStringNotContainsString('page-topbar-right', $html);
        $this->assertStringNotContainsString('page-topbar-crumb', $html);
    }

    public function test_success_message_is_a_toast_and_errors_are_alerts(): void
    {
        $root = $this->root();

        $html = $this->actingAs($root)->withSession(['success' => 'Đã lưu dự án.'])->get('/backend')->getContent();
        $this->assertMatchesRegularExpression('#<div class="ant-message-notice move-up-appear move-up-appear-active" data-flash-message>.*?<div class="ant-message-custom-content ant-message-success" role="status">.*?<span>Đã lưu dự án.</span>#s', $html);
        // Toast nằm ngoài vùng nội dung (vùng đó có clip-path)
        $this->assertGreaterThan(strpos($html, '</main>'), strpos($html, 'class="ant-message"'));
        $this->assertStringNotContainsString('ant-alert-error', $html);

        $this->flushSession(); // withSession() giữ dữ liệu qua các request trong cùng test
        $html = $this->actingAs($root)->withSession(['error' => 'Không xóa được: còn 2 dự án.'])->get('/backend')->getContent();
        $this->assertMatchesRegularExpression('#<div class="ant-alert ant-alert-error ant-alert-closable" role="alert">.*?<div class="ant-alert-message">Không xóa được: còn 2 dự án.</div>.*?<button type="button" class="ant-alert-close-icon" tabindex="0" aria-label="Đóng">#s', $html);
        $this->assertStringNotContainsString('data-flash-message', $html);

        // Lỗi validate thật (form người dùng thiếu tên), quay lại Dashboard
        $this->flushSession();
        $html = $this->actingAs($root)->from('/backend')->followingRedirects()
            ->post('/backend/users', ['name' => '', 'email' => 'khong-hop-le'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<div class="ant-alert ant-alert-error ant-alert-with-description" role="alert">.*?<div class="ant-alert-message">Dữ liệu chưa hợp lệ</div>\s*<div class="ant-alert-description">\s*<ul class="m-0 list-disc pl-5">\s*<li>[^<]+</li><li>[^<]+</li>#s', $html);
        // Thông báo nằm sau thanh trên cùng, trước nội dung trang
        $this->assertGreaterThan(strpos($html, 'id="page-topbar"'), strpos($html, 'Dữ liệu chưa hợp lệ'));
    }
}
