<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Setting;
use App\Support\AppIcon;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * "Thêm vào màn hình chính" (iOS / Android) — mở website như ứng dụng riêng: manifest (/manifest.webmanifest), thẻ meta ở
 * layout, biểu tượng ứng dụng tạo từ favicon trong Cài đặt (nền trắng, vuông), gợi ý cài ở trang chủ / trang tài khoản.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        Storage::fake('public');
    }

    private function favicon(int $width = 300, int $height = 300): string
    {
        $path = UploadedFile::fake()->image('favicon.png', $width, $height)->store('settings', 'public');
        Setting::put('site_favicon', $path);

        return $path;
    }

    public function test_manifest_describes_a_standalone_app_named_after_the_site(): void
    {
        Setting::put('site_name', 'Thuankhang.vn');
        Setting::put('seo_description', 'Cửa hàng Thuận Khang');

        $response = $this->get('/manifest.webmanifest')->assertOk();
        $this->assertStringStartsWith('application/manifest+json', $response->headers->get('Content-Type'));
        $manifest = $response->json();
        $this->assertSame(['Thuankhang.vn', 'Thuankhang', 'Cửa hàng Thuận Khang', 'standalone', '/', '/', 'vi'],
            [$manifest['name'], $manifest['short_name'], $manifest['description'], $manifest['display'], $manifest['start_url'], $manifest['scope'], $manifest['lang']]);
        $this->assertSame(['#ffffff', '#ffffff'], [$manifest['theme_color'], $manifest['background_color']]);
        // Android cần đủ icon 192 và 512; có thêm bản maskable
        $this->assertSame([['192x192', 'any'], ['512x512', 'any'], ['512x512', 'maskable']], array_map(fn ($icon) => [$icon['sizes'], $icon['purpose']], $manifest['icons']));
        $this->assertSame(['/don-hang', '/gio-hang', '/tai-khoan'], array_column($manifest['shortcuts'], 'url'));
        // Tiếng Việt không bị mã hóa \u
        $this->assertStringContainsString('Cửa hàng Thuận Khang', $response->getContent());

        // Chưa đặt favicon: dùng bộ icon tĩnh mặc định (file có thật)
        foreach ($manifest['icons'] as $icon) {
            $this->assertStringContainsString('/assets/frontend/pwa/', $icon['src']);
            $this->assertFileExists(public_path(parse_url($icon['src'], PHP_URL_PATH)));
        }
        $this->assertFileExists(public_path('assets/frontend/pwa/apple-touch-icon.png'));
    }

    public function test_app_icons_are_generated_from_the_favicon_on_a_white_square(): void
    {
        $favicon = $this->favicon(300, 200); // favicon không vuông vẫn ra icon vuông

        $manifest = $this->get('/manifest.webmanifest')->assertOk()->json();
        $sizes = ['icon-192' => 192, 'icon-512' => 512, 'icon-maskable-512' => 512];
        foreach (array_values($sizes) as $index => $size) {
            $src = $manifest['icons'][$index]['src'];
            $this->assertStringContainsString('/storage/pwa/', $src);
            $path = 'pwa/'.basename($src);
            Storage::disk('public')->assertExists($path);
            $info = getimagesizefromstring(Storage::disk('public')->get($path));
            $this->assertSame([$size, $size, 'image/png'], [$info[0], $info[1], $info['mime']]);
        }
        // Góc ảnh là nền trắng đặc (iOS không hiện nền đen với favicon trong suốt)
        $image = imagecreatefromstring(Storage::disk('public')->get('pwa/'.basename($manifest['icons'][1]['src'])));
        $this->assertSame(0xFFFFFF, imagecolorat($image, 2, 2) & 0xFFFFFF);

        // Trang web: thẻ manifest + thẻ cho iOS, apple-touch-icon 180 px tạo từ favicon
        $html = $this->get('/')->assertOk()
            ->assertSee('<link rel="manifest" href="'.route('pwa.manifest').'" />', false)
            ->assertSee('<meta name="apple-mobile-web-app-capable" content="yes" />', false)
            ->assertSee('<meta name="mobile-web-app-capable" content="yes" />', false)
            ->assertSee('<meta name="theme-color" content="#ffffff" />', false)
            ->getContent();
        $this->assertSame(1, preg_match('#<link rel="apple-touch-icon" href="([^"]+)"#', $html, $m));
        $apple = getimagesizefromstring(Storage::disk('public')->get('pwa/'.basename($m[1])));
        $this->assertSame([180, 180], [$apple[0], $apple[1]]);

        // Gọi lại không tạo thêm file; đổi favicon thì tạo bộ mới và dọn bộ cũ
        $files = Storage::disk('public')->files('pwa');
        $this->assertCount(4, $files);
        $this->get('/manifest.webmanifest');
        $this->assertSame($files, Storage::disk('public')->files('pwa'));
        Storage::disk('public')->delete($favicon);
        $this->favicon(400, 400);
        $this->get('/manifest.webmanifest');
        $this->get('/');
        $new = Storage::disk('public')->files('pwa');
        $this->assertCount(4, $new);
        $this->assertSame([], array_intersect($files, $new));
    }

    public function test_unreadable_favicon_falls_back_to_the_default_icons(): void
    {
        // Favicon .ico (GD không đọc được) hoặc file đã mất: không lỗi, dùng icon mặc định
        Storage::disk('public')->put('settings/favicon.ico', 'khong-phai-anh');
        Setting::put('site_favicon', 'settings/favicon.ico');
        $this->assertStringContainsString('/assets/frontend/pwa/icon-512.png', AppIcon::url('icon-512', 'settings/favicon.ico'));
        $this->assertStringContainsString('/assets/frontend/pwa/icon-192.png', AppIcon::url('icon-192', 'settings/da-xoa.png'));
        $this->assertStringContainsString('/assets/frontend/pwa/icon-192.png', AppIcon::url('icon-192', null));
        $this->get('/manifest.webmanifest')->assertOk();
        $this->get('/')->assertOk()->assertSee('/assets/frontend/pwa/apple-touch-icon.png', false);
    }

    public function test_install_hint_is_on_the_home_and_account_pages_hidden_until_the_script_shows_it(): void
    {
        Setting::put('site_name', 'Thuankhang.vn');

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['hidden data-install-app', 'Cài ứng dụng Thuankhang', 'data-install-button', 'Safari', 'Thêm vào Màn hình chính', 'data-install-android', 'Thêm vào màn hình chính', 'data-install-close'], false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="Thuankhang" />', false);
        $this->actingAs(Member::factory()->create(), 'member')->get('/tai-khoan')->assertOk()->assertSee('hidden data-install-app', false);
        // Trang khác không chèn gợi ý
        $this->get('/tai-khoan/vi')->assertOk()->assertDontSee('data-install-app', false);
    }
}
