<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Member;
use App\Models\Order;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Support\AffiliateSettings;
use App\Support\Cart;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 5 – Giỏ hàng & checkout (S4, S5, S7): giỏ trong session, bắt đăng nhập khi thanh toán, giao tận nơi (ship theo
 * Tham số chung) / nhận tại VP (ship 0, chỉ điểm đang bật), tạo đơn "Chờ thanh toán" + snapshot, đơn của tôi.
 */
class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        AffiliateSettings::save(['shipping_fee' => 30000, 'payment_expiry_hours' => 24]);
        $this->category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);
    }

    private function product(array $data = []): Product
    {
        return Product::factory()->create(['category_id' => $this->category->id, 'status' => true, ...$data]);
    }

    private function form(array $data = []): array
    {
        return [
            'recipient_name' => 'Nguyễn Văn A', 'recipient_phone' => '0912 345 678', 'delivery_type' => 'ship',
            'province' => 'ha-noi', 'address' => '12 Láng Hạ', 'pickup_point_id' => '', 'note' => '', ...$data,
        ];
    }

    public function test_cart_add_update_remove(): void
    {
        $a = $this->product(['name' => 'Sản phẩm A', 'price' => 110000]);
        $b = $this->product(['name' => 'Sản phẩm B', 'price' => 50000]);
        $hidden = $this->product(['status' => false]);

        $this->get($a->url())->assertOk()->assertSee('Thêm vào giỏ')->assertSee('Mua ngay')->assertSee(route('shop.cart.store'), false);
        $this->post('/gio-hang', ['product_id' => $a->id, 'qty' => 2])->assertRedirect()->assertSessionHas('success');
        $this->post('/gio-hang', ['product_id' => $b->id])->assertSessionHas('success');
        $this->post('/gio-hang', ['product_id' => $hidden->id])->assertSessionHas('error');
        $this->post('/gio-hang', ['product_id' => $a->id, 'qty' => 100])->assertSessionHasErrors('qty');
        $this->assertSame([$a->id => 2, $b->id => 1], session(Cart::SESSION_KEY));

        $this->get('/gio-hang')->assertOk()->assertSee('Sản phẩm A')->assertSee('220.000đ')->assertSee('270.000đ')
            ->assertSee('data-cart-count>3<', false);

        $this->patch("/gio-hang/{$a->id}", ['qty' => 5]);
        $this->delete("/gio-hang/{$b->id}");
        $this->assertSame([$a->id => 5], session(Cart::SESSION_KEY));

        // Sản phẩm ngừng bán sau khi đã vào giỏ: tự bỏ, báo khách
        $a->update(['status' => false]);
        $this->get('/gio-hang')->assertOk()->assertSee('Giỏ hàng đang trống')->assertSee('không còn bán');
        $this->assertSame([], session(Cart::SESSION_KEY));
    }

    public function test_checkout_requires_member_login(): void
    {
        $product = $this->product();
        $this->post('/gio-hang', ['product_id' => $product->id, 'buy_now' => 1])->assertRedirect(route('shop.checkout'));
        $this->get('/thanh-toan')->assertRedirect(route('member.login'));
        $this->post('/thanh-toan', $this->form())->assertRedirect(route('member.login'));
        $this->assertSame(0, Order::count());
    }

    public function test_place_order_with_home_delivery(): void
    {
        $member = Member::factory()->create(['full_name' => 'Trần Thị B', 'phone' => '0987654321']);
        $a = $this->product(['name' => 'Sâm', 'sku' => 'SAM-1', 'price' => 1080000, 'vat_rate' => 8]);
        $b = $this->product(['name' => 'Trà', 'price' => 110000, 'vat_rate' => 10]);
        $this->actingAs($member, 'member')->get('/thanh-toan')->assertRedirect(route('shop.cart'));

        $this->post('/gio-hang', ['product_id' => $a->id, 'qty' => 2]);
        $this->post('/gio-hang', ['product_id' => $b->id, 'qty' => 1]);
        $this->get('/thanh-toan')->assertOk()->assertSee('Trần Thị B')->assertSee('0987654321')->assertSee('2.270.000đ')->assertSee('2.300.000đ');

        $response = $this->post('/thanh-toan', $this->form(['note' => 'Giao giờ hành chính']));
        $order = Order::sole();
        $response->assertRedirect(route('member.orders.show', $order))->assertSessionHas('success');

        $this->assertMatchesRegularExpression('/^TK[2-9A-HJKMNP-Z]{8}$/', $order->code);
        AffiliateSettings::save(['order_code_prefix' => 'THK']);
        $this->assertMatchesRegularExpression('/^THK[2-9A-HJKMNP-Z]{8}$/', Order::generateCode());
        $this->assertSame([Order::STATUS_NEW, $member->id, 2270000, 30000, 2300000], [$order->status, $order->member_id, $order->goods_amount, $order->shipping_fee, $order->total_amount]);
        $this->assertSame(160000 + 10000, $order->vat_amount); // 2.160.000 gồm 8% → 160.000; 110.000 gồm 10% → 10.000
        $this->assertSame(['0912345678', 'ha-noi', '12 Láng Hạ, Hà Nội', null], [$order->recipient_phone, $order->province, $order->shippingAddress(), $order->pickup_point_id]);
        $this->assertTrue($order->expires_at->between(now()->addHours(24)->subMinute(), now()->addHours(24)->addMinute()));

        $item = $order->items()->where('product_id', $a->id)->sole();
        $this->assertSame(['Sâm', 'SAM-1', 2, 1080000, 2160000, 160000], [$item->product_name, $item->sku, $item->qty, $item->unit_price, $item->line_amount, $item->vat_amount]);
        $this->assertSame([], session(Cart::SESSION_KEY, []));

        // Snapshot: đổi giá / tên sau khi đặt không ảnh hưởng đơn
        $a->update(['name' => 'Sâm mới', 'price' => 999000]);
        $this->get(route('member.orders.show', $order))->assertOk()->assertSee($order->code)
            ->assertSee('Đơn mới')->assertSee('Sâm')->assertDontSee('Sâm mới')->assertSee('2.300.000đ')->assertSee('12 Láng Hạ, Hà Nội');
        $this->get('/don-hang')->assertOk()->assertSee($order->code)->assertSee('2.300.000đ');
    }

    public function test_place_order_with_pickup_point(): void
    {
        $member = Member::factory()->create();
        $point = PickupPoint::factory()->create(['name' => 'VP Đà Nẵng', 'province' => 'da-nang', 'address' => '5 Bạch Đằng']);
        $off = PickupPoint::factory()->create(['province' => 'da-nang', 'active' => false]);
        $product = $this->product(['price' => 500000]);

        $this->actingAs($member, 'member')->post('/gio-hang', ['product_id' => $product->id]);
        $this->get('/thanh-toan')->assertOk()->assertSee('VP Đà Nẵng')->assertDontSee($off->name);

        $this->post('/thanh-toan', $this->form(['delivery_type' => 'pickup', 'pickup_point_id' => (string) $off->id, 'province' => '', 'address' => '']))
            ->assertSessionHasErrors(['pickup_point_id' => 'Điểm nhận hàng này không còn nhận đơn. Vui lòng chọn điểm khác.']);
        $this->post('/thanh-toan', $this->form(['delivery_type' => 'pickup', 'pickup_point_id' => '', 'province' => '', 'address' => '']))
            ->assertSessionHasErrors('pickup_point_id');
        $this->assertSame(0, Order::count());

        $this->post('/thanh-toan', $this->form(['delivery_type' => 'pickup', 'pickup_point_id' => (string) $point->id, 'province' => 'ho-chi-minh', 'address' => 'bỏ qua']));
        $order = Order::sole();
        $this->assertSame([Order::DELIVERY_PICKUP, $point->id, 0, 500000, null, null], [$order->delivery_type, $order->pickup_point_id, $order->shipping_fee, $order->total_amount, $order->province, $order->address]);
        $this->get(route('member.orders.show', $order))->assertSee('VP Đà Nẵng')->assertSee('5 Bạch Đằng');
    }

    public function test_checkout_validation(): void
    {
        $member = Member::factory()->create();
        $this->actingAs($member, 'member')->post('/gio-hang', ['product_id' => $this->product()->id]);

        foreach ([
            'recipient_name' => ['recipient_name' => ''], 'recipient_phone' => ['recipient_phone' => '12345'],
            'delivery_type' => ['delivery_type' => 'drone'], 'province' => ['province' => 'sai'], 'address' => ['address' => ''],
        ] as $field => $data) {
            $this->post('/thanh-toan', $this->form($data))->assertSessionHasErrors($field);
        }
        $this->assertSame(0, Order::count());

        // Sản phẩm vừa ngừng bán lúc bấm đặt: không tạo đơn, quay lại giỏ
        Product::query()->update(['status' => false]);
        $this->post('/thanh-toan', $this->form())->assertRedirect(route('shop.cart'))->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    public function test_member_sees_only_own_orders_and_payment_info(): void
    {
        AffiliateSettings::save(['bank_code' => 'MB', 'bank_account' => '0123456789', 'bank_account_name' => 'CONG TY THUAN KHANG']);
        $member = Member::factory()->create();
        $mine = Order::factory()->create(['member_id' => $member->id, 'total_amount' => 1230000]);
        $overdue = Order::factory()->create(['member_id' => $member->id, 'expires_at' => now()->subMinute()]);
        $other = Order::factory()->create();

        $this->actingAs($member, 'member')->get(route('member.orders.show', $mine))->assertOk()
            ->assertSee('Chuyển khoản để hoàn tất')->assertSee('0123456789')->assertSee('CONG TY THUAN KHANG')->assertSee('1.230.000đ')
            ->assertSee('https://img.vietqr.io/image/MB-0123456789-compact2.png?amount=1230000&amp;addInfo='.$mine->code, false)
            ->assertSee('Tôi đã chuyển khoản');
        $this->get(route('member.orders.show', $overdue))->assertOk()->assertSee('Đã hủy')->assertSee('Quá hạn thanh toán')
            ->assertDontSee('Chuyển khoản để hoàn tất')->assertDontSee('data-qr', false);
        $this->get(route('member.orders.show', $other))->assertNotFound();
        $this->post(route('member.orders.transferred', $other))->assertNotFound();
        $this->post(route('member.orders.cancel', $other))->assertNotFound();
        $this->get('/don-hang')->assertSee($mine->code)->assertSee($overdue->code)->assertDontSee($other->code);
    }

    public function test_member_reports_transfer_and_cancels(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->create(['member_id' => $member->id]);
        $toCancel = Order::factory()->create(['member_id' => $member->id]);
        $overdue = Order::factory()->create(['member_id' => $member->id, 'expires_at' => now()->subMinute()]);

        // Báo đã chuyển khoản → Chờ xác nhận, không còn bị quá hạn, không tự hủy được nữa
        $this->actingAs($member, 'member')->post(route('member.orders.transferred', $order))->assertSessionHas('success');
        $order->refresh();
        $this->assertSame(Order::STATUS_AWAITING, $order->status);
        $this->assertNotNull($order->confirm_requested_at);
        $this->travel(2)->days();
        $this->assertFalse($order->refresh()->isOverdue());
        $this->get(route('member.orders.show', $order))->assertSee('Đang chờ xác nhận thanh toán')->assertDontSee('Tôi đã chuyển khoản');
        $this->post(route('member.orders.cancel', $order))->assertSessionHas('error');
        $this->assertSame(Order::STATUS_AWAITING, $order->refresh()->status);
        $this->travelBack();

        $this->post(route('member.orders.cancel', $toCancel), ['reason' => 'Đặt nhầm'])->assertSessionHas('success');
        $toCancel->refresh();
        $this->assertSame([Order::STATUS_CANCELLED, Order::CANCEL_CUSTOMER, 'Khách tự hủy: Đặt nhầm'], [$toCancel->status, $toCancel->cancel_source, $toCancel->cancel_reason]);

        // Đơn quá hạn: không báo chuyển khoản / hủy được nữa
        $this->post(route('member.orders.transferred', $overdue))->assertSessionHas('error');
        $this->assertSame(Order::STATUS_NEW, $overdue->refresh()->status);
    }

    public function test_expire_command_cancels_overdue_new_orders_only(): void
    {
        $overdue = Order::factory()->create(['expires_at' => now()->subMinute()]);
        $fresh = Order::factory()->create();
        $awaiting = Order::factory()->create(['status' => Order::STATUS_AWAITING, 'expires_at' => now()->subDay()]);

        $this->artisan('orders:expire')->expectsOutput('Đã hủy 1 đơn quá hạn.')->assertSuccessful();
        $overdue->refresh();
        $this->assertSame([Order::STATUS_CANCELLED, Order::CANCEL_EXPIRED, 'Quá hạn thanh toán'], [$overdue->status, $overdue->cancel_source, $overdue->cancel_reason]);
        $this->assertSame(Order::STATUS_NEW, $fresh->refresh()->status);
        $this->assertSame(Order::STATUS_AWAITING, $awaiting->refresh()->status);
    }
}
