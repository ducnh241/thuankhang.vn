<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/** Khung trang quản trị theo layout Admin Pro của template: sidebar (thu gọn được), header, menu tài khoản, breadcrumb, thông báo. */
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
        $start = strpos($html, 'id="app-sidebar"');
        $this->assertNotFalse($start, 'Không thấy sidebar');

        return substr($html, $start, strpos($html, '</aside>', $start) - $start);
    }

    /** Chữ của các phần tử khớp XPath trong một đoạn HTML. */
    private function texts(string $html, string $query): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?><div>'.$html.'</div>');

        return array_map(fn ($node) => $node->textContent, iterator_to_array((new \DOMXPath($dom))->query($query)));
    }

    public function test_sidebar_lists_items_with_ant_icons(): void
    {
        $sidebar = $this->sidebar($this->actingAs($this->root())->get('/backend')->assertOk()->getContent());

        // Menu xếp liền nhau như template: không hiện tên nhóm (OVERVIEW, SHOP…), các mục theo thứ tự trong config
        foreach (['OVERVIEW', 'SHOP', 'AFFILIATE', 'WEBSITE', 'SYSTEM'] as $group) {
            $this->assertStringNotContainsString($group, $sidebar);
        }
        $this->assertSame(
            ['Dashboard', 'Categories', 'Products', 'Orders', 'Reviews', 'Pickup points', 'Members', 'Configuration', 'Pages', 'Menus', 'Files', 'Users', 'Settings', 'Audit log'],
            array_map('trim', $this->texts($sidebar, '//ul[@class="pro-menu"]/li/*[1]//span[@class="pro-menu-text"]'))
        );
        foreach (['dashboard', 'folder', 'file-text', 'container', 'team', 'setting', 'history', 'menu-fold'] as $icon) {
            $this->assertStringContainsString('<use href="#i-'.$icon.'"></use>', $sidebar);
        }
        // Mỗi icon dùng trong sidebar đều có trong bộ icon nhúng
        $sprite = file_get_contents(resource_path('views/backend/partials/sprite.blade.php'));
        preg_match_all('/<use href="#i-([a-z0-9-]+)"/', $sidebar, $m);
        foreach (array_unique($m[1]) as $icon) {
            $this->assertStringContainsString('<symbol id="i-'.$icon.'"', $sprite);
        }
        // Dashboard đang mở: tô sáng + aria-current; mục khác không
        $this->assertMatchesRegularExpression('#<li class="pro-menu-item pro-menu-item-selected" role="none">\s*<a href="'.preg_quote(route('backend.dashboard'), '#').'" role="menuitem" data-label="Dashboard"\s+aria-current="page"\s*><span[^>]*aria-label="dashboard"#', $sidebar);
        $this->assertSame(1, substr_count($sidebar, 'aria-current="page"'));
        $this->assertSame(1, substr_count($sidebar, 'pro-menu-item-selected'));
        // Đầu sidebar: logo + tên app về Dashboard; mặc định sidebar mở rộng
        $this->assertMatchesRegularExpression('#<div class="pro-sider-logo">\s*<a href="'.preg_quote(route('backend.dashboard'), '#').'"[^>]*>.*?<span class="pro-sider-brand">'.preg_quote(config('app.name'), '#').'</span></a#s', $sidebar);
        $this->assertStringNotContainsString('is-collapsed', $sidebar);
    }

    public function test_parent_menu_opens_when_a_child_page_is_current(): void
    {
        $root = $this->root();

        $closed = '#<ul class="pro-menu-sub" role="menu"\s+hidden\s*>#'; // menu cha đang đóng

        // Ở trang Users: menu cha Users mở sẵn, mục con Users được chọn (Categories là một mục thường, không phải menu cha)
        $sidebar = $this->sidebar($this->actingAs($root)->get('/backend/users')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#<li class="pro-menu-submenu pro-menu-submenu-open pro-menu-submenu-selected" role="none">\s*<button type="button" class="pro-menu-title" role="menuitem" aria-haspopup="true" aria-expanded="true"\s*><span[^>]*aria-label="team"#', $sidebar);
        $this->assertMatchesRegularExpression('#<li class="pro-menu-item pro-menu-item-selected" role="none">\s*<a href="'.preg_quote(route('backend.users.index'), '#').'" role="menuitem"\s+aria-current="page"#', $sidebar);
        $this->assertSame(1, preg_match_all($closed, $sidebar)); // Configuration (affiliate) đóng
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('backend.categories.index'), '#').'" role="menuitem" data-label="Categories"\s*><span[^>]*aria-label="folder".*?</span><span class="pro-menu-text">Categories</span></a#', $sidebar);

        // Ở Dashboard: menu cha Users và Configuration đều đóng
        $sidebar = $this->sidebar($this->actingAs($root)->get('/backend')->getContent());
        $this->assertSame(2, preg_match_all($closed, $sidebar));
        $this->assertStringNotContainsString('pro-menu-submenu-open', $sidebar);

        // Ở trang cấu hình affiliate: menu cha Configuration mở, mục con được chọn
        $sidebar = $this->sidebar($this->actingAs($root)->get('/backend/affiliate/kpi')->assertOk()->getContent());
        $this->assertMatchesRegularExpression('#pro-menu-submenu-open pro-menu-submenu-selected" role="none">\s*<button type="button" class="pro-menu-title" role="menuitem" aria-haspopup="true" aria-expanded="true"\s*><span[^>]*aria-label="fund"#', $sidebar);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('backend.affiliate.kpi.index'), '#').'" role="menuitem"\s+aria-current="page"#', $sidebar);
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
        $this->assertSame(['Dashboard', 'Pages', 'Menus'], array_map('trim', $this->texts($sidebar, '//span[@class="pro-menu-text"]')));
    }

    public function test_account_menu_and_header(): void
    {
        $root = $this->root();
        $html = $this->actingAs($root)->get('/backend')->getContent();

        // Avatar + tên ở góc phải header mở menu tài khoản; chưa có ảnh thì dùng ảnh người mặc định như template
        $this->assertMatchesRegularExpression('#<button type="button" class="app-header-account" aria-haspopup="menu" aria-expanded="false" aria-label="Menu tài khoản" title="Nguyễn Đức · '.preg_quote($root->email, '#').'"\s*><span class="ant-avatar ant-avatar-circle ant-avatar-image ant-avatar-sm"><img src="'.preg_quote(asset('assets/backend/img/avatar.svg'), '#').'" alt="" /></span>\s*<span class="app-header-account-name max-sm:hidden">Nguyễn Đức</span></button#', $html);
        $this->assertFileExists(public_path('assets/backend/img/avatar.svg'));
        // Không nạp font Inter từ Google (template thực tế hiển thị bằng font hệ thống)
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);

        $menu = substr($html, strpos($html, '<template id="account-menu">'));
        $menu = substr($menu, 0, strpos($menu, '</template>'));
        $this->assertStringContainsString('<div class="account-menu-name">Nguyễn Đức</div>', $menu);
        $this->assertStringContainsString('<div class="account-menu-email">'.$root->email.'</div>', $menu);
        $this->assertStringContainsString('<a href="'.route('backend.profile.edit').'" role="menuitem">', $menu);
        $this->assertStringContainsString('<a href="#" data-submit="logout-form" role="menuitem">Đăng xuất</a>', $menu);
        $this->assertStringNotContainsString('anticon', $menu); // mục menu chỉ có chữ, như template
        $this->assertStringNotContainsString('Xem website', $menu); // đã chuyển thành nút trên header
        $this->assertMatchesRegularExpression('#<form id="logout-form" method="POST" action="'.preg_quote(route('backend.logout'), '#').'" class="hidden"><input type="hidden" name="_token"#', $html);

        // Header: nút thu gọn / mở sidebar (trên mobile mở drawer), logo về Dashboard chỉ hiện trên mobile; header nằm
        // trong vùng nội dung, trước thanh trang
        $this->assertMatchesRegularExpression('#<header class="app-header">\s*<a class="app-header-logo lg:hidden" href="'.preg_quote(route('backend.dashboard'), '#').'"#', $html);
        $this->assertMatchesRegularExpression('#<button type="button" class="app-header-toggle" data-sider-toggle aria-controls="app-sidebar" aria-expanded="true" aria-label="Thu gọn / mở rộng menu"\s*><span[^>]*aria-label="menu-fold"#', $html);
        // Nút "Xem website" (tab mới) nằm ngay bên trái nút tài khoản
        $this->assertMatchesRegularExpression('#<a class="app-header-action" href="'.preg_quote(config('app.frontend_url'), '#').'" target="_blank" rel="noopener" title="Mở website ở tab mới" aria-label="Xem website"\s*><span[^>]*aria-label="global".*?</span><span class="max-sm:hidden">Xem website</span></a\s*>\s*<button type="button" class="app-header-account"#', $html);
        $this->assertGreaterThan(strpos($html, '</aside>'), strpos($html, '<header class="app-header">'));
        $this->assertGreaterThan(strpos($html, '<header class="app-header">'), strpos($html, 'id="page-topbar"'));
    }

    public function test_sidebar_collapsed_state_comes_from_cookie(): void
    {
        $root = $this->root();

        // Cookie do JS ghi (không mã hóa): server dựng sẵn sidebar thu gọn + icon mở rộng ở cả hai nút
        $html = $this->actingAs($root)->withUnencryptedCookie('backend_sider', 'collapsed')->get('/backend')->assertOk()->getContent();
        $this->assertStringContainsString('<div class="app-sidebar max-lg:hidden is-collapsed" id="app-sidebar">', $html);
        $this->assertSame(2, substr_count($html, 'data-sider-toggle aria-controls="app-sidebar" aria-expanded="false"'));
        $this->assertSame(2, preg_match_all('#data-sider-toggle[^>]*>\s*<span[^>]*aria-label="menu-unfold"#', $html));

        foreach (['expanded', 'x" onload="alert(1)'] as $value) {
            $html = $this->actingAs($root)->withUnencryptedCookie('backend_sider', $value)->get('/backend')->assertOk()->getContent();
            $this->assertStringContainsString('<div class="app-sidebar max-lg:hidden" id="app-sidebar">', $html);
            $this->assertStringNotContainsString('menu-unfold"', str_replace('id="i-menu-unfold"', '', $html));
        }
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
        // Breadcrumb: "Trang chủ / Dự án / Sửa dự án" — cấp đầu do layout thêm, các cấp cách nhau bằng dấu /
        $home = '<div class="page-topbar-home max-md:hidden">\s*<a href="'.preg_quote(route('backend.dashboard'), '#').'" class="page-topbar-crumb">Trang chủ</a>\s*<span class="page-topbar-sep" aria-hidden="true">/</span>\s*</div>';
        $this->assertMatchesRegularExpression('#'.$home.'\s*<div class="page-topbar-left">\s*<a href="/backend/project" class="page-topbar-crumb">Dự án</a>\s*<span class="page-topbar-sep" aria-hidden="true">/</span>\s*<h1 class="page-topbar-title">Sửa dự án</h1>\s*</div>\s*<div class="page-topbar-right"><button type="submit" class="ant-btn ant-btn-primary">Lưu</button></div>#s', $html);

        // Không khai báo breadcrumb / actions: chỉ có tiêu đề
        $html = Blade::render(<<<'BLADE'
            @extends('layouts.backend')
            @section('title', 'Khách hàng')
            @section('content')<p>Nội dung</p>@endsection
            BLADE, ['errors' => new ViewErrorBag]);
        $this->assertMatchesRegularExpression('#'.$home.'\s*<div class="page-topbar-left">\s*<h1 class="page-topbar-title">Khách hàng</h1>\s*</div>\s*</div>#', $html);
        $this->assertStringNotContainsString('page-topbar-right', $html);
        $this->assertSame(1, substr_count($html, 'page-topbar-crumb'));

        // Dashboard là trang chủ: không có cấp "Trang chủ", các trang khác thì có
        $root = $this->root();
        $this->actingAs($root)->get('/backend')->assertOk()->assertDontSee('page-topbar-home', false);
        $html = $this->actingAs($root)->get('/backend/users')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#'.$home.'\s*<div class="page-topbar-left">\s*<h1 class="page-topbar-title">#', $html);
        // Trang tự dựng thanh trang (form sản phẩm): Trang chủ / Sản phẩm / Thêm sản phẩm
        $html = $this->actingAs($root)->get('/backend/product/create')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#'.$home.'\s*<div class="page-topbar-left">\s*<a href="'.preg_quote(route('backend.products.index'), '#').'" class="page-topbar-crumb"[^>]*>Sản phẩm</a>\s*<span class="page-topbar-sep" aria-hidden="true">/</span>\s*<h1 class="page-topbar-title">Thêm sản phẩm</h1>#', $html);
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
