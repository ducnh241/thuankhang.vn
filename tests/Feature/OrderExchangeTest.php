<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Commission;
use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderExchange;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Đổi sản phẩm trong đơn (chốt 26/11/2026): một đổi một, một đổi nhiều, nhiều đổi một; tổng tiền hàng mới phải bằng đúng tổng
 * cũ; chỉ đơn mới / chờ xác nhận / đã thanh toán; giá thêm = giá hiện tại; tồn kho trả / trừ; lý do bắt buộc; lịch sử; quyền
 * order/exchange (Vận hành, CSKH); xem đơn như khách thấy.
 */
class OrderExchangeTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);
    }

    private function product(string $name, int $price, ?int $stock = null): Product
    {
        return Product::factory()->create(['category_id' => $this->category->id, 'status' => true, 'name' => $name, 'price' => $price, 'stock' => $stock]);
    }

    /** Đơn có các dòng [product, qty] (giá theo sản phẩm), đã giữ hàng như lúc đặt. */
    private function order(array $lines, string $status = Order::STATUS_PAID): Order
    {
        $goods = array_sum(array_map(fn ($l) => $l[0]->price * $l[1], $lines));
        $order = Order::factory()->create(['member_id' => Member::factory()->create()->id, 'goods_amount' => $goods, 'total_amount' => $goods]);
        foreach ($lines as [$product, $qty]) {
            $order->items()->create(['product_id' => $product->id, 'product_name' => $product->name, 'qty' => $qty, 'unit_price' => $product->price, 'line_amount' => $product->price * $qty]);
        }
        $order->load('items')->takeStock();
        $order->forceFill(['status' => $status, 'paid_at' => $status === Order::STATUS_PAID ? now() : null])->save();

        return $order->fresh();
    }

    private function staff(string $group): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $group)->value('id')]);
    }

    private function exchange(Order $order, array $keep, array $adds, string $reason = 'Khách đổi sang loại khác')
    {
        return $this->from(route('backend.orders.exchange', $order))->post(route('backend.orders.exchange.store', $order), [
            'keep' => $keep, 'adds' => $adds, 'reason' => $reason,
        ]);
    }

    private function items(Order $order): array
    {
        return $order->items()->orderBy('id')->get()->map(fn ($i) => [$i->product_name, $i->variant_name, $i->qty, $i->unit_price, $i->line_amount])->all();
    }

    public function test_one_for_one_one_for_many_and_many_for_one_keep_the_total(): void
    {
        $tea = $this->product('Trà sâm', 600000, 10);
        $honey = $this->product('Mật ong', 600000, 5);
        $candy = $this->product('Kẹo sâm', 200000, 20);
        $jar = $this->product('Hũ quà', 1200000, 3);
        $order = $this->order([[$tea, 2]]); // 1.200.000
        $ops = $this->staff('Vận hành');
        $this->actingAs($ops, 'web');

        // Trang đơn: có nút đổi + nút xem trên website; trang đổi hiện tổng cũ và danh sách để chọn
        $this->get(route('backend.orders.show', $order))->assertOk()->assertSee('id="order-exchange"', false)->assertSee(route('backend.orders.preview', $order), false);
        $this->get(route('backend.orders.exchange', $order))->assertOk()->assertSee('data-old-total="1200000"', false)
            ->assertSee('data-picker-search', false)->assertSee('data-exchange-options', false)->assertSee('data-item-thumb', false)
            ->assertViewHas('options', fn ($options) => $options->firstWhere('label', 'Mật ong') === ['value' => (string) $honey->id, 'label' => 'Mật ong', 'price' => 600000, 'stock' => 5, 'thumb' => null]
                && $options->firstWhere('label', 'Kẹo sâm')['stock'] === 20);

        // 1 đổi 1: một hộp trà → một hũ mật ong (cùng giá)
        $this->exchange($order, [$order->items[0]->id => 1], [['product' => (string) $honey->id, 'qty' => 1]])->assertSessionHas('success');
        $this->assertSame([['Trà sâm', null, 1, 600000, 600000], ['Mật ong', null, 1, 600000, 600000]], $this->items($order));
        $this->assertSame([9, 4], [$tea->fresh()->stock, $honey->fresh()->stock]);

        // 1 đổi nhiều: hũ mật ong → 3 gói kẹo
        $honeyLine = $order->items()->where('product_name', 'Mật ong')->sole();
        $teaLine = $order->items()->where('product_name', 'Trà sâm')->sole();
        $this->exchange($order, [$teaLine->id => 1, $honeyLine->id => 0], [['product' => (string) $candy->id, 'qty' => 3]])->assertSessionHas('success');
        $this->assertSame([['Trà sâm', null, 1, 600000, 600000], ['Kẹo sâm', null, 3, 200000, 600000]], $this->items($order));
        $this->assertSame([5, 17], [$honey->fresh()->stock, $candy->fresh()->stock]);

        // Nhiều đổi 1: trà + 3 kẹo → 1 hũ quà
        $ids = $order->items()->pluck('id')->all();
        $this->exchange($order, array_fill_keys($ids, 0), [['product' => (string) $jar->id, 'qty' => 1]])->assertSessionHas('success');
        $this->assertSame([['Hũ quà', null, 1, 1200000, 1200000]], $this->items($order));
        $this->assertSame([10, 20, 2], [$tea->fresh()->stock, $candy->fresh()->stock, $jar->fresh()->stock]);

        // Tiền đơn không đổi; lưu đủ 3 lần đổi + nhật ký
        $order->refresh();
        $this->assertSame([1200000, 1200000, Order::STATUS_PAID], [$order->goods_amount, $order->total_amount, $order->status]);
        $this->assertSame(3, OrderExchange::where('order_id', $order->id)->count());
        $last = OrderExchange::latest('id')->first();
        $this->assertSame([$ops->id, 'Khách đổi sang loại khác', 1200000], [$last->user_id, $last->reason, $last->goods_amount]);
        $this->assertSame(['Trà sâm', 'Kẹo sâm'], array_column($last->before_items, 'name'));
        $this->assertSame(['Hũ quà'], array_column($last->after_items, 'name'));
        $this->assertTrue(AuditLog::where('auditable_type', Order::class)->where('auditable_id', $order->id)->where('description', 'like', 'Đổi sản phẩm đơn%')->exists());

        // Trang đơn admin: lịch sử 3 lần đổi; trang khách: thông báo đã đổi + sản phẩm mới
        $this->get(route('backend.orders.show', $order))->assertSee('Lịch sử đổi sản phẩm (3)')->assertSee('+ Hũ quà × 1 (1.200.000 đ)')
            ->assertSee('− Kẹo sâm × 3 (600.000 đ)')->assertSee('Lý do: Khách đổi sang loại khác');
        $this->actingAs($order->member, 'member')->get(route('member.orders.show', $order))->assertOk()
            ->assertSee('Đơn đã được đổi sản phẩm ngày')->assertSee('Hũ quà')->assertDontSee('Trà sâm');
    }

    public function test_history_shows_only_the_changed_lines(): void
    {
        $soap = $this->product('Nước rửa chén', 45000);
        $shoes = $this->product('Vớ thể thao', 167000);
        $polo = $this->product('Áo polo nữ', 1000000);
        $orange = ProductVariant::create(['product_id' => $polo->id, 'name' => 'Cam đào', 'price' => 1560000, 'active' => true]);
        $grey = ProductVariant::create(['product_id' => $polo->id, 'name' => 'Xám bạc', 'price' => 1560000, 'active' => true]);
        $order = $this->order([[$soap, 10], [$shoes, 1]]);
        $line = $order->items()->create(['product_id' => $polo->id, 'variant_id' => $orange->id, 'product_name' => $polo->name, 'variant_name' => 'Cam đào', 'qty' => 1, 'unit_price' => 1560000, 'line_amount' => 1560000]);
        $order->forceFill(['goods_amount' => 2177000, 'total_amount' => 2177000])->save();
        $this->actingAs($this->staff('Vận hành'), 'web');

        // Đổi màu áo: chỉ một dòng thay đổi, hai dòng còn lại giữ nguyên
        $this->exchange($order, [$line->id => 0], [['product' => $polo->id.':'.$grey->id, 'qty' => 1]], 'Khách đổi màu')->assertSessionHas('success');
        $this->assertSame(['removed' => [['name' => 'Áo polo nữ', 'variant' => 'Cam đào', 'qty' => 1, 'unit_price' => 1560000, 'line_amount' => 1560000]],
            'added' => [['name' => 'Áo polo nữ', 'variant' => 'Xám bạc', 'qty' => 1, 'unit_price' => 1560000, 'line_amount' => 1560000]], 'unchanged' => 2],
            OrderExchange::sole()->changes());

        $this->get(route('backend.orders.show', $order))
            ->assertSee('− Áo polo nữ — Cam đào × 1 (1.560.000 đ)')->assertSee('+ Áo polo nữ — Xám bạc × 1 (1.560.000 đ)')
            ->assertSee('Giữ nguyên 2 sản phẩm khác')->assertDontSee('Nước rửa chén × 10 (450.000 đ)')->assertDontSee('Vớ thể thao × 1');
        $this->assertTrue(AuditLog::where('description', 'like', '%bớt Áo polo nữ — Cam đào × 1 → thêm Áo polo nữ — Xám bạc × 1%')->exists());

        // Thẻ "Lịch sử": dòng đổi sản phẩm ngắn gọn, đúng thứ tự thời gian (đặt hàng → thanh toán → đổi), không lặp nhật ký dài
        $this->travel(1)->minutes();
        AuditLog::record('updated', $order, null, 'Ghi chú sau khi đổi');
        $html = $this->get(route('backend.orders.show', $order))->getContent();
        preg_match_all('/<div class="[^"]*" data-event>(.*?)<\/div>/s', $html, $m);
        $events = array_map(fn ($e) => trim(preg_replace('/\s+/', ' ', strip_tags($e))), $m[1]);
        $this->assertCount(4, $events);
        $this->assertStringContainsString('Đặt hàng', $events[0]);
        $this->assertStringContainsString('Xác nhận thanh toán', $events[1]);
        $this->assertStringEndsWith('Đổi sản phẩm: bớt Áo polo nữ — Cam đào × 1 → thêm Áo polo nữ — Xám bạc × 1 · Lý do: Khách đổi màu', $events[2]);
        $this->assertStringEndsWith('Ghi chú sau khi đổi', $events[3]);
        $this->assertStringNotContainsString('Nước rửa chén', implode(' ', $events));

        // Bớt số lượng của một dòng → chỉ hiện phần bớt
        $soapLine = $order->items()->where('product_id', $soap->id)->sole();
        $this->exchange($order, [$soapLine->id => 4], [['product' => (string) $shoes->id, 'qty' => 1], ['product' => (string) $soap->id, 'qty' => 2]])->assertSessionHas('error');
        $candy = $this->product('Kẹo', 270000);
        $this->exchange($order, [$soapLine->id => 4], [['product' => (string) $candy->id, 'qty' => 1]], 'Bớt nước rửa chén')->assertSessionHas('success');
        $this->assertSame(['Nước rửa chén', 6, 270000], [OrderExchange::latest('id')->first()->changes()['removed'][0]['name'],
            OrderExchange::latest('id')->first()->changes()['removed'][0]['qty'], OrderExchange::latest('id')->first()->changes()['removed'][0]['line_amount']]);
    }

    public function test_cannot_exchange_an_item_for_itself(): void
    {
        $polo = $this->product('Áo polo nữ', 1000000);
        $orange = ProductVariant::create(['product_id' => $polo->id, 'name' => 'Cam đào', 'price' => 500000, 'active' => true]);
        $grey = ProductVariant::create(['product_id' => $polo->id, 'name' => 'Xám bạc', 'price' => 500000, 'active' => true]);
        $soap = $this->product('Nước rửa chén', 50000);
        $order = $this->order([[$soap, 10]]);
        $poloLine = $order->items()->create(['product_id' => $polo->id, 'variant_id' => $orange->id, 'product_name' => $polo->name, 'variant_name' => 'Cam đào', 'qty' => 2, 'unit_price' => 500000, 'line_amount' => 1000000]);
        $soapLine = $order->items()->where('product_id', $soap->id)->sole();
        $order->forceFill(['goods_amount' => 1500000, 'total_amount' => 1500000])->save();
        $this->actingAs($this->staff('Vận hành'), 'web');

        // Bỏ / bớt rồi thêm lại đúng sản phẩm, đúng phân loại → chặn (kể cả sản phẩm không có phân loại)
        $this->exchange($order, [$poloLine->id => 0], [['product' => $polo->id.':'.$orange->id, 'qty' => 2]])
            ->assertSessionHas('error', 'Không đổi sang chính sản phẩm đang bớt: “Áo polo nữ — Cam đào”.');
        $this->exchange($order, [$poloLine->id => 1], [['product' => $polo->id.':'.$orange->id, 'qty' => 1]])
            ->assertSessionHas('error', 'Không đổi sang chính sản phẩm đang bớt: “Áo polo nữ — Cam đào”.');
        $this->exchange($order, [$soapLine->id => 0], [['product' => (string) $soap->id, 'qty' => 10]])
            ->assertSessionHas('error', 'Không đổi sang chính sản phẩm đang bớt: “Nước rửa chén”.');
        $this->assertSame(0, OrderExchange::count());

        // Đổi sang phân loại khác của cùng sản phẩm: được; thêm sản phẩm đang có nhưng không bớt (giữ nguyên): được
        $this->exchange($order, [$poloLine->id => 1], [['product' => $polo->id.':'.$grey->id, 'qty' => 1]])->assertSessionHas('success');
        $greyLine = $order->items()->where('variant_id', $grey->id)->sole();
        $this->exchange($order, [$greyLine->id => 0], [['product' => (string) $soap->id, 'qty' => 10]])->assertSessionHas('success');
        $this->assertSame([['Nước rửa chén', null, 20, 50000, 1000000], ['Áo polo nữ', 'Cam đào', 1, 500000, 500000]], $this->items($order)); // gộp vào dòng cũ

        // Trang đổi: mỗi dòng có giá trị sản phẩm để bộ chọn khóa đúng sản phẩm đang bớt
        $this->get(route('backend.orders.exchange', $order))->assertSee('data-item-value="'.$polo->id.':'.$orange->id.'"', false)
            ->assertSee('data-item-value="'.$soap->id.'"', false);
    }

    public function test_tampered_and_stale_requests_are_rejected(): void
    {
        $tea = $this->product('Trà sâm', 600000, 10);
        $honey = $this->product('Mật ong', 300000, 10);
        $free = $this->product('Quà tặng', 0, 10);
        $other = $this->product('Tinh dầu', 100000);
        $foreign = ProductVariant::create(['product_id' => $other->id, 'name' => 'Sả', 'active' => true]);
        $order = $this->order([[$tea, 2]]);
        $line = $order->items[0];
        $otherOrder = $this->order([[$honey, 1]]);
        $this->actingAs($this->staff('Vận hành'), 'web');
        $swap = [['product' => (string) $honey->id, 'qty' => 4]];

        // Lý do quá dài (bỏ qua maxlength của trình duyệt) → báo lỗi, không lỗi 500
        $this->exchange($order, [$line->id => 0], $swap, str_repeat('a', 301))->assertSessionHas('error', 'Lý do đổi tối đa 300 ký tự.');
        // Sản phẩm 0đ (không cho thêm hàng tặng), quá nhiều dòng, phân loại của sản phẩm khác, phân loại đã tắt
        $this->exchange($order, [$line->id => 1], [['product' => (string) $honey->id, 'qty' => 2], ['product' => (string) $free->id, 'qty' => 5]])
            ->assertSessionHas('error', '“Quà tặng” chưa có giá bán — không thêm vào đơn được.');
        $this->exchange($order, [$line->id => 0], array_fill(0, 31, ['product' => (string) $honey->id, 'qty' => 1]))
            ->assertSessionHas('error', 'Thêm tối đa 30 dòng sản phẩm mỗi lần đổi.');
        $this->exchange($order, [$line->id => 0], [['product' => $honey->id.':'.$foreign->id, 'qty' => 4]])
            ->assertSessionHas('error', '“Mật ong”: phân loại đã chọn không còn bán.');
        // Dòng của đơn khác / dữ liệu sai kiểu bị bỏ qua hoặc chặn
        $this->exchange($order, [$otherOrder->items[0]->id => 0], [])->assertSessionHas('error', 'Chưa có thay đổi nào.');
        $this->post(route('backend.orders.exchange.store', $order), ['keep' => [$line->id => ['x']], 'adds' => 'abc', 'reason' => 'Thử dữ liệu sai'])
            ->assertSessionHas('error', 'Số lượng giữ lại của “Trà sâm” phải từ 0 đến 2.');
        $this->post(route('backend.orders.exchange.store', $order), ['keep' => 'abc', 'adds' => [['product' => ['x'], 'qty' => ['y']]], 'reason' => ['z']])
            ->assertSessionHas('error', 'Nhập lý do đổi (ít nhất 5 ký tự).');
        $this->assertSame([0, 8, 10, 9], [OrderExchange::count(), $tea->fresh()->stock, $free->fresh()->stock, $honey->fresh()->stock]);

        // Form cũ gửi hai lần (bấm đúp / hai tab): lần đầu đổi được, lần sau bị chặn vì tổng không còn khớp
        $this->exchange($order, [$line->id => 0], $swap)->assertSessionHas('success');
        $this->exchange($order, [$line->id => 0], $swap)->assertSessionHas('error', fn ($m) => str_contains($m, 'phải bằng đúng tổng cũ'));
        $this->assertSame(1, OrderExchange::count());
        $this->assertSame([['Mật ong', null, 4, 300000, 1200000]], $this->items($order));
        $this->assertSame([10, 5], [$tea->fresh()->stock, $honey->fresh()->stock]);
        $this->assertSame([['Mật ong', null, 1, 300000, 300000]], $this->items($otherOrder)); // đơn khác không bị đụng
    }

    public function test_cancel_and_payment_after_exchange_use_the_new_items(): void
    {
        $tea = $this->product('Trà sâm', 600000, 10);
        $honey = $this->product('Mật ong', 300000, 10);
        $order = $this->order([[$tea, 1]], Order::STATUS_NEW);
        $ops = $this->staff('Vận hành');
        $this->actingAs($ops, 'web')->exchange($order, [$order->items[0]->id => 0], [['product' => (string) $honey->id, 'qty' => 2]])->assertSessionHas('success');
        $this->assertSame([10, 8], [$tea->fresh()->stock, $honey->fresh()->stock]);

        // Hủy đơn sau khi đổi: trả về kho đúng hàng MỚI (mật ong), trà không bị cộng thừa
        $this->actingAs($ops, 'web')->post(route('backend.orders.cancel', $order), ['reason' => 'Khách không lấy nữa'])->assertSessionHas('success');
        $this->assertSame([10, 10], [$tea->fresh()->stock, $honey->fresh()->stock]);
        $this->actingAs($ops, 'web')->get(route('backend.orders.exchange', $order))->assertRedirect(route('backend.orders.show', $order));

        // Đơn chờ thanh toán đã đổi: xác nhận thanh toán theo đúng tổng cũ, hoa hồng tính trên tiền hàng không đổi
        $order2 = $this->order([[$tea, 1]], Order::STATUS_AWAITING);
        $this->actingAs($ops, 'web')->exchange($order2, [$order2->items[0]->id => 0], [['product' => (string) $honey->id, 'qty' => 2]])->assertSessionHas('success');
        $this->actingAs($this->staff('Kế toán'), 'web')->post(route('backend.orders.confirm-payment', $order2), ['payment_reference' => 'FTX1', 'paid_amount' => '600000'])
            ->assertSessionHas('success');
        $order2->refresh();
        $this->assertSame([Order::STATUS_PAID, 600000, 600000], [$order2->status, $order2->goods_amount, $order2->paid_amount]);
        $this->assertSame([['Mật ong', null, 2, 300000, 600000]], $this->items($order2));
    }

    public function test_total_must_match_exactly_and_input_is_validated(): void
    {
        $tea = $this->product('Trà sâm', 600000, 10);
        $candy = $this->product('Kẹo sâm', 250000, 20);
        $order = $this->order([[$tea, 2]]);
        $line = $order->items[0];
        $this->actingAs($this->staff('CSKH'), 'web');
        $refuse = fn ($response, string $message) => $response->assertRedirect(route('backend.orders.exchange', $order))->assertSessionHas('error', $message);

        $refuse($this->exchange($order, [$line->id => 0], [['product' => (string) $candy->id, 'qty' => 4]]),
            'Tổng tiền hàng mới (1.000.000 đ) phải bằng đúng tổng cũ (1.200.000 đ) — thiếu 200.000 đ.');
        $refuse($this->exchange($order, [$line->id => 0], [['product' => (string) $candy->id, 'qty' => 5]]),
            'Tổng tiền hàng mới (1.250.000 đ) phải bằng đúng tổng cũ (1.200.000 đ) — thừa 50.000 đ.');
        $refuse($this->exchange($order, [$line->id => 3], []), 'Số lượng giữ lại của “Trà sâm” phải từ 0 đến 2.');
        $refuse($this->exchange($order, [$line->id => -1], []), 'Số lượng giữ lại của “Trà sâm” phải từ 0 đến 2.');
        $refuse($this->exchange($order, [$line->id => 2], []), 'Chưa có thay đổi nào.');
        $refuse($this->exchange($order, [$line->id => 0], []), 'Đơn phải còn ít nhất một sản phẩm.');
        $refuse($this->exchange($order, [$line->id => 0], [['product' => '999999', 'qty' => 1]]), 'Sản phẩm thêm không tồn tại hoặc đã ngừng bán.');
        $refuse($this->exchange($order, [$line->id => 0], [['product' => (string) $candy->id, 'qty' => '1.5']]), 'Số lượng “Kẹo sâm” phải từ 1 đến 99.');
        $refuse($this->exchange($order, [$line->id => 0], [['product' => (string) $candy->id, 'qty' => 0]], 'ab'), 'Nhập lý do đổi (ít nhất 5 ký tự).');
        $candy->update(['status' => false]);
        $refuse($this->exchange($order, [$line->id => 1], [['product' => (string) $candy->id, 'qty' => 1]]), 'Sản phẩm thêm không tồn tại hoặc đã ngừng bán.');

        // Không đổi gì được lưu, tồn kho y nguyên
        $this->assertSame([['Trà sâm', null, 2, 600000, 1200000]], $this->items($order));
        $this->assertSame([8, 20, 0], [$tea->fresh()->stock, $candy->fresh()->stock, OrderExchange::count()]);
        // Đổi sai vẫn giữ lại dữ liệu đã nhập trên form
        $this->exchange($order, [$line->id => 0], [['product' => (string) $tea->id, 'qty' => 1]], 'Giữ lại lý do này');
        $this->get(route('backend.orders.exchange', $order))->assertSee('Giữ lại lý do này')
            ->assertSee('value="'.$tea->id.'" data-picker-value', false)->assertSee('Trà sâm — 600.000 đ');
    }

    public function test_variants_current_price_and_stock_limits(): void
    {
        $tea = $this->product('Trà sâm', 600000, 10);
        $scent = $this->product('Tinh dầu', 100000);
        $lavender = ProductVariant::create(['product_id' => $scent->id, 'name' => 'Oải hương', 'price' => 300000, 'stock' => 1, 'active' => true]);
        $mint = ProductVariant::create(['product_id' => $scent->id, 'name' => 'Bạc hà', 'price' => null, 'stock' => null, 'active' => true]);
        $order = $this->order([[$tea, 1]]);
        $line = $order->items[0];
        $this->actingAs($this->staff('Vận hành'), 'web');

        // Có biến thể → phải chọn biến thể; biến thể không thuộc sản phẩm → chặn
        $this->exchange($order, [$line->id => 0], [['product' => (string) $scent->id, 'qty' => 6]])->assertSessionHas('error', '“Tinh dầu”: chọn phân loại.');
        $this->exchange($order, [$line->id => 0], [['product' => $scent->id.':999999', 'qty' => 2]])->assertSessionHas('error', '“Tinh dầu”: phân loại đã chọn không còn bán.');
        // 2 × 300k = 600k: đúng tổng nhưng Oải hương chỉ còn 1
        $this->exchange($order, [$line->id => 0], [['product' => $scent->id.':'.$lavender->id, 'qty' => 2]])
            ->assertSessionHas('error', 'Không đủ hàng: “Oải hương” còn 1, cần thêm 2.');
        $this->assertSame([1, 9], [$lavender->fresh()->stock, $tea->fresh()->stock]);

        // Giá thêm = giá bán hiện tại: Bạc hà không có giá riêng → theo giá sản phẩm lúc đổi; dòng trống bị bỏ qua
        $scent->update(['price' => 300000]);
        $this->exchange($order, [$line->id => 0], [['product' => $scent->id.':'.$lavender->id, 'qty' => 1], ['product' => $scent->id.':'.$mint->id, 'qty' => 1], ['product' => '', 'qty' => '']])
            ->assertSessionHas('success');
        $this->assertSame([['Tinh dầu', 'Oải hương', 1, 300000, 300000], ['Tinh dầu', 'Bạc hà', 1, 300000, 300000]], $this->items($order));
        $this->assertSame([0, 10, null], [$lavender->fresh()->stock, $tea->fresh()->stock, $mint->fresh()->stock]);
    }

    public function test_allowed_statuses_permissions_and_money_side_effects(): void
    {
        $tea = $this->product('Trà sâm', 600000);
        $honey = $this->product('Mật ong', 600000);
        $ops = $this->staff('Vận hành');

        // Kế toán / Giám đốc không có quyền đổi
        $paid = $this->order([[$tea, 1]]);
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.orders.show', $paid))->assertDontSee('id="order-exchange"', false);
        $this->get(route('backend.orders.exchange', $paid))->assertForbidden();
        $this->exchange($paid, [$paid->items[0]->id => 0], [['product' => (string) $honey->id, 'qty' => 1]])->assertForbidden();

        // Đơn mới, chờ xác nhận, đã thanh toán: đổi được
        foreach ([Order::STATUS_NEW, Order::STATUS_AWAITING, Order::STATUS_PAID] as $status) {
            $order = $this->order([[$tea, 1]], $status);
            $this->actingAs($ops, 'web')->exchange($order, [$order->items[0]->id => 0], [['product' => (string) $honey->id, 'qty' => 1]])->assertSessionHas('success');
        }
        // Đang giao, hoàn tất, đã hủy, đơn mới quá hạn: không đổi được, không có nút
        foreach ([Order::STATUS_PROCESSING, Order::STATUS_COMPLETED, Order::STATUS_CANCELLED, 'overdue'] as $status) {
            $order = $this->order([[$tea, 1]], $status === 'overdue' ? Order::STATUS_NEW : $status);
            if ($status === 'overdue') {
                $order->forceFill(['expires_at' => now()->subMinute()])->save();
            }
            $this->get(route('backend.orders.show', $order))->assertDontSee('id="order-exchange"', false);
            $this->get(route('backend.orders.exchange', $order))->assertRedirect(route('backend.orders.show', $order));
            $this->exchange($order, [$order->items[0]->id => 0], [['product' => (string) $honey->id, 'qty' => 1]])
                ->assertSessionHas('error', fn ($m) => str_contains($m, 'không đổi sản phẩm được'));
        }

        // Đơn đã thanh toán có hoa hồng: đổi xong hoa hồng / doanh số / số tiền giữ nguyên
        $order = $this->order([[$tea, 1]]);
        Commission::create(['order_id' => $order->id, 'buyer_id' => $order->member_id, 'beneficiary_id' => Member::factory()->create()->id, 'tier' => 'F1',
            'goods_amount' => 600000, 'fee_percent' => 5, 'base_amount' => 570000, 'amount' => 57000, 'status' => Commission::STATUS_CREDITED, 'created_at' => now()]);
        $before = [Commission::sum('amount'), $order->goods_amount, $order->paid_amount];
        $this->exchange($order, [$order->items[0]->id => 0], [['product' => (string) $honey->id, 'qty' => 1]])->assertSessionHas('success');
        $this->assertSame($before, [Commission::sum('amount'), $order->fresh()->goods_amount, $order->fresh()->paid_amount]);
    }

    public function test_admin_preview_shows_the_customer_page_without_actions(): void
    {
        $tea = $this->product('Trà sâm', 600000);
        $order = $this->order([[$tea, 1]], Order::STATUS_NEW);
        $this->actingAs($this->staff('CSKH'), 'web')->get(route('backend.orders.preview', $order))->assertOk()
            ->assertSee('data-admin-preview', false)->assertSee('như khách thấy trên website')->assertSee($order->code)->assertSee('Trà sâm')
            ->assertSee('Chuyển khoản để hoàn tất đơn hàng');
        // Khách khác / chưa đăng nhập quản trị: không xem được
        auth('web')->logout();
        $this->get(route('backend.orders.preview', $order))->assertRedirect();
        $reviewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'review')->create()->id]);
        $this->actingAs($reviewer, 'web')->get(route('backend.orders.preview', $order))->assertForbidden();
    }
}
