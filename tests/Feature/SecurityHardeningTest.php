<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\LoginController as AdminLogin;
use App\Http\Controllers\Frontend\Auth\LoginController as MemberLogin;
use App\Http\Middleware\AuthenticateApiToken;
use App\Models\ApiToken;
use App\Models\Category;
use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductImageImporter;
use App\Support\AffiliateSettings;
use App\Support\SiteSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Rà soát bảo mật: chống dò mật khẩu / dò token theo IP, chống SSRF khi API tải ảnh từ link, tên ô nhập biến thể. */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
    }

    public function test_admin_login_is_limited_per_ip_across_different_emails(): void
    {
        $user = User::factory()->superAdmin()->create();

        // Mỗi email chỉ sai 1 lần (không chạm giới hạn theo email) nhưng cùng một IP
        for ($i = 0; $i < AdminLogin::IP_MAX_ATTEMPTS; $i++) {
            $this->post('/backend/login', ['email' => "do{$i}@example.com", 'password' => 'nope']);
        }

        $this->from('/backend/login')->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/backend/login')->assertSessionHasErrors('email');
        $this->assertGuest();

        // IP khác không bị ảnh hưởng
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->post('/backend/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/backend');
    }

    public function test_member_login_is_limited_per_ip_across_different_phones(): void
    {
        $member = Member::factory()->create(['phone' => '0912345678', 'password' => 'matkhau123']);

        for ($i = 0; $i < MemberLogin::IP_MAX_ATTEMPTS; $i++) {
            $this->post('/dang-nhap', ['phone' => '09'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'password' => 'nope']);
        }

        $this->from('/dang-nhap')->post('/dang-nhap', ['phone' => $member->phone, 'password' => 'matkhau123'])
            ->assertRedirect('/dang-nhap')->assertSessionHasErrors('phone');
        $this->assertGuest('member');
    }

    public function test_api_blocks_an_ip_after_too_many_bad_tokens(): void
    {
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        [, $plain] = ApiToken::issue($ops, 'Claude');

        for ($i = 0; $i < AuthenticateApiToken::MAX_FAILURES; $i++) {
            $this->withHeaders(['Authorization' => "Bearer tk_sai{$i}"])->getJson('/api/v1/me')->assertUnauthorized();
        }
        // Đã khóa IP này: kể cả không gửi token hay gửi token đúng cũng 429 (không còn truy vấn database)
        $this->withHeaders(['Authorization' => 'Bearer tk_sai'])->getJson('/api/v1/me')->assertStatus(429)->assertHeader('Retry-After');
        $this->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/v1/me')->assertStatus(429);

        // IP khác dùng token đúng vẫn bình thường
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->withHeaders(['Authorization' => 'Bearer '.$plain])->getJson('/api/v1/me')->assertOk();
    }

    public function test_image_importer_only_accepts_public_ipv4_targets(): void
    {
        foreach (['8.8.8.8', '93.184.216.34', '1.1.1.1'] as $ip) {
            $this->assertTrue(ProductImageImporter::isPublicIpv4($ip), $ip);
        }
        foreach (['127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '100.100.100.200', '0.0.0.0', '224.0.0.1', '255.255.255.255', '203.0.113.9', '198.18.0.1', '::1', 'abc'] as $ip) {
            $this->assertFalse(ProductImageImporter::isPublicIpv4($ip), $ip);
        }

        Storage::fake('public');
        Http::fake(); // không request nào được phép đi ra
        $importer = new ProductImageImporter;
        foreach ([
            'http://127.0.0.1/a.png', 'http://localhost/a.png', 'http://169.254.169.254/latest/meta-data/', 'http://100.100.100.200/a.png',
            'http://[::1]/a.png', 'http://[::ffff:127.0.0.1]/a.png', 'http://2130706433/a.png', 'ftp://93.184.216.34/a.png',
            'file:///etc/passwd', 'gopher://93.184.216.34/', 'http:///a.png', '//93.184.216.34/a.png',
        ] as $url) {
            try {
                $importer->fromUrl($url, 'images.0');
                $this->fail("Phải chặn: {$url}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('images.0', $e->errors(), $url);
            }
        }
        Http::assertNothingSent();
    }

    public function test_image_importer_revalidates_every_redirect_hop(): void
    {
        Storage::fake('public');
        $png = $this->png();

        // Chuyển hướng sang địa chỉ nội bộ → chặn, không gọi tới đích nội bộ
        Http::fake([
            '93.184.216.34/to-internal' => Http::response('', 302, ['Location' => 'http://127.0.0.1:6379/']),
            '93.184.216.34/to-meta' => Http::response('', 307, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
            '93.184.216.34/loop' => Http::response('', 302, ['Location' => '/loop']),
            '93.184.216.34/moved' => Http::response('', 301, ['Location' => '/real.png']),
            '93.184.216.34/real.png' => Http::response($png, 200, ['Content-Type' => 'image/png']),
            '93.184.216.34/big' => Http::response(str_repeat('a', ProductImageImporter::MAX_BYTES + 1), 200),
            '93.184.216.34/html' => Http::response('<html><script>alert(1)</script></html>', 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('SECRET', 200),
        ]);
        $importer = new ProductImageImporter;

        foreach (['to-internal', 'to-meta', 'loop', 'big', 'html'] as $path) {
            try {
                $importer->fromUrl("https://93.184.216.34/{$path}", 'images.0');
                $this->fail("Phải chặn: {$path}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('images.0', $e->errors(), $path);
            }
        }
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1') || str_contains($request->url(), '169.254.'));

        // Chuyển hướng tới link công khai (đường dẫn tương đối) vẫn tải được
        $path = $importer->fromUrl('https://93.184.216.34/moved', 'images.0');
        Storage::disk('public')->assertExists($path);
        $this->assertStringEndsWith('.png', $path);
    }

    public function test_variant_row_keys_from_old_input_cannot_inject_markup(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $cat = Category::factory()->create(['type' => Category::TYPE_PRODUCT]);
        $product = Product::factory()->create(['category_id' => $cat->id]);
        $evil = 'x]"><script>alert(1)</script>';

        $this->actingAs($admin, 'web')->from("/backend/product/{$product->id}/edit")
            ->put("/backend/product/{$product->id}", ['name' => '', 'variants' => [$evil => ['name' => 'Đỏ']]])
            ->assertRedirect("/backend/product/{$product->id}/edit");

        $this->actingAs($admin, 'web')->get("/backend/product/{$product->id}/edit")->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('variants[xscriptalert1script][name]', false);
    }

    public function test_only_root_admin_can_change_tracking_code(): void
    {
        $group = Group::create(['name' => 'Sửa cài đặt', 'permission_data' => ['backend_access', 'setting', 'setting/edit']]);
        $editor = User::factory()->create(['group_id' => $group->id]);
        $root = User::factory()->superAdmin()->create();
        $form = fn (array $data) => ['site_name' => 'Thuận Khang', 'code_head' => '', 'code_body' => '', 'code_bottom' => '', ...$data];

        $this->actingAs($root, 'web')->put('/backend/setting', $form(['code_head' => '<script>ga()</script>']))->assertSessionHasNoErrors();
        $this->assertSame('<script>ga()</script>', SiteSettings::all()['code_head']);

        // Người có quyền sửa cài đặt (không phải Root): ô mã bị khóa; tự gửi lên cũng không đổi / không xóa được mã
        $this->actingAs($editor, 'web')->get('/backend/setting?tab=tracking')->assertOk()
            ->assertSee('Chỉ Root admin được sửa mã theo dõi')->assertSee('name="code_head"', false)->assertSee('disabled', false);
        $this->actingAs($editor, 'web')->put('/backend/setting', $form([
            'site_name' => 'Tên mới', 'code_head' => '<script>steal()</script>', 'code_bottom' => '<script>steal()</script>',
        ]))->assertSessionHasNoErrors();
        $all = SiteSettings::all();
        $this->assertSame(['Tên mới', '<script>ga()</script>', ''], [$all['site_name'], $all['code_head'], $all['code_bottom']]);
        $this->get('/')->assertSee('<script>ga()</script>', false)->assertDontSee('steal()', false);

        $this->actingAs($root, 'web')->get('/backend/setting?tab=tracking')->assertDontSee('Chỉ Root admin được sửa mã theo dõi');
    }

    public function test_member_cannot_hold_more_than_the_open_unpaid_order_limit(): void
    {
        AffiliateSettings::save(['shipping_fee' => 30000, 'payment_expiry_hours' => 24]);
        $member = Member::factory()->create();
        $cat = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);
        $product = Product::factory()->create(['category_id' => $cat->id, 'status' => true, 'stock' => 10, 'price' => 100000]);
        $form = ['recipient_name' => 'Nguyễn Văn A', 'recipient_phone' => '0912 345 678', 'delivery_type' => 'ship', 'province' => 'ha-noi', 'address' => '12 Láng Hạ', 'pickup_point_id' => '', 'note' => ''];
        $place = function () use ($product, $form) {
            $this->post('/gio-hang', ['product_id' => $product->id, 'qty' => 1]);

            return $this->post('/thanh-toan', $form);
        };

        $this->actingAs($member, 'member');
        for ($i = 0; $i < Order::MAX_OPEN_UNPAID; $i++) {
            $place()->assertSessionHas('success');
        }
        $this->assertSame(7, $product->fresh()->stock);

        // Đơn thứ 4: bị chặn ở cả trang thanh toán lẫn lúc đặt, không giữ thêm hàng
        $this->post('/gio-hang', ['product_id' => $product->id, 'qty' => 1]);
        $this->get('/thanh-toan')->assertRedirect(route('member.orders.index'))->assertSessionHas('error');
        $this->post('/thanh-toan', $form)->assertRedirect(route('member.orders.index'))->assertSessionHas('error');
        $this->assertSame([Order::MAX_OPEN_UNPAID, 7], [Order::count(), $product->fresh()->stock]);

        // Đơn đã báo chuyển khoản vẫn tính là chưa thanh toán
        $orders = Order::orderBy('id')->get();
        $this->post("/don-hang/{$orders[0]->code}/da-chuyen-khoan", $this->billUpload());
        $this->assertSame(Order::STATUS_AWAITING, $orders[0]->fresh()->status);
        $this->post('/thanh-toan', $form)->assertRedirect(route('member.orders.index'));

        // Hủy một đơn → đặt tiếp được; thành viên khác không bị ảnh hưởng
        $this->post("/don-hang/{$orders[1]->code}/huy");
        $this->post('/thanh-toan', $form)->assertSessionHas('success');
        $this->assertSame(Order::MAX_OPEN_UNPAID + 1, Order::count());

        // Đơn được xác nhận đã thanh toán / đơn quá hạn không còn tính
        $this->assertTrue(Order::openLimitReached($member->id));
        $orders[0]->update(['status' => Order::STATUS_PAID]);
        $this->assertFalse(Order::openLimitReached($member->id));
        $orders[0]->update(['status' => Order::STATUS_NEW, 'expires_at' => now()->subMinute()]);
        $this->assertFalse(Order::openLimitReached($member->id));
        $this->assertFalse(Order::openLimitReached(Member::factory()->create()->id));
    }

    private function png(): string
    {
        $img = imagecreatetruecolor(20, 20);
        ob_start();
        imagepng($img);

        return ob_get_clean();
    }
}
