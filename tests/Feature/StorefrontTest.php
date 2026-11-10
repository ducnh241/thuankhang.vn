<?php

namespace Tests\Feature;

use App\Http\Middleware\CaptureReferralCode;
use App\Models\Category;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Support\AffiliateSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Step 2 – Storefront (S1, S2): trang chủ, danh mục, tìm kiếm, chi tiết sản phẩm; bắt ?ref= trên mọi trang. */
class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    private Category $food;

    private Category $vitamin;

    private Category $hidden;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::put('site_name', 'Thuận Khang');
        $this->food = $this->category('Thực phẩm chức năng', 'thuc-pham-chuc-nang');
        $this->vitamin = $this->category('Vitamin', 'vitamin', $this->food);
        $this->hidden = $this->category('Danh mục ẩn', 'danh-muc-an', null, false);
    }

    private function category(string $name, string $slug, ?Category $parent = null, bool $status = true): Category
    {
        return Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'name' => $name, 'slug' => $slug, 'parent_id' => $parent?->id, 'status' => $status]);
    }

    private function product(array $data): Product
    {
        return Product::factory()->create(['category_id' => $this->food->id, ...$data]);
    }

    public function test_home_page(): void
    {
        $this->product(['name' => 'Dầu Cá Nổi Bật', 'is_featured' => true, 'price' => 480_000]);
        $this->product(['name' => 'Vitamin Con', 'category_id' => $this->vitamin->id]);
        $this->product(['name' => 'Đang Ẩn', 'status' => false, 'is_featured' => true]);
        $this->product(['name' => 'Thuộc Danh Mục Ẩn', 'category_id' => $this->hidden->id, 'is_featured' => true]);
        $this->product(['name' => 'Đã Xóa', 'is_featured' => true])->delete();
        $other = $this->category('Gia dụng', 'gia-dung');
        Product::factory()->create(['category_id' => $other->id, 'name' => 'Nồi Chiên']);

        $this->get('/')->assertOk()
            ->assertSee('<title>Thuận Khang</title>', false)
            ->assertSee('data-join-card', false)->assertSee(route('member.register'))
            ->assertSee('Sản phẩm nổi bật')->assertSee('Dầu Cá Nổi Bật')->assertSee('480.000đ')
            // Link danh mục gốc đang hiện; "Tất cả" gồm cả sản phẩm danh mục con
            ->assertSee('href="/danh-muc/thuc-pham-chuc-nang" data-group="thuc-pham-chuc-nang"', false)->assertSee('data-group="gia-dung"', false)->assertDontSee('Danh mục ẩn')
            ->assertSee('Vitamin Con')->assertSee('Nồi Chiên')->assertSeeInOrder(['data-product-count', '3', 'sản phẩm'], false)
            ->assertSee('data-quick-add', false)->assertSee('data-bottom-nav', false)
            ->assertDontSee('Đang Ẩn')->assertDontSee('Thuộc Danh Mục Ẩn')->assertDontSee('Đã Xóa')
            ->assertDontSee('/backend', false);

        $this->get('/?sort=x')->assertOk()->assertSee('Nồi Chiên');
        $this->get('/?sort[]=1')->assertOk();
        $this->actingAs(User::factory()->superAdmin()->create())->get('/')->assertOk();
    }

    public function test_home_member_card_sold_count_and_quick_add(): void
    {
        $product = $this->product(['name' => 'Trà Gừng']);
        $member = Member::factory()->create(['full_name' => 'Phạm Thu', 'referral_code' => 'THU123']);
        $paid = Order::factory()->status(Order::STATUS_PAID)->create();
        $paid->items()->create(['product_id' => $product->id, 'product_name' => 'Trà Gừng', 'qty' => 3, 'unit_price' => 1, 'vat_rate' => 0, 'line_amount' => 3, 'vat_amount' => 0]);
        $unpaid = Order::factory()->create();
        $unpaid->items()->create(['product_id' => $product->id, 'product_name' => 'Trà Gừng', 'qty' => 50, 'unit_price' => 1, 'vat_rate' => 0, 'line_amount' => 50, 'vat_amount' => 0]);
        Order::factory()->create(['member_id' => $member->id]);

        // Lượt bán chỉ tính đơn đã thanh toán
        $this->get('/')->assertSee('Đã bán 3')->assertDontSee('Đã bán 53');

        $this->actingAs($member, 'member')->get('/')->assertOk()->assertSee('data-member-card', false)->assertDontSee('data-join-card', false)
            ->assertSee('Xin chào, Phạm Thu')->assertSee('THU123')->assertSee($member->referralLink())
            ->assertSee('data-pending-orders', false)->assertSee('<strong>1</strong> đơn chờ thanh toán', false);

        $this->from('/')->post('/gio-hang', ['product_id' => $product->id])->assertRedirect('/')->assertSessionHas('success');
        $this->get('/')->assertSee('data-cart-count>1<', false);
    }

    public function test_category_page(): void
    {
        $this->product(['name' => 'Cha Rẻ', 'price' => 100_000]);
        $this->product(['name' => 'Con Đắt', 'price' => 900_000, 'category_id' => $this->vitamin->id]);
        $this->product(['name' => 'Ẩn', 'status' => false]);

        $html = $this->get('/danh-muc/thuc-pham-chuc-nang?sort=price_desc')->assertOk()
            ->assertSee('<title>Thực phẩm chức năng - Thuận Khang</title>', false)
            ->assertSee('2 sản phẩm')->assertSee('href="/danh-muc/vitamin"', false)->assertDontSee('>Ẩn<', false)->getContent();
        $this->assertLessThan(strpos($html, 'Cha Rẻ'), strpos($html, 'Con Đắt'));
        $html = $this->get('/danh-muc/thuc-pham-chuc-nang?sort=price_asc')->getContent();
        $this->assertLessThan(strpos($html, 'Con Đắt'), strpos($html, 'Cha Rẻ'));

        // Danh mục con: đường dẫn có danh mục cha, chỉ sản phẩm của nó
        $this->get('/danh-muc/vitamin')->assertOk()->assertSee('Con Đắt')->assertDontSee('Cha Rẻ')
            ->assertSee('<a href="/danh-muc/thuc-pham-chuc-nang" class="hover:text-brand-700">Thực phẩm chức năng</a>', false);

        // Danh mục ẩn, con của danh mục ẩn, slug không có, loại khác → 404
        $this->category('Con của ẩn', 'con-cua-an', $this->hidden);
        Category::factory()->create(['type' => Category::TYPE_POST, 'slug' => 'tin-tuc-a']);
        foreach (['danh-muc-an', 'con-cua-an', 'khong-co', 'tin-tuc-a'] as $slug) {
            $this->get("/danh-muc/{$slug}")->assertNotFound();
        }
        $this->get('/danh-muc/thuc-pham-chuc-nang?sort[]=x&page=abc')->assertOk();
    }

    public function test_search(): void
    {
        $this->product(['name' => 'Viên uống Vitamin C', 'sku' => 'VITC-1000']);
        $this->product(['name' => 'Dầu cá Omega', 'short_description' => 'Bổ sung DHA cho trí não', 'category_id' => $this->vitamin->id]);
        $this->product(['name' => 'Vitamin Ẩn', 'status' => false]);
        $other = $this->category('Gia dụng', 'gia-dung');
        $this->product(['name' => 'Vitamin cho bếp', 'category_id' => $other->id]);

        $this->get('/tim-kiem')->assertOk()->assertSee('Nhập từ khóa hoặc chọn danh mục');
        $this->get('/tim-kiem?q=vitamin')->assertOk()->assertSee('Tìm thấy 2 sản phẩm')
            ->assertSee('Viên uống Vitamin C')->assertSee('Vitamin cho bếp')->assertDontSee('Vitamin Ẩn');
        // Nhiều từ = phải có đủ các từ; tìm theo mã, mô tả ngắn
        $this->get('/tim-kiem?q=vitamin+bếp')->assertSee('Tìm thấy 1 sản phẩm')->assertSee('Vitamin cho bếp');
        $this->get('/tim-kiem?q=vitc-1000')->assertSee('Viên uống Vitamin C');
        $this->get('/tim-kiem?q=trí+não')->assertSee('Dầu cá Omega');
        // Lọc danh mục (gồm danh mục con)
        $this->get('/tim-kiem?q=vitamin&category='.$this->food->id)->assertSee('Viên uống Vitamin C')->assertDontSee('Vitamin cho bếp');
        $this->get('/tim-kiem?category='.$this->food->id)->assertSee('Tìm thấy 2 sản phẩm')->assertSee('Dầu cá Omega');
        $this->get('/tim-kiem?q=khongcogi')->assertSee('Không tìm thấy sản phẩm phù hợp');
        $this->get('/tim-kiem?q=50%25_')->assertOk()->assertSee('Không tìm thấy');
        $this->get('/tim-kiem?q[]=x&category=abc')->assertOk();
    }

    public function test_product_page(): void
    {
        $product = $this->product([
            'name' => 'Serum Vitamin C', 'slug' => 'serum-vitamin-c', 'sku' => 'MP-SRVC', 'price' => 550_000, 'vat_rate' => '8',
            'short_description' => 'Dưỡng sáng da',
            'description' => '<h2>Công dụng</h2><p onclick="alert(1)">Làm sáng da</p><script>alert(2)</script><a href="javascript:alert(3)">x</a><a href="https://example.com">Nguồn</a>',
        ]);
        $product->images()->create(['path' => 'products/a.jpg', 'sort_order' => 0]);
        $product->images()->create(['path' => 'products/b.jpg', 'sort_order' => 1]);
        $related = $this->product(['name' => 'Cùng Danh Mục']);

        $this->get('/san-pham/serum-vitamin-c')->assertOk()
            ->assertSee('<title>Serum Vitamin C - Thuận Khang</title>', false)
            ->assertSee('<meta name="description" content="Dưỡng sáng da" />', false)
            ->assertSee('550.000đ')->assertSee('Giá đã gồm VAT 8%')->assertSee('MP-SRVC')
            ->assertSee('data-gallery-thumb', false)->assertSee('/storage/products/b.jpg', false)
            ->assertSee('<h2>Công dụng</h2>', false)->assertSee('Làm sáng da')
            ->assertDontSee('onclick', false)->assertDontSee('<script>alert(2)', false)->assertDontSee('javascript:alert', false)
            ->assertSee('rel="noopener noreferrer nofollow"', false)
            ->assertSee('Sản phẩm cùng danh mục')->assertSee($related->name)
            ->assertSee('href="/danh-muc/thuc-pham-chuc-nang"', false);

        $product->update(['status' => false]);
        $this->get('/san-pham/serum-vitamin-c')->assertNotFound();
        $product->update(['status' => true, 'category_id' => $this->hidden->id]);
        $this->get('/san-pham/serum-vitamin-c')->assertNotFound();
        $product->update(['category_id' => $this->food->id]);
        $product->delete();
        $this->get('/san-pham/serum-vitamin-c')->assertNotFound();
        $this->get('/san-pham/khong-co')->assertNotFound();
    }

    public function test_product_page_sold_count_share_link_and_chat(): void
    {
        $product = $this->product(['name' => 'Trà Gừng', 'slug' => 'tra-gung']);
        $paid = Order::factory()->status(Order::STATUS_PAID)->create();
        $paid->items()->create(['product_id' => $product->id, 'product_name' => 'Trà Gừng', 'qty' => 7, 'unit_price' => 1, 'vat_rate' => 0, 'line_amount' => 7, 'vat_amount' => 0]);
        Setting::put('social_zalo', 'https://zalo.me/0968057988');
        $shareMarker = 'Sao chép link giới thiệu';

        // Khách: lượt bán, chọn số lượng, nút mua (cả thanh dưới điện thoại), chat Zalo; mời đăng ký thay vì link
        $this->get('/san-pham/tra-gung')->assertOk()->assertSee('Đã bán 7')->assertSee('data-qty-step', false)
            ->assertSee('form="buy-form" name="buy_now"', false)->assertSee('href="https://zalo.me/0968057988"', false)
            ->assertSee('Đăng ký thành viên')->assertDontSee($shareMarker);

        // Thành viên chưa kích hoạt: chưa có link
        $inactive = Member::factory()->create(['activated_at' => null]);
        $this->actingAs($inactive, 'member')->get('/san-pham/tra-gung')->assertSee('Mua đơn hàng đầu tiên để kích hoạt')->assertDontSee($shareMarker);

        // Đã kích hoạt: link sản phẩm kèm ?ref=mã của mình
        $active = Member::factory()->create(['referral_code' => 'GIOITHIEU1', 'activated_at' => now()]);
        $this->actingAs($active, 'member')->get('/san-pham/tra-gung')->assertSee($shareMarker)
            ->assertSee(url('/san-pham/tra-gung').'?ref=GIOITHIEU1', false);

        // Zalo không phải link an toàn: không hiện nút chat
        Setting::put('social_zalo', 'javascript:alert(1)');
        $this->get('/san-pham/tra-gung')->assertDontSee('data-chat', false)->assertDontSee('javascript:alert', false);
    }

    public function test_frontend_and_backend_load_their_own_assets(): void
    {
        // Website: chỉ build frontend (vite.frontend.config.js); trang quản trị: chỉ build admin
        $this->get('/')->assertOk()
            ->assertSee('assets/frontend/build/assets/app-', false)->assertDontSee('assets/backend/build', false);
        $this->get('/backend/login')->assertOk()
            ->assertSee('assets/backend/build/assets/app-', false)->assertDontSee('assets/frontend/build', false);
        $this->assertFileExists(public_path('assets/frontend/build/manifest.json'));
        $this->assertArrayNotHasKey('resources/frontend/css/app.css', json_decode(file_get_contents(public_path('assets/backend/build/manifest.json')), true));
    }

    public function test_referral_code_is_remembered_on_any_page(): void
    {
        AffiliateSettings::save(['ref_cookie_days' => 7]);
        $this->product(['slug' => 'mot']);

        foreach (['/?ref=abc123', '/san-pham/mot?ref=abc123', '/tim-kiem?ref=abc123'] as $url) {
            $response = $this->get($url)->assertOk()->assertCookie(CaptureReferralCode::COOKIE, 'ABC123');
            $cookie = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === CaptureReferralCode::COOKIE);
            $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $cookie->getExpiresTime(), 5, $url);
            $this->app['cookie']->flushQueuedCookies(); // test chạy nhiều request trên cùng một app: bỏ cookie đã xếp hàng
        }
        // Mã sai định dạng: bỏ qua
        foreach (['/?ref=a', '/?ref=abc-123', '/?ref[]=x', '/?ref='.str_repeat('A', 21)] as $url) {
            $this->get($url)->assertOk()->assertCookieMissing(CaptureReferralCode::COOKIE);
        }
    }
}
