<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\BinaryTree;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Luồng đầy đủ sau khi bỏ VAT (19/11/2026): giá sản phẩm / biến thể → dòng đơn → đơn → xác nhận thanh toán → hoa hồng F1 / F2
 * (cơ sở = tiền hàng × 95%) → chia ví 70 / 20 / 10 → doanh số lên cấp → doanh số nhóm A / B của tuyến trên. Sản phẩm còn
 * vat_rate cũ (dữ liệu trước khi bỏ) cũng không làm lệch con số nào; các cột VAT của đơn mới luôn 0.
 */
class OrderToCommissionFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_price_flows_unchanged_into_order_commission_wallets_level_and_group_sales(): void
    {
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->travel(2)->hours();
        foreach (['Cộng tác viên' => [10, 7], 'Đại lý' => [20, 7]] as $name => [$f1, $f2]) {
            $levelId = Level::where('name', $name)->value('id');
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F1', 'rate_percent' => $f1, 'effective_from' => now()->subHour()]);
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F2', 'rate_percent' => $f2, 'effective_from' => now()->subHour()]);
        }
        $member = fn (string $level, ?Member $ref, string $side) => Member::factory()->create([
            'referrer_id' => $ref?->id, 'sponsor_side' => $side, 'level_id' => Level::where('name', $level)->value('id'), 'activated_at' => now()->subDay(),
        ])->fresh();
        $top = $member('Cộng tác viên', null, 'L');
        $f1 = $member('Đại lý', $top, 'R');                                   // Nhóm B của top
        $buyer = Member::factory()->create(['referrer_id' => $f1->id, 'sponsor_side' => 'L'])->fresh();   // Nhóm A của f1

        $category = Category::factory()->create(['type' => Category::TYPE_PRODUCT]);
        $plain = Product::factory()->create(['category_id' => $category->id, 'status' => true, 'price' => 4_200_000]);
        $withVariants = Product::factory()->create(['category_id' => $category->id, 'status' => true, 'price' => 350_000, 'variant_label' => 'Màu']);
        $red = $withVariants->variants()->create(['name' => 'Đỏ', 'price' => 380_000]);
        \DB::table('products')->update(['vat_rate' => 10]);                   // dữ liệu VAT cũ còn sót: không được ảnh hưởng

        $this->actingAs($buyer, 'member');
        $this->post('/gio-hang', ['product_id' => $withVariants->id, 'variant_id' => $red->id, 'qty' => 2]);
        $this->post('/gio-hang', ['product_id' => $plain->id, 'qty' => 3]);
        $this->get('/thanh-toan')->assertOk()->assertSee('13.360.000đ')->assertDontSee('VAT');
        $this->post('/thanh-toan', ['recipient_name' => 'A', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship',
            'province' => 'ha-noi', 'address' => '1 Láng Hạ', 'pickup_point_id' => '', 'note' => '']);
        $order = Order::sole()->load('items');

        // 380.000 × 2 + 4.200.000 × 3 = 13.360.000; ship 30.000
        $this->assertSame([13_360_000, 30_000, 13_390_000, 0], [$order->goods_amount, $order->shipping_fee, $order->total_amount, (int) $order->getRawOriginal('vat_amount')]);
        $this->assertSame([[$red->id, 2, 380_000, 760_000, 0.0, 0], [null, 3, 4_200_000, 12_600_000, 0.0, 0]],
            $order->items->sortBy('id')->map(fn ($i) => [$i->variant_id, $i->qty, $i->unit_price, $i->line_amount, (float) $i->getRawOriginal('vat_rate'), (int) $i->getRawOriginal('vat_amount')])->values()->all());

        $accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $this->actingAs($accountant, 'web')->post(route('backend.orders.confirm-payment', $order), ['payment_reference' => 'FT1', 'paid_amount' => '13390000'])
            ->assertSessionHas('success');

        // Cơ sở 13.360.000 × 95% = 12.692.000 → F1 (Đại lý 20%) 2.538.400; F2 (CTV 7%) 888.440
        $line = fn (string $tier) => Commission::where('tier', $tier)->sole();
        $this->assertSame([13_360_000, 12_692_000, 2_538_400, 1_776_880, 507_680, 253_840],
            [$line('F1')->goods_amount, $line('F1')->base_amount, $line('F1')->amount, $line('F1')->cash_amount, $line('F1')->reconsume_amount, $line('F1')->tax_amount]);
        $this->assertSame([888_440, 621_908, 177_688, 88_844], [$line('F2')->amount, $line('F2')->cash_amount, $line('F2')->reconsume_amount, $line('F2')->tax_amount]);
        $this->assertSame([1_776_880, 507_680], [$f1->walletBalance(), $f1->walletBalance('reconsume')]);
        $this->assertSame([621_908, 177_688], [$top->walletBalance(), $top->walletBalance('reconsume')]);

        // Doanh số lên cấp của người mua + doanh số nhóm của tuyến trên = đúng tiền hàng
        $this->assertSame(13_360_000, (int) $buyer->fresh()->cumulative_amount);
        $this->assertSame([13_360_000, 0], [BinaryTree::legs($f1)['L']['sales'], BinaryTree::legs($f1)['R']['sales']]);
        $this->assertSame([0, 13_360_000], [BinaryTree::legs($top)['L']['sales'], BinaryTree::legs($top)['R']['sales']]);
        $this->actingAs($top->fresh(), 'member')->get('/tai-khoan')->assertSeeInOrder(['Nhóm A', '0 PV', 'Nhóm B', 'data-group-pv>13,36 PV<'], false);
    }
}
