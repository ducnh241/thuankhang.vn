<?php

namespace Tests\Feature;

use App\Http\Controllers\Backend\SettingController;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Support\SiteSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function valid(array $overrides = []): array
    {
        return [
            '_tab' => 'general', 'site_name' => 'Đức NH', 'site_hotline' => '0987 654 321', 'site_email' => 'lienhe@ducnh.com',
            'site_address' => 'Hà Nội', 'site_footer' => '<p>© 2026 Đức NH</p>', 'site_map' => '', 'social_facebook' => 'https://facebook.com/ducnh',
            'seo_title' => 'Đức NH', 'seo_description' => 'Thiết kế website', 'seo_keywords' => ['website', 'hosting'],
            'code_head' => '', 'code_body' => '', 'code_bottom' => '', ...$overrides,
        ];
    }

    public function test_permissions_and_menu(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('setting')->create()->id]);
        $editor = User::factory()->create(['group_id' => Group::factory()->withPermissions('setting', 'setting/edit')->create()->id]);
        $other = User::factory()->create(['group_id' => Group::factory()->withPermissions('page')->create()->id]);

        $this->get('/backend/setting')->assertRedirect('/backend/login');
        $this->actingAs($other)->get('/backend/setting')->assertForbidden();
        $this->actingAs($other)->put('/backend/setting', $this->valid())->assertForbidden();
        $this->actingAs($other)->get('/backend')->assertDontSee(route('backend.settings.edit'));
        // Menu: Settings nằm trong nhóm SYSTEM, sau Users
        $this->actingAs($this->root())->get('/backend')
            ->assertSeeInOrder(['SYSTEM', route('backend.users.index'), route('backend.groups.index'), route('backend.settings.edit')], false);

        // Chỉ xem: các ô khóa (inert), không có dải lưu, gửi form bị chặn
        $this->actingAs($viewer)->get('/backend/setting')->assertOk()
            ->assertSee('Bạn chỉ có quyền xem cài đặt.')->assertSee('<div class="min-w-0"  inert >', false)
            ->assertDontSee('id="save-bar"', false)->assertDontSee('data-edit-form', false);
        $this->actingAs($viewer)->put('/backend/setting', $this->valid())->assertForbidden();
        $this->assertSame(0, Setting::count());
        $this->actingAs($viewer)->get('/backend')->assertSee(route('backend.settings.edit'));

        $this->actingAs($editor)->get('/backend/setting')->assertOk()->assertSee('data-edit-form', false)->assertSee('id="save-bar"', false)
            ->assertDontSee('Bạn chỉ có quyền xem cài đặt.');
        $this->actingAs($editor)->put('/backend/setting', $this->valid())->assertSessionHasNoErrors();
        $this->assertSame('Đức NH', Setting::get('site_name'));
    }

    public function test_save_stores_every_field_and_returns_to_the_tab(): void
    {
        Setting::put('other_module_key', 'Giá trị cũ');
        $this->actingAs($this->root())->put('/backend/setting', $this->valid([
            '_tab' => 'seo', 'seo_keywords' => [' website ', 'hosting', 'website', ''], 'code_head' => '<script>gtag()</script>',
            'site_map' => 'https://maps.app.goo.gl/abc',
        ]))->assertRedirect(route('backend.settings.edit', ['tab' => 'seo']))->assertSessionHas('success', 'Đã lưu cài đặt.');

        $all = SiteSettings::all();
        $this->assertSame('Đức NH', $all['site_name']);
        $this->assertSame('0987 654 321', $all['site_hotline']);
        $this->assertSame('<p>© 2026 Đức NH</p>', $all['site_footer']);
        $this->assertSame('https://facebook.com/ducnh', $all['social_facebook']);
        $this->assertSame('website, hosting', $all['seo_keywords']);
        $this->assertSame('<script>gtag()</script>', $all['code_head']);
        $this->assertSame('https://maps.app.goo.gl/abc', $all['site_map']);
        // Ô trống chưa từng lưu: không tạo dòng; khóa khác trong bảng settings giữ nguyên
        $this->assertFalse(Setting::where('key', 'social_zalo')->exists());
        $this->assertFalse(Setting::where('key', 'code_body')->exists());
        $this->assertSame('Giá trị cũ', Setting::get('other_module_key'));

        // Trang hiện lại đúng dữ liệu (từ khóa thành tag đã chọn), mở đúng tab
        $this->actingAs($this->root())->get('/backend/setting?tab=seo')->assertOk()
            ->assertSeeInOrder(['<option value="website" selected>website</option>', '<option value="hosting" selected>hosting</option>', '</select>'], false)
            ->assertSee('<h1 class="page-topbar-title" id="page-title">SEO</h1>', false)
            ->assertSee('<section class="flex flex-col gap-4" id="settings-seo"', false)
            ->assertSee('<section class="flex flex-col gap-4 hidden" id="settings-general"', false);
        // Mã theo dõi nằm ở tab Tracking riêng
        $this->actingAs($this->root())->get('/backend/setting?tab=tracking')->assertOk()
            ->assertSee('<h1 class="page-topbar-title" id="page-title">Tracking</h1>', false)
            ->assertSeeInOrder(['id="settings-seo"', 'id="settings-tracking"', 'Mã theo dõi', 'name="code_head"'], false)
            ->assertSee('&lt;script&gt;gtag()&lt;/script&gt;</textarea>', false);

        // Xóa trắng một ô đã lưu → về null; tab lạ → về tab Chung
        $this->actingAs($this->root())->put('/backend/setting', $this->valid(['social_facebook' => '', '_tab' => 'abc', 'seo_keywords' => null]))
            ->assertRedirect(route('backend.settings.edit', ['tab' => 'general']));
        $this->assertNull(Setting::get('social_facebook'));
        $this->assertNull(Setting::get('seo_keywords'));
    }

    public function test_validation_errors_open_the_tab_of_the_first_error(): void
    {
        $root = $this->root();
        $cases = [
            'site_name' => [['site_name' => ''], 'general'],
            'site_email' => [['site_email' => 'abc'], 'general'],
            'site_hotline' => [['site_hotline' => '0987<script>'], 'general'],
            'site_map' => [['site_map' => 'https://google.com.evil.io/maps/embed?pb=1'], 'general'],
            'social_facebook' => [['social_facebook' => 'facebook.com/ducnh'], 'social'],
            'social_zalo' => [['social_zalo' => 'javascript:alert(1)'], 'social'],
            'seo_title' => [['seo_title' => str_repeat('a', 71)], 'seo'],
            'seo_keywords.0' => [['seo_keywords' => ['a,b']], 'seo'],
            'seo_keywords' => [['seo_keywords' => array_map(fn ($i) => "k$i", range(1, 31))], 'seo'],
            'code_head' => [['code_head' => str_repeat('a', 20001)], 'tracking'],
            'code_bottom' => [['code_bottom' => str_repeat('a', 20001)], 'tracking'],
        ];
        foreach ($cases as $field => [$input, $tab]) {
            $this->actingAs($root)->from('/backend/setting?tab=general')->put('/backend/setting', $this->valid($input))
                ->assertRedirect('/backend/setting?tab=general')->assertSessionHasErrors($field);
            // Đi theo redirect như trình duyệt: trang mở tab có ô lỗi, ô đó báo lỗi
            $html = $this->actingAs($root)->from('/backend/setting?tab=general')->followingRedirects()
                ->put('/backend/setting', $this->valid($input))->assertOk()->getContent();
            $this->assertStringContainsString('<h1 class="page-topbar-title" id="page-title">'.SettingController::TABS[$tab][0].'</h1>', $html, $field);
            $panel = explode('data-panel=', explode('data-panel="'.$tab.'">', $html, 2)[1])[0];
            $this->assertStringContainsString('ant-form-item-has-error', $panel, $field);
        }
        $this->assertSame(0, Setting::count());

        // Ô đã lưu bị xóa trắng rồi lưu lỗi: vẫn hiện trống (không hiện lại giá trị cũ); bỏ hết từ khóa cũng vậy
        Setting::put('site_name', 'Tên cũ');
        Setting::put('seo_keywords', 'cũ');
        $html = $this->actingAs($root)->from('/backend/setting')->followingRedirects()
            ->put('/backend/setting', $this->valid(['site_name' => '', 'seo_keywords' => null, 'social_facebook' => 'x']))->getContent();
        $this->assertStringContainsString('id="site_name" name="site_name" value=""', $html);
        $this->assertStringNotContainsString('<option value="cũ" selected>', $html);
        Setting::query()->delete();

        // Lỗi giữ lại dữ liệu vừa nhập, kể cả từ khóa
        $this->actingAs($root)->from('/backend/setting')->followingRedirects()->put('/backend/setting', $this->valid(['site_name' => '', 'seo_keywords' => ['giữ lại']]))
            ->assertSee('<option value="giữ lại" selected>giữ lại</option>', false)
            ->assertSee('value="lienhe@ducnh.com"', false)->assertSee('data-dirty', false);
    }

    public function test_map_embed_url(): void
    {
        $embed = 'https://www.google.com/maps/embed?pb=!1m18&amp;x=1';
        $this->assertSame('https://www.google.com/maps/embed?pb=!1m18&x=1', SiteSettings::mapEmbedUrl('<iframe src="'.$embed.'" width="600"></iframe>'));
        $this->assertSame('https://www.google.com.vn/maps/embed?pb=1', SiteSettings::mapEmbedUrl('https://www.google.com.vn/maps/embed?pb=1'));
        $this->assertSame('https://www.google.com/maps?q='.rawurlencode('https://maps.app.goo.gl/abc').'&output=embed', SiteSettings::mapEmbedUrl('https://maps.app.goo.gl/abc'));
        $this->assertSame('https://www.google.com/maps?q='.rawurlencode('https://www.google.com/maps/place/Hà+Nội').'&output=embed', SiteSettings::mapEmbedUrl('https://www.google.com/maps/place/Hà+Nội'));
        foreach (['', 'http://www.google.com/maps/embed?pb=1', 'https://google.com.evil.io/maps', 'https://evil.io/?u=https://www.google.com/maps',
            '<iframe src="https://evil.io/maps"></iframe>', 'javascript:alert(1)', 'https://maps.google.evil.io/'] as $bad) {
            $this->assertNull(SiteSettings::mapEmbedUrl($bad), $bad);
        }
    }

    public function test_logo_and_favicon_upload_replace_and_remove(): void
    {
        Storage::fake('public');
        $root = $this->root();

        $this->actingAs($root)->put('/backend/setting', $this->valid([
            'logo' => UploadedFile::fake()->image('logo.png', 240, 60), 'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ]))->assertSessionHasNoErrors();
        $logo = Setting::get('site_logo');
        $favicon = Setting::get('site_favicon');
        $this->assertStringStartsWith('settings/', $logo);
        Storage::disk('public')->assertExists([$logo, $favicon]);
        $this->actingAs($root)->get('/backend/setting')->assertSee('data-current="'.Storage::disk('public')->url($logo).'"', false)
            ->assertSee('<img src="'.Storage::disk('public')->url($favicon).'" alt="" class="ant-upload-list-item-image" />', false);

        // Ảnh mới thay ảnh cũ (ảnh cũ bị xóa); lưu không chọn ảnh thì giữ nguyên
        $this->actingAs($root)->put('/backend/setting', $this->valid(['logo' => UploadedFile::fake()->image('logo2.jpg', 200, 50)]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($logo);
        $this->assertNotSame($logo, Setting::get('site_logo'));
        $this->assertSame($favicon, Setting::get('site_favicon'));

        // Bỏ ảnh
        $newLogo = Setting::get('site_logo');
        $this->actingAs($root)->put('/backend/setting', $this->valid(['remove_logo' => '1']))->assertSessionHasNoErrors();
        $this->assertNull(Setting::get('site_logo'));
        Storage::disk('public')->assertMissing($newLogo);
        Storage::disk('public')->assertExists($favicon);

        // ICO được nhận cho favicon
        $this->actingAs($root)->put('/backend/setting', $this->valid(['favicon' => UploadedFile::fake()->create('favicon.ico', 4, 'image/vnd.microsoft.icon')]))
            ->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.ico', Setting::get('site_favicon'));
    }

    public function test_rejects_svg_and_oversized_images(): void
    {
        Storage::fake('public');
        $root = $this->root();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');

        $this->actingAs($root)->put('/backend/setting', $this->valid(['logo' => $svg]))->assertSessionHasErrors(['logo' => 'Logo phải là ảnh PNG, JPG hoặc WEBP.']);
        $this->actingAs($root)->put('/backend/setting', $this->valid(['favicon' => $svg]))->assertSessionHasErrors('favicon');
        $this->actingAs($root)->put('/backend/setting', $this->valid(['logo' => UploadedFile::fake()->image('big.png')->size(2049)]))->assertSessionHasErrors('logo');
        $this->actingAs($root)->put('/backend/setting', $this->valid(['favicon' => UploadedFile::fake()->image('big.png')->size(513)]))->assertSessionHasErrors('favicon');
        $this->actingAs($root)->put('/backend/setting', $this->valid(['logo' => UploadedFile::fake()->create('shell.php', 1, 'text/x-php')]))->assertSessionHasErrors('logo');
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, Setting::count());
    }

    public function test_values_are_escaped_in_the_admin_page(): void
    {
        foreach ([
            'site_name' => 'Tên <script>alert(1)</script>', 'site_footer' => '<p>chân</p></textarea><script>alert(2)</script>',
            'code_head' => '</textarea><script>alert(3)</script>', 'site_address' => '"><img src=x onerror=alert(4)>',
            'seo_keywords' => 'từ"><script>alert(5)</script>',
        ] as $key => $value) {
            Setting::put($key, $value);
        }
        $html = $this->actingAs($this->root())->get('/backend/setting')->assertOk()->getContent();
        foreach (['alert(1)', 'alert(2)', 'alert(3)', 'alert(5)'] as $needle) {
            $this->assertStringNotContainsString('<script>'.$needle, $html);
        }
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;/textarea&gt;&lt;script&gt;alert(3)&lt;/script&gt;</textarea>', $html);
    }

    public function test_array_and_odd_inputs_do_not_crash(): void
    {
        $root = $this->root();
        foreach (['site_name', 'site_map', 'site_footer', 'social_facebook', 'seo_title', 'code_head', '_tab', 'remove_logo', 'logo'] as $field) {
            $this->actingAs($root)->from('/backend/setting')->put('/backend/setting', $this->valid([$field => ['x' => ['y']]]))
                ->assertStatus(302);
        }
        $this->actingAs($root)->put('/backend/setting', $this->valid(['seo_keywords' => 'không phải mảng']))->assertSessionHasErrors('seo_keywords');
        $this->actingAs($root)->put('/backend/setting', $this->valid(['seo_keywords' => [['lồng']]]))->assertSessionHasErrors('seo_keywords.0');
        $this->actingAs($root)->get('/backend/setting?tab[]=seo')->assertOk()->assertSee('id="page-title">Chung</h1>', false);
        $this->actingAs($root)->get('/backend/setting')->assertOk();
    }
}
