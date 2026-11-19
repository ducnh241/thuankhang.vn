<?php

namespace Tests\Feature;

use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CommissionEngine;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

/**
 * Step 8 – Engine hoa hồng: đơn được xác nhận thanh toán → F1 cho người giới thiệu của người mua, F2 cho người trên đó, tỉ lệ
 * theo CẤP NGƯỜI HƯỞNG. Cơ sở = tiền hàng (giá bán đã gồm thuế, không tách VAT) × (1 − phí DN 5%), không tính ship; làm tròn xuống. Cộng ví ngay.
 * Ma trận dùng trong test (như cấu hình thật): Nhân viên 0 / 0 · Cộng tác viên 10 / 7 · Đại lý 20 / 7.
 */
class CommissionEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private int $reference = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        // Chia ví 70 / 20 / 10 có test riêng (WalletSplitTest); ở đây để 100% hoa hồng vào ví có thể rút cho dễ đối chiếu
        \App\Models\Setting::put('aff_pit_percent', '0.00');
        \App\Models\Setting::put('aff_reconsume_percent', '0.00');
        $this->accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        // Seeder ghi phiên bản mặc định lúc "bây giờ" → tua đồng hồ để phiên bản của test là bản mới hơn và đã có hiệu lực
        $this->travel(2)->hours();
        $this->setRates(['Nhân viên' => [0, 0], 'Cộng tác viên' => [10, 7], 'Đại lý' => [20, 7]], now()->subHour());
    }

    /** Thêm một phiên bản ma trận: [tên cấp => [F1 %, F2 %]]. */
    private function setRates(array $matrix, $from): void
    {
        foreach ($matrix as $name => [$f1, $f2]) {
            $levelId = Level::where('name', $name)->value('id');
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F1', 'rate_percent' => $f1, 'effective_from' => $from]);
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F2', 'rate_percent' => $f2, 'effective_from' => $from]);
        }
    }

    /** Thành viên đã kích hoạt ở cấp $level (null = mới đăng ký, chưa kích hoạt). */
    private function member(?string $level, ?Member $referrer = null, array $attributes = []): Member
    {
        return Member::factory()->create([
            'referrer_id' => $referrer?->id,
            ...($level ? ['level_id' => Level::where('name', $level)->value('id'), 'activated_at' => now()->subDays(5)] : []),
            ...$attributes,
        ]);
    }

    /** Đơn của $buyer: tiền hàng $goods đã gồm VAT $vatPercent %, ship 30.000. */
    private function order(Member $buyer, int $goods, int $vatPercent = 10): Order
    {
        return Order::factory()->create([
            'member_id' => $buyer->id, 'goods_amount' => $goods, 'vat_amount' => $goods - (int) round($goods * 100 / (100 + $vatPercent)),
            'shipping_fee' => 30000, 'total_amount' => $goods + 30000,
        ]);
    }

    private function pay(Order $order)
    {
        return $this->actingAs($this->accountant, 'web')->post(route('backend.orders.confirm-payment', $order),
            ['payment_reference' => 'FT'.(++$this->reference), 'paid_amount' => (string) $order->total_amount]);
    }

    /** [tầng, id người hưởng, cấp, tỉ lệ, cơ sở, tiền, trạng thái] của một dòng hoa hồng. */
    private function line(Order $order, string $tier): ?array
    {
        $line = Commission::where('order_id', $order->id)->where('tier', $tier)->first();

        return $line ? [$line->beneficiary_id, $line->level_name, $line->rate_percent, $line->base_amount, $line->amount, $line->status] : null;
    }

    public function test_spec_example_f1_and_f2_are_paid_by_the_level_of_each_beneficiary(): void
    {
        // A → B (Đại lý) → C (Cộng tác viên) → D (mới đăng ký). D mua 5.500.000 đ (VAT 10%), ship 30.000
        $a = $this->member('Đại lý');
        $b = $this->member('Đại lý', $a);
        $c = $this->member('Cộng tác viên', $b);
        $d = $this->member(null, $c);
        $order = $this->order($d, 5_500_000);

        $this->pay($order)->assertSessionHas('success', "Đã xác nhận thanh toán đơn {$order->code}. Tài khoản thành viên được kích hoạt. Thành viên lên cấp Cộng tác viên."
            ." Hoa hồng: F1 {$c->full_name} +522.500 đ, F2 {$b->full_name} +365.750 đ.");

        // Cơ sở = 5.500.000 × 95% = 5.225.000 (không tách VAT, không tính 30.000 ship)
        $this->assertSame([$c->id, 'Cộng tác viên', '10.00', 5_225_000, 522_500, 'credited'], $this->line($order, 'F1'));
        $this->assertSame([$b->id, 'Đại lý', '7.00', 5_225_000, 365_750, 'credited'], $this->line($order, 'F2'));
        $line = Commission::where('order_id', $order->id)->where('tier', 'F1')->first();
        $this->assertSame([$d->id, 5_500_000, '5.00'], [$line->buyer_id, $line->goods_amount, $line->fee_percent]);

        // Ví: C +522.500, B +365.750; A cách D ba tầng và D tự mua → không có gì
        $this->assertSame([522_500, 365_750, 0, 0], [$c->walletBalance(), $b->walletBalance(), $a->walletBalance(), $d->walletBalance()]);
        $transaction = WalletTransaction::where('member_id', $c->id)->sole();
        $this->assertSame(['commission', 522_500, 522_500, $line->id, "Hoa hồng F1 đơn {$order->code}"],
            [$transaction->type, $transaction->amount, $transaction->balance_after, $transaction->commission_id, $transaction->note]);
        $this->assertNotNull($order->fresh()->commissioned_at);
    }

    public function test_owner_example_base_is_goods_amount_minus_business_fee_and_vat_is_not_split_out(): void
    {
        // Ví dụ của chủ dự án: tiền hàng 15.000.000 − 5% phí DN = 14.250.000 → F1 20% = 2.850.000, F2 7% = 997.500
        $f2Beneficiary = $this->member('Đại lý');
        $f1Beneficiary = $this->member('Đại lý', $f2Beneficiary);
        $order = $this->order($this->member(null, $f1Beneficiary), 15_000_000, 0);
        $this->pay($order);

        $this->assertSame([$f1Beneficiary->id, 'Đại lý', '20.00', 14_250_000, 2_850_000, 'credited'], $this->line($order, 'F1'));
        $this->assertSame([$f2Beneficiary->id, 'Đại lý', '7.00', 14_250_000, 997_500, 'credited'], $this->line($order, 'F2'));

        // Giá bán đã gồm thuế: sản phẩm khai VAT 10% hay 0% thì hoa hồng vẫn như nhau (không tách VAT)
        $withVat = $this->order($this->member(null, $f1Beneficiary), 15_000_000, 10);
        $this->assertGreaterThan(0, $withVat->vat_amount);
        $this->pay($withVat);
        $this->assertSame([$f1Beneficiary->id, 'Đại lý', '20.00', 14_250_000, 2_850_000, 'credited'], $this->line($withVat, 'F1'));
        $this->assertSame(997_500, Commission::where('order_id', $withVat->id)->where('tier', 'F2')->value('amount'));

        // Đổi phí doanh nghiệp về 0%: cơ sở = tiền hàng
        Setting::put('aff_business_fee_percent', '0.00');
        $noFee = $this->order($this->member(null, $f1Beneficiary), 1_100_000, 10);
        $this->pay($noFee);
        $this->assertSame([$f1Beneficiary->id, 'Đại lý', '20.00', 1_100_000, 220_000, 'credited'], $this->line($noFee, 'F1'));
        $this->assertSame(2_850_000 * 2 + 220_000, $f1Beneficiary->walletBalance());
    }

    public function test_beneficiary_who_cannot_earn_gets_a_skipped_line_with_a_reason_and_no_wallet_money(): void
    {
        $top = $this->member('Cộng tác viên');
        $notActivated = $this->member(null, $top);
        $locked = $this->member('Đại lý', $top, ['status' => Member::STATUS_LOCKED]);
        $staff = $this->member('Nhân viên', $top);

        // F1 chưa kích hoạt: bỏ qua; F2 (top, CTV 7%) vẫn nhận
        $first = $this->order($this->member(null, $notActivated), 1_100_000);
        $this->pay($first)->assertSessionHas('success', fn ($message) => str_contains($message, "Hoa hồng: F1 {$notActivated->full_name} không nhận, F2 {$top->full_name} +73.150 đ."));
        $line = Commission::where('order_id', $first->id)->where('tier', 'F1')->first();
        $this->assertSame(['skipped', 0, 'Người hưởng chưa kích hoạt tài khoản', '0.00', 1_045_000],
            [$line->status, $line->amount, $line->skip_reason, $line->rate_percent, $line->base_amount]);
        $this->assertSame([$top->id, 'Cộng tác viên', '7.00', 1_045_000, 73_150, 'credited'], $this->line($first, 'F2'));

        // F1 bị khóa
        $second = $this->order($this->member(null, $locked), 1_100_000);
        $this->pay($second);
        $this->assertSame('Tài khoản người hưởng đang bị khóa', Commission::where('order_id', $second->id)->where('tier', 'F1')->value('skip_reason'));

        // F1 ở cấp có tỉ lệ 0%
        $third = $this->order($this->member(null, $staff), 1_100_000);
        $this->pay($third);
        $this->assertSame('Cấp Nhân viên chưa có hoa hồng F1 (tỉ lệ 0%)', Commission::where('order_id', $third->id)->where('tier', 'F1')->value('skip_reason'));

        // Không kích hoạt sau này cũng không được tính bù: ví ba người này vẫn 0; top nhận 3 dòng F2
        $this->assertSame([0, 0, 0, 3 * 73_150], [$notActivated->walletBalance(), $locked->walletBalance(), $staff->walletBalance(), $top->walletBalance()]);
        $this->assertSame(3, WalletTransaction::count());
        $this->assertSame(219_450, WalletTransaction::where('member_id', $top->id)->latest('id')->value('balance_after'));
    }

    public function test_buyer_without_upline_produces_no_lines_and_self_purchase_pays_only_the_upline(): void
    {
        $root = $this->member('Đại lý');
        $order = $this->order($root, 2_200_000);
        $this->pay($order)->assertSessionHas('success', "Đã xác nhận thanh toán đơn {$order->code}.");
        $this->assertSame(0, Commission::count());
        $this->assertNotNull($order->fresh()->commissioned_at);

        // F1 của root tự mua: root nhận F1 20%, người mua không nhận gì; không có F2 vì root không có người giới thiệu
        $child = $this->member('Cộng tác viên', $root);
        $own = $this->order($child, 2_200_000);
        $this->pay($own);
        $this->assertSame([$root->id, 'Đại lý', '20.00', 2_090_000, 418_000, 'credited'], $this->line($own, 'F1'));
        $this->assertNull($this->line($own, 'F2'));
        $this->assertSame([418_000, 0], [$root->walletBalance(), $child->walletBalance()]);
    }

    public function test_rate_version_in_effect_at_payment_time_and_beneficiary_level_at_that_moment_are_used(): void
    {
        $top = $this->member('Cộng tác viên');
        $buyer = $this->member(null, $top);
        $pending = $this->order($buyer, 1_100_000);

        // Admin đổi tỉ lệ khi đơn đang chờ thanh toán → dùng tỉ lệ mới (CTV F1 12,5%)
        $this->setRates(['Cộng tác viên' => [12.5, 7]], now()->subMinute());
        $this->pay($pending);
        $this->assertSame([$top->id, 'Cộng tác viên', '12.50', 1_045_000, 130_625, 'credited'], $this->line($pending, 'F1'));

        // Phiên bản hẹn giờ trong tương lai chưa có hiệu lực
        $this->setRates(['Cộng tác viên' => [50, 50]], now()->addDay());
        $next = $this->order($buyer, 1_100_000);
        $this->pay($next);
        $this->assertSame('12.50', Commission::where('order_id', $next->id)->value('rate_percent'));

        // Người hưởng lên Đại lý → đơn sau tính 20%; dòng cũ giữ nguyên snapshot
        $top->forceFill(['level_id' => Level::where('name', 'Đại lý')->value('id')])->save();
        $later = $this->order($buyer, 1_100_000);
        $this->pay($later);
        $this->assertSame([$top->id, 'Đại lý', '20.00', 1_045_000, 209_000, 'credited'], $this->line($later, 'F1'));
        $this->assertSame('Cộng tác viên', Commission::where('order_id', $pending->id)->value('level_name'));
        $this->assertSame(130_625 * 2 + 209_000, $top->walletBalance());
    }

    public function test_an_order_is_commissioned_once_and_the_ledger_is_append_only(): void
    {
        $top = $this->member('Đại lý');
        $order = $this->order($this->member(null, $top), 1_100_000);
        $this->pay($order);
        $this->assertSame(209_000, $top->walletBalance());

        // Xác nhận lại / gọi lại engine: không cộng đôi
        $this->pay($order)->assertSessionHas('error');
        $this->assertCount(0, DB::transaction(fn () => CommissionEngine::orderPaid($order->fresh())));
        $this->assertSame([1, 1, 209_000], [Commission::count(), WalletTransaction::count(), $top->walletBalance()]);

        $line = Commission::sole();
        $this->assertThrows(fn () => $line->update(['amount' => 1]), LogicException::class);
        $this->assertThrows(fn () => $line->delete(), LogicException::class);
        $this->assertThrows(fn () => WalletTransaction::sole()->delete(), LogicException::class);
    }

    public function test_tiny_order_rounds_down_and_zero_amount_is_not_credited(): void
    {
        $top = $this->member('Cộng tác viên');
        // 42.345 đ → cơ sở floor(42.345 × 0,95 = 40.227,75) = 40.227 → 10% = floor(4.022,7) = 4.022
        $order = $this->order($this->member(null, $top), 42_345);
        $this->pay($order);
        $this->assertSame([$top->id, 'Cộng tác viên', '10.00', 40_227, 4_022, 'credited'], $this->line($order, 'F1'));

        // Đơn 5 đ: cơ sở 4 đ → hoa hồng làm tròn xuống 0 → không ghi ví
        $dust = $this->order($this->member(null, $top), 5, 0);
        $this->pay($dust);
        $line = Commission::where('order_id', $dust->id)->sole();
        $this->assertSame(['skipped', 'Hoa hồng làm tròn xuống bằng 0 đ'], [$line->status, $line->skip_reason]);
        $this->assertSame(4_022, $top->walletBalance());
    }

    /** A (Đại lý) ← B (Cộng tác viên) ← C: C mua 2 đơn, B mua 1 đơn; thêm 1 dòng A không được nhận lúc còn bị khóa. */
    private function sampleLedger(): array
    {
        $a = $this->member('Đại lý', null, ['full_name' => 'Trần Văn Gốc']);
        $b = $this->member('Cộng tác viên', $a, ['full_name' => 'Lê Thị Bình']);
        $c = $this->member(null, $b, ['full_name' => 'Phạm Văn Cường']);

        $a->forceFill(['status' => Member::STATUS_LOCKED])->save();
        $lockedOrder = $this->order($b, 550_000);       // A bị khóa: F1 không được nhận
        $this->pay($lockedOrder);
        $a->forceFill(['status' => Member::STATUS_ACTIVE])->save();

        $first = $this->order($c, 1_100_000);           // cơ sở 1.045.000: B F1 10% = 104.500; A F2 7% = 73.150
        $this->pay($first);
        $own = $this->order($b, 2_200_000);             // cơ sở 2.090.000: A F1 20% = 418.000
        $this->pay($own);
        $second = $this->order($c, 3_300_000);          // cơ sở 3.135.000: B F1 10% = 313.500; A F2 7% = 219.450
        $this->pay($second);

        return [$a, $b, $c, $first, $own, $second, $lockedOrder];
    }

    public function test_member_sees_wallet_balance_and_commission_history_on_the_website(): void
    {
        [$a, $b, $c, $first, $own, $second, $lockedOrder] = $this->sampleLedger();

        $this->get('/tai-khoan/vi')->assertRedirect('/dang-nhap');

        // A: 418.000 (F1) + 73.150 + 219.450 (F2) = 710.600; dòng lúc bị khóa không hiện
        $this->actingAs($a->fresh(), 'member')->get('/tai-khoan')->assertOk()
            ->assertSeeInOrder(['Ví có thể rút', 'data-wallet-balance>710.600đ<', 'Tháng này', '+710.600đ', 'Ví tái tiêu dùng', 'Xem chi tiết'], false)
            ->assertSee('href="'.route('member.wallet').'"', false);
        $this->get('/tai-khoan/vi')->assertOk()
            ->assertSee('data-wallet-balance>710.600đ<', false)->assertDontSee('data-wallet-f1', false)
            // Mới nhất trước: F2 đơn thứ hai của C, F1 đơn B tự mua, F2 đơn đầu của C (dòng gọn), rồi popup chi tiết từng dòng
            ->assertSeeInOrder(['data-wallet-rows', 'F2', 'Phạm Văn Cường', $second->code, '+219.450đ',
                'F1', 'Lê Thị Bình', $own->code, '+418.000đ',
                'F2', 'Phạm Văn Cường', $first->code, '+73.150đ',
                '<dialog', '3.135.000đ × 7%', '(cấp Đại lý)'], false)
            ->assertDontSee($lockedOrder->code)->assertDontSee($c->phone)->assertDontSee($b->phone)
            ->assertSee('trừ 5% phí doanh nghiệp');

        // B chỉ thấy hoa hồng của mình; C chưa có gì
        $this->actingAs($b->fresh(), 'member')->get('/tai-khoan/vi')->assertOk()
            ->assertSee('data-wallet-balance>418.000đ<', false)->assertSee('+313.500đ')->assertSee('+104.500đ')->assertDontSee('+219.450đ')->assertDontSee($own->code);
        $this->actingAs($c->fresh(), 'member')->get('/tai-khoan/vi')->assertOk()
            ->assertSee('data-wallet-balance>0đ<', false)->assertSee('Không có hoa hồng nào trong tháng này')->assertDontSee('data-wallet-rows', false);
    }

    public function test_admin_reconciles_commissions_on_member_and_order_pages(): void
    {
        [$a, $b, $c, $first, $own, $second, $lockedOrder] = $this->sampleLedger();
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);

        // Chi tiết thành viên: thẻ ví + nút mở sổ hoa hồng
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $a))->assertOk()
            ->assertSeeInOrder(['Ví hoa hồng', 'data-wallet-balance>710.600 đ<', 'Ví tái tiêu dùng', 'F1 (trực tiếp)', '418.000 đ', 'F2 (gián tiếp)', '292.600 đ', '3 đã cộng ví · 1 không được nhận', 'Xem sổ hoa hồng'], false)
            ->assertSee('href="'.route('backend.members.commissions', $a).'"', false);

        // Sổ hoa hồng: đủ các bước tính + phần vào từng ví; dòng không được nhận có lý do
        $this->get(route('backend.members.commissions', $a))->assertOk()
            ->assertSee('data-wallet-balance>710.600 đ<', false)->assertSee('data-wallet-total>710.600 đ<', false)
            ->assertSeeInOrder([$second->code, 'Phạm Văn Cường', 'F2', 'Đại lý', '3.300.000 đ', '3.135.000 đ', '7%', '219.450 đ', '+219.450 đ',
                $own->code, 'Lê Thị Bình', 'F1', '2.200.000 đ', '2.090.000 đ', '20%', '418.000 đ', '+418.000 đ',
                $first->code, 'F2', '1.045.000 đ', '7%', '73.150 đ', '+73.150 đ',
                $lockedOrder->code, 'F1', 'Không được nhận', 'Tài khoản người hưởng đang bị khóa'], false);

        // Lọc theo tầng / trạng thái
        $this->get(route('backend.members.commissions', [$a, 'tier' => 'F1', 'status' => 'credited']))->assertOk()
            ->assertSee($own->code)->assertDontSee($second->code)->assertDontSee($lockedOrder->code);
        $this->get(route('backend.members.commissions', [$a, 'status' => 'skipped']))->assertOk()
            ->assertSee($lockedOrder->code)->assertDontSee($own->code);
        $this->get(route('backend.members.commissions', [$c, 'tier' => 'XX']))->assertOk()->assertSee('Chưa có hoa hồng nào');

        // Chi tiết đơn: hai dòng hoa hồng của đơn + cách tính cơ sở
        $this->get(route('backend.orders.show', $second))->assertOk()
            ->assertSeeInOrder(['Hoa hồng', 'F1', 'Lê Thị Bình', 'Cộng tác viên · 10% × 3.135.000 đ', '+313.500 đ', 'F2', 'Trần Văn Gốc', 'Đại lý · 7% × 3.135.000 đ', '+219.450 đ',
                'Cơ sở = tiền hàng 3.300.000 đ − 5% phí doanh nghiệp = 3.135.000 đ'], false);
        $this->get(route('backend.orders.show', $lockedOrder))->assertOk()->assertSeeInOrder(['Hoa hồng', 'Trần Văn Gốc', 'Không được nhận', 'Tài khoản người hưởng đang bị khóa']);
        $unpaid = $this->order($c, 500_000);
        $this->get(route('backend.orders.show', $unpaid))->assertOk()->assertDontSee('data-card="commissions"', false);

        // Kế toán (quyền commission, không có quyền member) mở được sổ hoa hồng từ đơn, không có link sang trang thành viên
        $this->actingAs($this->accountant, 'web')->get(route('backend.orders.show', $second))->assertOk()
            ->assertSee('href="'.route('backend.members.commissions', $a).'"', false);
        $this->get(route('backend.members.commissions', $a))->assertOk()->assertSee('+219.450 đ')
            ->assertDontSee('href="'.route('backend.members.show', $a).'"', false);
        $this->get(route('backend.members.show', $a))->assertForbidden();

        // Không có cả hai quyền thì không xem được
        $content = User::factory()->create(['group_id' => Group::factory()->create(['permission_data' => ['backend_access', 'order']])->id]);
        $this->actingAs($content, 'web')->get(route('backend.members.commissions', $a))->assertForbidden();
    }

    public function test_the_old_order_wipe_migration_is_disabled_and_deletes_nothing(): void
    {
        [$a, $b] = $this->sampleLedger();
        $before = [Order::count(), Commission::count(), WalletTransaction::count(), $a->walletBalance(), $b->walletBalance(), $a->fresh()->cumulative_amount, $b->fresh()->level_id];
        $this->assertSame([4, 6, 5, 710_600, 418_000], array_slice($before, 0, 5));

        // Migration từng xóa sạch đơn hàng: giờ chạy trên database có đơn thật cũng không đổi gì
        (require database_path('migrations/2026_11_23_000001_clear_test_orders.php'))->up();

        $this->assertSame($before, [Order::count(), Commission::count(), WalletTransaction::count(), $a->walletBalance(), $b->walletBalance(), $a->fresh()->cumulative_amount, $b->fresh()->level_id]);
    }
}
