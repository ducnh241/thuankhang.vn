<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Order;
use App\Support\Pv;
use Database\Seeders\AffiliateConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Doanh số nhóm A / B ở trang Tài khoản (19/11/2026): tổng tiền hàng (giá bán đã gồm thuế, bỏ qua VAT), không ship các đơn đã thanh toán trong
 * tháng này của mọi người ở Nhánh A / Nhánh B bên dưới thành viên (cộng dồn tới lá), không gồm đơn của chính họ;
 * hiển thị PV (1 PV = 1.000.000 đ, tối đa 2 số lẻ, làm tròn xuống); chỉ ghi Nhóm A / Nhóm B (không nhãn "Yếu hơn", không dòng giải thích).
 */
class GroupSalesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->travelTo(now()->setDate(2026, 11, 19)->setTime(10, 0));
    }

    /** Thành viên do $referrer giới thiệu, tự chọn nhánh $side (A = L, B = R). */
    private function member(string $name, ?Member $referrer = null, ?string $side = null): Member
    {
        return Member::factory()->create(['full_name' => $name, 'referrer_id' => $referrer?->id, 'sponsor_side' => $side])->fresh();
    }

    /** Đơn đã thanh toán: tiền hàng $goods (giá bán đã gồm thuế; vat_amount là dữ liệu cũ, không ảnh hưởng), ship 30.000. */
    private function paid(Member $member, int $goods, $paidAt = null, string $status = Order::STATUS_PAID): Order
    {
        return Order::factory()->status($status)->create([
            'member_id' => $member->id, 'goods_amount' => $goods, 'vat_amount' => intdiv($goods, 11),
            'shipping_fee' => 30000, 'total_amount' => $goods + 30000, 'paid_at' => $paidAt ?? now(),
        ]);
    }

    public function test_pv_format(): void
    {
        $this->assertSame(['50', '14,9', '14,15', '14,15', '0', '0,01', '1.234,5'],
            array_map(fn ($amount) => Pv::format($amount), [50_000_000, 14_900_000, 14_155_000, 14_159_999, 9_999, 10_000, 1_234_500_000]));
    }

    public function test_account_shows_group_a_and_b_sales_summed_down_to_the_leaves(): void
    {
        // Như hình vẽ: ID → A (Nhánh A) / B (Nhánh B); nhóm A = 10 + 10 + 10 + 10 + 10 = 50 PV, nhóm B = 15 + 15 = 30 PV
        $id = $this->member('Chủ tài khoản');
        $a = $this->member('A', $id, 'L');
        $b = $this->member('B', $id, 'R');
        $a1 = $this->member('A1', $a, 'L');
        $a2 = $this->member('A2', $a, 'R');
        $a1r = $this->member('A1 phải', $a1, 'R');
        $a1l = $this->member('A1 trái', $a1, 'L');
        $a2r = $this->member('A2 phải', $a2, 'R');
        $b2 = $this->member('B2', $b, 'R');
        $this->member('B1', $b, 'L');
        $this->member('B2 trái', $b2, 'L');
        $this->assertSame([$a->id, 'L', $a1->id, 'R'], [$a1->parent_id, $a1->position, $a1r->parent_id, $a1r->position]);

        foreach ([$a, $a1, $a2, $a1r, $a2r] as $member) {
            $this->paid($member, 10_000_000);
        }
        $this->paid($b, 15_000_000);
        $this->paid($b2, 15_000_000);

        // Không tính: đơn của chính mình, đơn tháng trước, đơn chưa thanh toán
        $this->paid($id, 99_000_000);
        $this->paid($a1l, 7_000_000, now()->subMonth());
        Order::factory()->create(['member_id' => $a1l->id, 'goods_amount' => 5_500_000, 'vat_amount' => 500_000]);

        $this->actingAs($id, 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('Doanh số nhóm tháng 11/2026')
            ->assertSeeInOrder(['data-group="A"', 'Nhóm A', 'data-group-pv>50 PV<', 'data-group="B"', 'Nhóm B', 'data-group-pv>30 PV<'], false)
            ->assertDontSee('Yếu hơn')->assertDontSee('Tổng tiền hàng (giá bán')->assertDontSee('1 PV = 1.000.000đ')
            ->assertDontSee('Chưa có thành viên');

        // Xem từ A: nhóm A = A1 + A1 phải (+ A1 trái chỉ có đơn tháng trước) = 20 PV, nhóm B = A2 + A2 phải = 20 PV → không ai yếu hơn
        $this->actingAs($a, 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('data-group-pv>20 PV<', false);

        // Lá: hai nhóm trống
        $this->actingAs($a2r, 'member')->get('/tai-khoan')->assertOk()
            ->assertSeeInOrder(['Nhóm A', '0 PV', 'Chưa có thành viên', 'Nhóm B', '0 PV', 'Chưa có thành viên']);
    }

    public function test_pv_uses_goods_amount_ignoring_vat_and_rounds_down_to_two_decimals(): void
    {
        $id = $this->member('Chủ tài khoản');
        $a = $this->member('A', $id, 'L');
        $this->paid($a, 15_000_000);                                       // VD Chung Nguyễn: 15tr → 15 PV (không trừ VAT)
        $this->paid($a, 155_999, status: Order::STATUS_COMPLETED);

        $this->actingAs($id, 'member')->get('/tai-khoan')->assertOk()
            ->assertSeeInOrder(['Nhóm A', 'data-group-pv>15,15 PV<', 'Nhóm B', 'data-group-pv>0 PV<', 'Chưa có thành viên'], false);
    }
}
