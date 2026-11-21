<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use App\Support\AffiliateSettings;
use App\Support\Cart;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Ví tái tiêu dùng dùng được (21/11/2026): mua hàng bằng ví (số dư ≥ tổng đơn, thanh toán ngay, tính như đơn thường), chuyển
 * ví cho thành viên khác qua mã giới thiệu (cần KYC, tối thiểu 10.000 đ), nạp tiền (QR + bill → kế toán xác nhận, cộng số
 * thực nhận), chuyển từ ví rút tiền sang (một chiều, tối thiểu 10.000 đ).
 */
class ReconsumeWalletTest extends TestCase
{
    use RefreshDatabase;

    private const R = WalletTransaction::WALLET_RECONSUME;

    private const C = WalletTransaction::WALLET_CASH;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        AffiliateSettings::save(['payment_expiry_hours' => 24]);
        Storage::fake(MemberKyc::DISK);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travel(2)->hours();
        foreach (['Cộng tác viên' => [10, 7], 'Đại lý' => [20, 7]] as $name => [$f1, $f2]) {
            $levelId = Level::where('name', $name)->value('id');
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F1', 'rate_percent' => $f1, 'effective_from' => now()->subHour()]);
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F2', 'rate_percent' => $f2, 'effective_from' => now()->subHour()]);
        }
    }

    private function member(array $attributes = []): Member
    {
        return Member::factory()->create($attributes);
    }

    private function agent(?Member $referrer = null): Member
    {
        return $this->member(['referrer_id' => $referrer?->id, 'level_id' => Level::where('name', 'Đại lý')->value('id'), 'activated_at' => now()->subDays(3)]);
    }

    private function fund(Member $member, int $amount, string $wallet = self::R): void
    {
        DB::transaction(fn () => Wallet::post($member, $wallet, WalletTransaction::TYPE_TOPUP, $amount, 'Số dư thử'));
    }

    private function kyc(Member $member, string $status = MemberKyc::STATUS_APPROVED): void
    {
        MemberKyc::factory()->create(['member_id' => $member->id, 'status' => $status]);
    }

    private function staff(string $group): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $group)->value('id')]);
    }

    private function product(array $data = []): Product
    {
        $category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);

        return Product::factory()->create(['category_id' => $category->id, 'status' => true, 'price' => 500000, ...$data]);
    }

    private function checkout(Member $member, Product $product, int $qty, array $data = [])
    {
        return $this->actingAs($member, 'member')->withSession([Cart::SESSION_KEY => [$product->id => $qty]])
            ->from(route('shop.checkout'))->post(route('shop.checkout.store'), [
                'recipient_name' => 'Nguyễn Văn A', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship',
                'province' => 'ha-noi', 'address' => '12 Láng Hạ', 'payment_method' => Order::PAYMENT_WALLET, ...$data,
            ]);
    }

    // ───────────── 1. Mua hàng bằng ví ─────────────

    public function test_checkout_with_reconsume_wallet_pays_the_order_immediately_like_a_normal_paid_order(): void
    {
        $upline = $this->agent();
        $buyer = $this->member(['referrer_id' => $upline->id]);
        $this->fund($buyer, 1200000);
        $product = $this->product(['stock' => 10]);

        // Trang thanh toán: có lựa chọn ví + số dư
        $this->actingAs($buyer, 'member')->withSession([Cart::SESSION_KEY => [$product->id => 2]])->get(route('shop.checkout'))->assertOk()
            ->assertSee('Ví tái tiêu dùng')->assertSee('data-wallet-reconsume>1.200.000đ', false)->assertDontSee('data-wallet-short', false);

        $response = $this->checkout($buyer, $product, 2);
        $order = Order::sole();
        $response->assertRedirect(route('member.orders.show', $order))
            ->assertSessionHas('success', "Đã thanh toán đơn {$order->code} bằng ví tái tiêu dùng (−1.000.000đ). Số dư ví còn 200.000đ.");

        // Đơn thanh toán ngay, không cần kế toán; mã giao dịch tự sinh
        $this->assertSame([Order::STATUS_PAID, Order::PAYMENT_WALLET, 'VI-'.$order->code, 1000000, null, null],
            [$order->status, $order->payment_method, $order->payment_reference, $order->paid_amount, $order->confirmed_by, $order->expires_at]);
        $this->assertSame(200000, $buyer->walletBalance(self::R));
        $debit = WalletTransaction::where('type', WalletTransaction::TYPE_PURCHASE)->sole();
        $this->assertSame([$buyer->id, self::R, -1000000, 200000, $order->id], [$debit->member_id, $debit->wallet, $debit->amount, $debit->balance_after, $debit->order_id]);
        $this->assertSame(8, $product->fresh()->stock);

        // Tính như đơn thường: kích hoạt người mua, cộng doanh số, hoa hồng F1 cho tuyến trên
        $buyer->refresh();
        $this->assertNotNull($buyer->activated_at);
        $this->assertSame(1000000, (int) $buyer->cumulative_amount);
        $f1 = Commission::where('order_id', $order->id)->where('tier', 'F1')->sole();
        $this->assertSame([Commission::STATUS_CREDITED, $upline->id, 190000], [$f1->status, $f1->beneficiary_id, $f1->amount]);
        $this->assertNotNull($order->commissioned_at);

        // Trang đơn (khách + quản trị) ghi rõ hình thức
        $this->get(route('member.orders.show', $order))->assertSee('data-paid-by-wallet', false)->assertDontSee('data-bill-form', false);
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.orders.show', $order))->assertOk()
            ->assertSee('đã trừ ví tái tiêu dùng của thành viên')->assertSee('VI-'.$order->code)->assertDontSee('confirm-payment-form');
        $this->assertSame(1, AuditLog::where('auditable_type', Order::class)->where('auditable_id', $order->id)->count());
    }

    public function test_wallet_payment_is_refused_when_the_balance_is_short(): void
    {
        $buyer = $this->member();
        $this->fund($buyer, 999999);
        $product = $this->product(['stock' => 5]);

        $this->actingAs($buyer, 'member')->withSession([Cart::SESSION_KEY => [$product->id => 2]])->get(route('shop.checkout'))->assertOk()
            ->assertSee('Còn thiếu 1đ.')->assertSee(route('member.topups.index'));

        $this->checkout($buyer, $product, 2)->assertRedirect(route('shop.checkout'))->assertSessionHasErrors('payment_method');
        $this->assertSame(0, Order::count());
        $this->assertSame([999999, 5], [$buyer->walletBalance(self::R), $product->fresh()->stock]);

        // Chuyển khoản vẫn như cũ (mặc định khi không gửi hình thức thanh toán)
        $this->checkout($buyer, $product, 2, ['payment_method' => null])->assertSessionHasErrors('payment_method');
        $form = ['recipient_name' => 'A', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship', 'province' => 'ha-noi', 'address' => 'x'];
        $this->actingAs($buyer, 'member')->withSession([Cart::SESSION_KEY => [$product->id => 2]])->post(route('shop.checkout.store'), $form)->assertRedirect();
        $this->assertSame([Order::STATUS_NEW, Order::PAYMENT_TRANSFER], [Order::sole()->status, Order::sole()->payment_method]);
        $this->assertSame(999999, $buyer->walletBalance(self::R));
    }

    public function test_wallet_still_works_when_the_unpaid_order_limit_is_reached(): void
    {
        $buyer = $this->member();
        Order::factory()->count(Order::MAX_OPEN_UNPAID)->create(['member_id' => $buyer->id]);
        $product = $this->product();

        // Ví không đủ: như trước, không vào được trang thanh toán
        $this->actingAs($buyer, 'member')->withSession([Cart::SESSION_KEY => [$product->id => 1]])->get(route('shop.checkout'))->assertRedirect(route('member.orders.index'));

        $this->fund($buyer, 500000);
        $this->actingAs($buyer, 'member')->withSession([Cart::SESSION_KEY => [$product->id => 1]])->get(route('shop.checkout'))->assertOk()
            ->assertSee('Bạn đang có '.Order::MAX_OPEN_UNPAID.' đơn chưa thanh toán');
        $this->checkout($buyer, $product, 1, ['payment_method' => Order::PAYMENT_TRANSFER])->assertRedirect(route('member.orders.index'));
        $this->checkout($buyer, $product, 1)->assertSessionHas('success');
        $this->assertSame(0, $buyer->walletBalance(self::R));
        $this->assertSame(1, Order::where('status', Order::STATUS_PAID)->count());
    }

    // ───────────── 2. Chuyển ví cho thành viên khác ─────────────

    public function test_transfer_to_another_member_by_referral_code_with_kyc_and_password(): void
    {
        $a = $this->member(['full_name' => 'Trần Thị Bình']);
        $b = $this->member(['full_name' => 'Lê Văn Cường Minh']);
        $this->fund($a, 100000);

        // Chưa duyệt KYC: không chuyển được
        $this->actingAs($a, 'member')->get(route('member.wallet.transfer'))->assertOk()->assertSee('data-kyc-required', false)->assertDontSee('data-transfer-form', false);
        $this->kyc($a, MemberKyc::STATUS_PENDING);
        $this->post(route('member.wallet.transfer.preview'), ['code' => $b->referral_code, 'amount' => '50.000'])
            ->assertRedirect(route('member.wallet.transfer'))->assertSessionHasErrors(['amount' => 'Bạn cần được duyệt KYC trước khi chuyển tiền cho thành viên khác.']);
        MemberKyc::query()->update(['status' => MemberKyc::STATUS_APPROVED]);

        // Bước 1 → trang xác nhận có tên rút gọn người nhận
        $this->get(route('member.wallet.transfer'))->assertSee('data-transfer-form', false);
        $this->post(route('member.wallet.transfer.preview'), ['code' => strtolower($b->referral_code), 'amount' => '60.000'])->assertOk()
            ->assertSee('Lê V. C. Minh')->assertSee('data-transfer-confirm', false)->assertSee('60.000đ');

        // Bước 2: sai / thiếu mật khẩu → ở lại trang xác nhận, chưa chuyển
        $this->post(route('member.wallet.transfer.store'), ['code' => $b->referral_code, 'amount' => '60000', 'password' => 'sai-mat-khau'])
            ->assertOk()->assertSee('Mật khẩu không đúng.')->assertSee('Lê V. C. Minh');
        $this->post(route('member.wallet.transfer.store'), ['code' => $b->referral_code, 'amount' => '60000'])->assertOk()->assertSee('Nhập mật khẩu đăng nhập để xác nhận.');
        $this->assertSame([100000, 0], [$a->walletBalance(self::R), $b->walletBalance(self::R)]);

        $this->post(route('member.wallet.transfer.store'), ['code' => $b->referral_code, 'amount' => '60000', 'password' => 'password'])
            ->assertRedirect(route('member.wallet.history', ['vi' => self::R]))
            ->assertSessionHas('success', "Đã chuyển 60.000đ cho Lê V. C. Minh ({$b->referral_code}). Số dư ví tái tiêu dùng còn 40.000đ.");
        $this->assertSame([40000, 60000], [$a->walletBalance(self::R), $b->walletBalance(self::R)]);
        $this->assertSame(0, $b->walletBalance(self::C));
        $out = WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_OUT)->sole();
        $in = WalletTransaction::where('type', WalletTransaction::TYPE_TRANSFER_IN)->sole();
        $this->assertSame([$a->id, -60000, $b->id], [$out->member_id, $out->amount, $out->counterpart_id]);
        $this->assertSame([$b->id, 60000, 60000, $a->id], [$in->member_id, $in->amount, $in->balance_after, $in->counterpart_id]);

        // Lịch sử hai bên
        $this->get(route('member.wallet.history'))->assertOk()->assertSee('Chuyển tiền đi')->assertSee('−60.000đ');
        $this->actingAs($b, 'member')->get(route('member.wallet.history', ['vi' => self::R]))->assertOk()->assertSee('Nhận tiền chuyển')->assertSee('từ Trần T. Bình');
    }

    public function test_transfer_rules(): void
    {
        $a = $this->member();
        $this->kyc($a);
        $this->fund($a, 30000);
        $locked = $this->member(['status' => Member::STATUS_LOCKED]);
        $b = $this->member();
        $preview = fn (array $data) => $this->actingAs($a, 'member')->from(route('member.wallet.transfer'))->post(route('member.wallet.transfer.preview'), $data);

        $preview(['code' => '', 'amount' => '20000'])->assertSessionHasErrors(['code' => 'Nhập mã giới thiệu của người nhận.']);
        $preview(['code' => 'KHONGCO', 'amount' => '20000'])->assertSessionHasErrors(['code' => 'Không tìm thấy thành viên có mã giới thiệu này.']);
        $preview(['code' => $a->referral_code, 'amount' => '20000'])->assertSessionHasErrors(['code' => 'Không chuyển được cho chính mình.']);
        $preview(['code' => $locked->referral_code, 'amount' => '20000'])->assertSessionHasErrors(['code' => 'Tài khoản người nhận đang bị khóa, không nhận được tiền.']);
        $preview(['code' => $b->referral_code, 'amount' => '9.999'])->assertSessionHasErrors(['amount' => 'Số tiền chuyển tối thiểu 10.000đ.']);
        $preview(['code' => $b->referral_code, 'amount' => '30.001'])->assertSessionHasErrors(['amount' => 'Số dư ví tái tiêu dùng không đủ (còn 30.000đ).']);
        // Tiền ở ví rút tiền không chuyển cho người khác được
        $this->fund($a, 500000, self::C);
        $preview(['code' => $b->referral_code, 'amount' => '40000'])->assertSessionHasErrors('amount');

        // Đúng tối thiểu, chuyển hết ví
        $this->post(route('member.wallet.transfer.store'), ['code' => $b->referral_code, 'amount' => '30000', 'password' => 'password'])->assertSessionHas('success');
        $this->assertSame([0, 30000, 500000], [$a->walletBalance(self::R), $b->walletBalance(self::R), $a->walletBalance(self::C)]);
        // Gửi lại form cũ (bấm 2 lần): không trừ âm ví
        $this->post(route('member.wallet.transfer.store'), ['code' => $b->referral_code, 'amount' => '30000', 'password' => 'password'])->assertSessionHasErrors('amount');
        $this->assertSame(0, $a->walletBalance(self::R));
    }

    // ───────────── 4. Chuyển từ ví rút tiền sang ví tái tiêu dùng ─────────────

    public function test_convert_cash_wallet_to_reconsume_wallet_one_way(): void
    {
        $m = $this->member();
        $this->fund($m, 50000, self::C);
        $this->fund($m, 1000);

        $this->actingAs($m, 'member')->get(route('member.wallet'))->assertOk()
            ->assertSee(route('member.wallet.convert'))->assertSee(route('member.topups.index'))->assertSee(route('member.wallet.transfer'));
        $this->get(route('member.wallet.convert'))->assertOk()->assertSee('Chỉ chuyển một chiều');

        $this->from(route('member.wallet.convert'))->post(route('member.wallet.convert.store'), ['amount' => '9.000'])
            ->assertSessionHasErrors(['amount' => 'Số tiền chuyển tối thiểu 10.000đ.']);
        $this->from(route('member.wallet.convert'))->post(route('member.wallet.convert.store'), ['amount' => 'abc'])
            ->assertSessionHasErrors(['amount' => 'Số tiền không hợp lệ.']);
        $this->from(route('member.wallet.convert'))->post(route('member.wallet.convert.store'), ['amount' => '60.000'])
            ->assertSessionHasErrors(['amount' => 'Số dư ví rút tiền không đủ (còn 50.000đ).']);
        $this->post(route('member.wallet.convert.store'), ['amount' => '50.000'])
            ->assertRedirect(route('member.wallet.history', ['vi' => self::R]))
            ->assertSessionHas('success', 'Đã chuyển 50.000đ từ ví rút tiền sang ví tái tiêu dùng. Ví tái tiêu dùng: 51.000đ.');
        $this->assertSame([0, 51000], [$m->walletBalance(self::C), $m->walletBalance(self::R)]);
        $this->assertSame(2, WalletTransaction::where('type', WalletTransaction::TYPE_CONVERT)->count());
        $this->get(route('member.wallet.history'))->assertSee('Chuyển sang ví tái tiêu dùng')->assertSee('Chuyển từ ví rút tiền');
    }

    // ───────────── 3. Nạp tiền ─────────────

    public function test_member_creates_a_topup_sees_the_company_qr_and_reports_the_transfer_with_a_bill(): void
    {
        $m = $this->member();
        $this->actingAs($m, 'member')->get(route('member.topups.index'))->assertOk()->assertSee('data-topup-form', false);

        $this->from(route('member.topups.index'))->post(route('member.topups.store'), ['amount' => '99.999'])
            ->assertSessionHasErrors(['amount' => 'Số tiền nạp tối thiểu 100.000đ.']);
        $this->post(route('member.topups.store'), ['amount' => '250.000'])->assertRedirect();
        $topup = WalletTopup::sole();
        $this->assertSame([$m->id, 250000, WalletTopup::STATUS_NEW], [$topup->member_id, $topup->amount, $topup->status]);
        $this->assertStringStartsWith('NAP', $topup->code);
        $this->assertTrue($topup->expires_at->between(now()->addHours(24)->subMinute(), now()->addHours(24)->addMinute()));

        $this->get(route('member.topups.show', $topup))->assertOk()->assertSee($topup->code)->assertSee('250.000đ')
            ->assertSee('data-bill-form', false)->assertSee('Tôi đã chuyển khoản');

        // Lệnh của người khác: 404
        $this->actingAs($this->member(), 'member')->get(route('member.topups.show', $topup))->assertNotFound();

        // Bắt buộc ảnh bill
        $this->actingAs($m, 'member')->from(route('member.topups.show', $topup))->post(route('member.topups.transferred', $topup))->assertSessionHasErrors('bill');
        $this->post(route('member.topups.transferred', $topup), $this->billUpload())->assertSessionHas('success');
        $topup->refresh();
        $this->assertSame(WalletTopup::STATUS_AWAITING, $topup->status);
        $this->assertNotNull($topup->billImage());
        $this->get(route('member.topups.bill', $topup))->assertOk();
        $this->get(route('member.topups.show', $topup))->assertSee('data-awaiting', false);
        // Chưa cộng ví khi chưa xác nhận
        $this->assertSame(0, $m->walletBalance(self::R));

        // Tối đa 3 lệnh chưa xong
        $this->post(route('member.topups.store'), ['amount' => '100000']);
        $this->post(route('member.topups.store'), ['amount' => '100000']);
        $this->from(route('member.topups.index'))->post(route('member.topups.store'), ['amount' => '100000'])->assertSessionHas('error');
        $this->assertSame(3, WalletTopup::count());
        // Tự hủy lệnh chưa báo chuyển khoản
        $last = WalletTopup::latest('id')->first();
        $this->post(route('member.topups.cancel', $last))->assertSessionHas('success');
        $this->assertSame(WalletTopup::STATUS_CANCELLED, $last->fresh()->status);
        $this->post(route('member.topups.cancel', $topup))->assertSessionHas('error');
    }

    public function test_accountant_confirms_a_topup_and_the_actual_amount_is_credited(): void
    {
        $m = $this->member();
        $topup = WalletTopup::start($m, 200000);
        $topup->forceFill(['status' => WalletTopup::STATUS_AWAITING, 'confirm_requested_at' => now()])->save();
        $url = route('backend.wallet-topups.confirm', $topup);

        // Quyền: CSKH không xem được, Kế toán thao tác được
        $this->actingAs($this->staff('CSKH'), 'web')->get(route('backend.wallet-topups.index'))->assertForbidden();
        $this->post($url, ['payment_reference' => 'FT1', 'paid_amount' => '200000'])->assertForbidden();
        $accountant = $this->staff('Kế toán');
        $this->actingAs($accountant, 'web')->get(route('backend.wallet-topups.index'))->assertOk()->assertSee($topup->code)->assertSee($m->full_name);
        $this->get(route('backend.wallet-topups.show', $topup))->assertOk()->assertSee('confirm-topup-form', false)->assertSee('reject-topup-form', false);

        // Thiếu tiền: không xác nhận được; mã VI- dành cho đơn ví
        $this->post($url, ['payment_reference' => 'ft1', 'paid_amount' => '150.000'])->assertSessionHasErrors('paid_amount');
        $this->post($url, ['payment_reference' => 'VI-ABC', 'paid_amount' => '200.000'])->assertSessionHasErrors('payment_reference');
        // Mã giao dịch dùng chung không gian với đơn hàng
        Order::factory()->status(Order::STATUS_PAID)->create(['payment_reference' => 'FT-ORDER']);
        $this->post($url, ['payment_reference' => 'ft-order', 'paid_amount' => '200.000'])
            ->assertSessionHasErrors(['payment_reference' => 'Mã giao dịch này đã dùng để xác nhận đơn hàng / lệnh nạp khác.']);

        // Chuyển thừa: cộng đúng số thực nhận
        $this->post($url, ['payment_reference' => 'ft1', 'paid_amount' => '210.000'])->assertSessionHas('success');
        $topup->refresh();
        $this->assertSame([WalletTopup::STATUS_PAID, 'FT1', 210000, $accountant->id], [$topup->status, $topup->payment_reference, $topup->paid_amount, $topup->confirmed_by]);
        $this->assertSame(210000, $m->walletBalance(self::R));
        $line = WalletTransaction::where('topup_id', $topup->id)->sole();
        $this->assertSame([WalletTransaction::TYPE_TOPUP, self::R, 210000], [$line->type, $line->wallet, $line->amount]);
        // Không tính doanh số / hoa hồng / kích hoạt
        $this->assertSame([0, null], [(int) $m->fresh()->cumulative_amount, $m->fresh()->activated_at]);
        $this->assertSame(0, Commission::count());

        // Bấm lại: không cộng hai lần; đơn hàng không dùng lại được mã này
        $this->post($url, ['payment_reference' => 'FT2', 'paid_amount' => '200000'])->assertSessionHas('error');
        $this->assertSame(210000, $m->walletBalance(self::R));
        $order = Order::factory()->create();
        $this->post(route('backend.orders.confirm-payment', $order), ['payment_reference' => 'FT1', 'paid_amount' => (string) $order->total_amount])
            ->assertSessionHasErrors('payment_reference');
        $this->get(route('backend.wallet-topups.show', $topup))->assertSee('Thừa 10.000 đ')->assertDontSee('confirm-topup-form', false);
        $this->actingAs($this->staff('Super admin'), 'web')->get(route('backend.members.show', $m))->assertOk()->assertSee('Giao dịch ví gần đây')->assertSee($topup->code);

        // Thành viên thấy đã cộng ví
        $this->actingAs($m, 'member')->get(route('member.topups.show', $topup))->assertSee('data-topup-paid', false)->assertSee('210.000đ');
    }

    public function test_reject_cancel_late_confirm_and_expiry_of_topups(): void
    {
        $m = $this->member();
        $accountant = $this->staff('Kế toán');
        $awaiting = WalletTopup::start($m, 100000);
        $awaiting->forceFill(['status' => WalletTopup::STATUS_AWAITING, 'confirm_requested_at' => now()])->save();

        // Chưa nhận được tiền (chuyển thiếu) → về Chờ chuyển khoản, hạn tính lại
        $this->actingAs($accountant, 'web')->post(route('backend.wallet-topups.reject', $awaiting), ['reason' => 'Mới nhận 80.000 đ'])->assertSessionHas('success');
        $awaiting->refresh();
        $this->assertSame([WalletTopup::STATUS_NEW, null], [$awaiting->status, $awaiting->confirm_requested_at]);
        $this->actingAs($m, 'member')->get(route('member.topups.show', $awaiting))->assertSee('data-bill-form', false);

        // Hủy có lý do
        $this->actingAs($accountant, 'web')->post(route('backend.wallet-topups.cancel', $awaiting), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post(route('backend.wallet-topups.cancel', $awaiting), ['reason' => 'Tạo nhầm lệnh'])->assertSessionHas('success');
        $this->assertSame([WalletTopup::STATUS_CANCELLED, WalletTopup::CANCEL_ADMIN], [$awaiting->fresh()->status, $awaiting->fresh()->cancel_source]);

        // Tiền về muộn: xác nhận lệnh đã hủy cần ghi chú
        $this->post(route('backend.wallet-topups.confirm', $awaiting), ['payment_reference' => 'FT9', 'paid_amount' => '100000'])->assertSessionHasErrors('payment_note');
        $this->post(route('backend.wallet-topups.confirm', $awaiting), ['payment_reference' => 'FT9', 'paid_amount' => '100000', 'payment_note' => 'Tiền về sau'])->assertSessionHas('success');
        $this->assertSame(100000, $m->walletBalance(self::R));

        // Quá hạn: lệnh mới tự hủy, lệnh chờ xác nhận thì không
        $fresh = WalletTopup::start($m, 100000);
        $reported = WalletTopup::start($m, 100000);
        $reported->forceFill(['status' => WalletTopup::STATUS_AWAITING])->save();
        $this->travel(25)->hours();
        $this->assertSame(WalletTopup::STATUS_CANCELLED, $fresh->fresh()->displayStatus());
        $this->artisan('topups:expire')->assertSuccessful();
        $this->assertSame([WalletTopup::STATUS_CANCELLED, WalletTopup::CANCEL_EXPIRED], [$fresh->fresh()->status, $fresh->fresh()->cancel_source]);
        $this->assertSame(WalletTopup::STATUS_AWAITING, $reported->fresh()->status);
    }

    public function test_existing_accountant_groups_get_the_topup_permission(): void
    {
        $this->assertTrue(Group::where('name', 'Kế toán')->sole()->hasPermission('wallet_topup/confirm'));
        $this->assertFalse(Group::where('name', 'CSKH')->sole()->hasPermission('wallet_topup'));
        $this->assertTrue(Group::where('name', 'Super admin')->sole()->hasPermission('wallet_topup/confirm'));
    }
}
