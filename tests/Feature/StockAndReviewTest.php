<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\User;
use App\Support\Cart;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Tồn kho (trừ khi đặt, trả khi hủy, chặn bán quá), thương hiệu / xuất xứ, đánh giá sao (người đã mua) + admin ẩn / hiện. */
class StockAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);
    }

    private function product(array $data = []): Product
    {
        return Product::factory()->create(['category_id' => $this->category->id, 'status' => true, ...$data]);
    }

    private function checkout(): TestResponse
    {
        return $this->post('/thanh-toan', [
            'recipient_name' => 'Nguyễn Văn A', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship', 'province' => 'ha-noi', 'address' => '1 Láng Hạ',
        ]);
    }

    public function test_product_page_shows_brand_origin_and_stock(): void
    {
        $product = $this->product(['slug' => 'dau-ca', 'brand' => 'Blackmores', 'origin' => 'Úc', 'stock' => 3]);
        $this->get('/san-pham/dau-ca')->assertSee('Blackmores')->assertSee('Úc')->assertSee('Còn 3 sản phẩm')->assertSee('max="3"', false);

        $product->update(['stock' => 0]);
        $this->get('/san-pham/dau-ca')->assertSee('Hết hàng')->assertDontSee('form="buy-form" name="buy_now"', false);
        $this->get('/')->assertSee('Hết hàng');

        $product->update(['stock' => null, 'brand' => null, 'origin' => null]);
        $this->get('/san-pham/dau-ca')->assertDontSee('data-stock', false)->assertDontSee('data-brand-origin', false);
    }

    public function test_cart_respects_stock(): void
    {
        $product = $this->product(['name' => 'Sâm', 'stock' => 3]);
        $soldOut = $this->product(['name' => 'Trà', 'stock' => 0]);

        $this->post('/gio-hang', ['product_id' => $soldOut->id])->assertSessionHas('error', '“Trà” đã hết hàng.');
        $this->post('/gio-hang', ['product_id' => $product->id, 'qty' => 2])->assertSessionHas('success');
        $this->post('/gio-hang', ['product_id' => $product->id, 'qty' => 2])->assertSessionHas('error', '“Sâm” chỉ còn 3 sản phẩm (giỏ đã có 2).');
        $this->patch("/gio-hang/{$product->id}", ['qty' => 9])->assertSessionHas('error', '“Sâm” chỉ còn 3 sản phẩm.');
        $this->assertSame([$product->id => 3], session(Cart::SESSION_KEY));

        // Tồn giảm sau khi đã vào giỏ: giỏ báo, đặt hàng bị chặn
        $product->update(['stock' => 1]);
        $this->get('/gio-hang')->assertSee('Chỉ còn 1 sản phẩm');
        $this->actingAs(Member::factory()->create(), 'member');
        $this->checkout()->assertRedirect(route('shop.cart'))->assertSessionHas('error', 'Không đủ hàng: Sâm (còn 1). Vui lòng giảm số lượng.');
        $this->assertSame(0, Order::count());
        $this->assertSame(1, $product->refresh()->stock);
    }

    public function test_stock_taken_on_order_and_released_on_cancel(): void
    {
        $tracked = $this->product(['stock' => 10]);
        $free = $this->product(['stock' => null]);
        $member = Member::factory()->create();

        $this->actingAs($member, 'member')->post('/gio-hang', ['product_id' => $tracked->id, 'qty' => 4]);
        $this->post('/gio-hang', ['product_id' => $free->id, 'qty' => 2]);
        $this->checkout()->assertSessionHas('success');
        $order = Order::sole();
        $this->assertTrue($order->stock_reserved);
        $this->assertSame([6, null], [$tracked->refresh()->stock, $free->refresh()->stock]);

        // Khách tự hủy → trả kho; hủy lần nữa không cộng thêm
        $this->post(route('member.orders.cancel', $order))->assertSessionHas('success');
        $this->assertSame(10, $tracked->refresh()->stock);
        $order->refresh()->load('items')->releaseStock();
        $this->assertSame(10, $tracked->refresh()->stock);

        // Kế toán khôi phục đơn đã hủy (tiền về muộn) → giữ hàng lại
        $accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $this->actingAs($accountant, 'web')->post(route('backend.orders.confirm-payment', $order), [
            'payment_reference' => 'FT9', 'paid_amount' => (string) $order->total_amount, 'payment_note' => 'Tiền về muộn',
        ])->assertSessionHas('success');
        $this->assertSame(6, $tracked->refresh()->stock);

        // Quá hạn tự hủy và admin hủy cũng trả kho
        $this->actingAs($member, 'member')->post('/gio-hang', ['product_id' => $tracked->id, 'qty' => 1]);
        $this->checkout();
        $expiring = Order::latest('id')->first();
        $expiring->forceFill(['expires_at' => now()->subMinute()])->save();
        $this->assertSame(5, $tracked->refresh()->stock);
        $this->artisan('orders:expire')->assertSuccessful();
        $this->assertSame(6, $tracked->refresh()->stock);

        $this->post('/gio-hang', ['product_id' => $tracked->id, 'qty' => 2]);
        $this->checkout();
        $this->assertSame(4, $tracked->refresh()->stock);
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $this->actingAs($ops, 'web')->post(route('backend.orders.cancel', Order::latest('id')->first()), ['reason' => 'Khách đổi ý'])->assertSessionHas('success');
        $this->assertSame(6, $tracked->refresh()->stock);
    }

    public function test_only_buyers_can_review_and_edit_their_review(): void
    {
        $product = $this->product(['name' => 'Trà Gừng', 'slug' => 'tra-gung']);
        $buyer = Member::factory()->create(['full_name' => 'Nguyễn Văn An']);
        $paid = Order::factory()->status(Order::STATUS_PAID)->create(['member_id' => $buyer->id]);
        $paid->items()->create(['product_id' => $product->id, 'product_name' => 'Trà Gừng', 'qty' => 1, 'unit_price' => 1, 'vat_rate' => 0, 'line_amount' => 1, 'vat_amount' => 0]);
        $unpaidBuyer = Member::factory()->create();
        $unpaid = Order::factory()->create(['member_id' => $unpaidBuyer->id]);
        $unpaid->items()->create(['product_id' => $product->id, 'product_name' => 'Trà Gừng', 'qty' => 1, 'unit_price' => 1, 'vat_rate' => 0, 'line_amount' => 1, 'vat_amount' => 0]);
        $url = route('shop.product.review', 'tra-gung');

        $this->get('/san-pham/tra-gung')->assertSee('Chưa có đánh giá nào')->assertSee('để đánh giá sản phẩm bạn đã mua');
        $this->post($url, ['rating' => 5])->assertRedirect(route('member.login'));
        $this->actingAs($unpaidBuyer, 'member')->get('/san-pham/tra-gung')->assertDontSee('data-review-form', false);
        $this->post($url, ['rating' => 5])->assertSessionHas('error');
        $this->assertSame(0, ProductReview::count());

        $this->actingAs($buyer, 'member')->get('/san-pham/tra-gung')->assertSee('data-review-form', false)->assertSee('Viết đánh giá');
        $this->post($url, ['rating' => 6])->assertSessionHasErrors('rating');
        $this->post($url, ['rating' => 4, 'comment' => 'Thơm, dễ uống'])->assertRedirect('/san-pham/tra-gung#danh-gia')->assertSessionHas('success', 'Cảm ơn bạn đã đánh giá sản phẩm!');
        $this->post($url, ['rating' => 5, 'comment' => 'Rất thơm'])->assertSessionHas('success', 'Đã cập nhật đánh giá của bạn.');
        $review = ProductReview::sole();
        $this->assertSame([5, 'Rất thơm'], [$review->rating, $review->comment]);

        $this->get('/san-pham/tra-gung')->assertSee('Sửa đánh giá của bạn')->assertSee('Rất thơm')->assertSee('Nguyễn V. An')
            ->assertSee('(1 đánh giá)')->assertSee('5,0');
        $this->get('/')->assertSee('data-rating', false);
    }

    public function test_admin_hides_and_shows_reviews(): void
    {
        $product = $this->product(['name' => 'Trà Gừng', 'slug' => 'tra-gung']);
        $member = Member::factory()->create(['full_name' => 'Lê Bình']);
        $review = ProductReview::create(['product_id' => $product->id, 'member_id' => $member->id, 'rating' => 1, 'comment' => 'Spam quảng cáo']);
        $cskh = User::factory()->create(['group_id' => Group::where('name', 'CSKH')->value('id')]);

        $this->actingAs($cskh)->get('/backend')->assertSee(route('backend.reviews.index'), false);
        $this->get('/backend/review')->assertOk()->assertSee('Spam quảng cáo')->assertSee('Trà Gừng')->assertSee('Lê Bình');
        $this->get('/backend/review?rating=5')->assertDontSee('Spam quảng cáo');
        $this->get('/backend/review?q=spam')->assertSee('Spam quảng cáo');
        $this->get('/backend/review?tab=x&rating=9&q[]=1')->assertOk();

        $this->post(route('backend.reviews.visibility', $review), ['action' => 'hide', 'reason' => 'Nội dung quảng cáo'])->assertSessionHas('success');
        $this->assertFalse($review->refresh()->is_visible);
        $this->assertTrue(AuditLog::where('auditable_type', ProductReview::class)->where('description', 'like', '%Nội dung quảng cáo%')->exists());
        $this->get('/san-pham/tra-gung')->assertDontSee('Spam quảng cáo')->assertSee('Chưa có đánh giá nào');
        $this->get('/backend/review?tab=hidden')->assertSee('Spam quảng cáo');

        // Người viết vẫn thấy mình bị ẩn khi mở form (nếu đã mua)
        $this->post(route('backend.reviews.visibility', $review), ['action' => 'show'])->assertSessionHas('success');
        $this->assertTrue($review->refresh()->is_visible);

        $accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $this->actingAs($accountant)->get('/backend/review')->assertForbidden();
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'review')->create()->id]);
        $this->actingAs($viewer)->get('/backend/review')->assertOk()->assertDontSee(route('backend.reviews.visibility', $review), false);
        $this->post(route('backend.reviews.visibility', $review), ['action' => 'hide'])->assertForbidden();
    }
}
