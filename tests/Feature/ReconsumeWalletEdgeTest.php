<?php

namespace Tests\Feature;

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
use App\Support\NotificationSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * Ví tái tiêu dùng — các trường hợp biên: quyền truy cập, lệnh của người khác, sửa tay dữ liệu form, hết hàng, lên cấp / F2
 * từ đơn trả bằng ví, gửi lại bill, thông báo, ví không bao giờ âm.
 */
class ReconsumeWalletEdgeTest extends TestCase
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

    private function fund(Member $member, int $amount, string $wallet = self::R): void
    {
        DB::transaction(fn () => Wallet::post($member, $wallet, WalletTransaction::TYPE_TOPUP, $amount, 'Số dư thử'));
    }

    private function staff(string $group): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $group)->value('id')]);
    }

    private function product(array $data = []): Product
    {
        $category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);

        return Product::factory()->create(['category_id' => $category->id, 'status' => true, 'price' => 1000000, ...$data]);
    }

    private function payByWallet(Member $member, Product $product, int $qty)
    {
        return $this->actingAs($member, 'member')->withSession([Cart::SESSION_KEY => [$product->id => $qty]])->from(route('shop.checkout'))
            ->post(route('shop.checkout.store'), ['recipient_name' => 'A', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship',
                'province' => 'ha-noi', 'address' => '1 Phố Huế', 'payment_method' => Order::PAYMENT_WALLET]);
    }

    public function test_guests_and_locked_members_cannot_use_wallet_pages(): void
    {
        $topup = WalletTopup::start(Member::factory()->create(), 100000);
        $gets = [route('member.wallet.history'), route('member.wallet.transfer'), route('member.wallet.convert'), route('member.topups.index'),
            route('member.topups.show', $topup), route('member.topups.bill', $topup)];
        $posts = [route('member.wallet.transfer.preview'), route('member.wallet.transfer.store'), route('member.wallet.convert.store'),
            route('member.topups.store'), route('member.topups.transferred', $topup), route('member.topups.cancel', $topup)];
        foreach ($gets as $url) {
            $this->get($url)->assertRedirect(route('member.login'));
        }
        foreach ($posts as $url) {
            $this->post($url, ['amount' => '100000'])->assertRedirect(route('member.login'));
        }
        $this->assertSame([1, 0], [WalletTopup::count(), WalletTransaction::count()]);

        $locked = Member::factory()->locked()->create();
        $this->fund($locked, 100000, self::C);
        $this->actingAs($locked, 'member')->post(route('member.wallet.convert.store'), ['amount' => '50000'])->assertRedirect(route('member.login'));
        $this->assertSame(100000, $locked->walletBalance(self::C));

        // Quản trị chưa đăng nhập / không có quyền
        $this->get(route('backend.wallet-topups.index'))->assertRedirect();
        $this->actingAs($this->staff('Vận hành'), 'web')->get(route('backend.wallet-topups.show', $topup))->assertForbidden();
        $this->post(route('backend.wallet-topups.cancel', $topup), ['reason' => 'Thử hủy lệnh'])->assertForbidden();
        $this->assertSame(WalletTopup::STATUS_NEW, $topup->fresh()->status);
    }

    public function test_members_cannot_touch_another_members_topup(): void
    {
        $owner = Member::factory()->create();
        $other = Member::factory()->create();
        $topup = WalletTopup::start($owner, 100000);
        $this->actingAs($owner, 'member')->post(route('member.topups.transferred', $topup), $this->billUpload())->assertSessionHas('success');

        $this->actingAs($other, 'member');
        $this->get(route('member.topups.show', $topup))->assertNotFound();
        $this->get(route('member.topups.bill', $topup))->assertNotFound();
        $this->post(route('member.topups.cancel', $topup))->assertNotFound();
        $this->post(route('member.topups.transferred', $topup), ['bill' => UploadedFile::fake()->image('x.jpg', 600, 900)])->assertNotFound();
        $this->get(route('member.topups.index'))->assertDontSee($topup->code);
        // Ảnh bill: chỉ quản trị có quyền nạp ví xem được
        $this->actingAs($this->staff('CSKH'), 'web')->get(route('backend.wallet-topups.bill', $topup))->assertForbidden();
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.wallet-topups.bill', $topup))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_tampered_or_odd_transfer_inputs_never_overdraw(): void
    {
        $a = Member::factory()->create();
        MemberKyc::factory()->create(['member_id' => $a->id, 'status' => MemberKyc::STATUS_APPROVED]);
        $b = Member::factory()->create(); // người nhận không cần KYC
        $this->fund($a, 50000);
        $send = fn (array $data) => $this->actingAs($a, 'member')->from(route('member.wallet.transfer'))
            ->post(route('member.wallet.transfer.store'), ['code' => $b->referral_code, 'password' => 'password', ...$data]);

        // Sửa ô ẩn ở trang xác nhận thành số lớn hơn số dư / âm / không phải số
        $send(['amount' => '50001'])->assertRedirect(route('member.wallet.transfer'))->assertSessionHasErrors('amount');
        $send(['amount' => '-20000'])->assertSessionHasErrors('amount');
        $send(['amount' => 'abc'])->assertSessionHasErrors('amount');
        $send(['amount' => ['10000']])->assertSessionHasErrors('amount');
        $send(['amount' => '1.000.000.001'])->assertSessionHasErrors(['amount' => 'Số tiền quá lớn.']);
        $send(['amount' => '20000', 'code' => ['x']])->assertSessionHasErrors('code');
        $this->assertSame([50000, 0], [$a->walletBalance(self::R), $b->walletBalance(self::R)]);

        $send(['amount' => '50.000'])->assertSessionHas('success');
        $this->assertSame([0, 50000], [$a->walletBalance(self::R), $b->walletBalance(self::R)]);

        // Lớp cuối: Wallet::post không cho số dư âm dù được gọi thẳng
        $this->expectException(RuntimeException::class);
        DB::transaction(fn () => Wallet::post($a, self::R, WalletTransaction::TYPE_PURCHASE, -1, 'thử'));
    }

    public function test_wallet_order_out_of_stock_takes_nothing_and_paid_wallet_order_cannot_be_cancelled(): void
    {
        $buyer = Member::factory()->create();
        $this->fund($buyer, 5000000);
        $product = $this->product(['stock' => 1]);

        $this->payByWallet($buyer, $product, 2)->assertRedirect(route('shop.cart'))->assertSessionHas('error');
        $this->assertSame([0, 5000000, 1], [Order::count(), $buyer->walletBalance(self::R), $product->fresh()->stock]);

        $this->payByWallet($buyer, $product, 1)->assertSessionHas('success');
        $order = Order::sole();
        $this->post(route('member.orders.cancel', $order))->assertSessionHas('error');
        $this->actingAs($this->staff('Super admin'), 'web')->post(route('backend.orders.cancel', $order), ['reason' => 'Thử hủy đơn ví'])->assertSessionHas('error');
        $this->assertSame([Order::STATUS_PAID, 4000000], [$order->fresh()->status, $buyer->walletBalance(self::R)]);
        // Đơn đã thanh toán không xác nhận lại được (không cộng doanh số / hoa hồng hai lần)
        $this->post(route('backend.orders.confirm-payment', $order), ['payment_reference' => 'FT77', 'paid_amount' => '1000000'])->assertSessionHas('error');
        $this->assertSame(1000000, (int) $buyer->fresh()->cumulative_amount);
    }

    public function test_wallet_order_levels_up_the_buyer_and_pays_f1_and_f2(): void
    {
        $agent = Member::factory()->create(['level_id' => Level::where('name', 'Đại lý')->value('id'), 'activated_at' => now()->subDay()]);
        $ctv = Member::factory()->create(['referrer_id' => $agent->id, 'level_id' => Level::where('name', 'Cộng tác viên')->value('id'), 'activated_at' => now()->subDay()]);
        $buyer = Member::factory()->create(['referrer_id' => $ctv->id]);
        $this->fund($buyer, 3000000);
        // Tuyến trên chuyển ví cho người mua (cùng chuỗi người bị khóa khi thanh toán)
        MemberKyc::factory()->create(['member_id' => $agent->id, 'status' => MemberKyc::STATUS_APPROVED]);
        $this->fund($agent, 100000);
        $this->assertIsArray(Wallet::transfer($agent, $buyer, 100000));
        $this->assertSame([$buyer->id, $ctv->id, $agent->id], Wallet::buyerChainIds($buyer->id));

        $this->payByWallet($buyer, $this->product(['price' => 3000000]), 1)->assertSessionHas('success');
        $order = Order::sole();
        $this->assertSame('Cộng tác viên', $buyer->fresh()->level->name);
        $this->assertSame(100000, $buyer->walletBalance(self::R));
        $lines = Commission::where('order_id', $order->id)->orderBy('tier')->get();
        // Cơ sở 3.000.000 × 95% = 2.850.000; F1 (CTV 10%) = 285.000, F2 (Đại lý 7%) = 199.500 — chia 70 / 20 / 10
        $this->assertSame([['F1', $ctv->id, 285000], ['F2', $agent->id, 199500]], $lines->map(fn ($l) => [$l->tier, $l->beneficiary_id, $l->amount])->all());
        $this->assertSame(57000, $ctv->walletBalance(self::R));
        $this->assertSame(199500 - 19950 - 39900, $agent->walletBalance(self::C));
    }

    public function test_topup_bill_resubmitted_after_reject_replaces_the_old_file_and_overdue_topup_cannot_be_reported(): void
    {
        $m = Member::factory()->create();
        $topup = WalletTopup::start($m, 100000);
        $this->actingAs($m, 'member')->post(route('member.topups.transferred', $topup), $this->billUpload())->assertSessionHas('success');
        $first = $topup->fresh()->payment_bill;
        $this->actingAs($this->staff('Kế toán'), 'web')->post(route('backend.wallet-topups.reject', $topup), ['reason' => 'Chưa thấy tiền về'])->assertSessionHas('success');
        Storage::disk(MemberKyc::DISK)->put($first, 'cu'); // billUpload() tạo disk giả mới — đặt lại file cũ để kiểm tra bị xóa
        $this->actingAs($m, 'member')->post(route('member.topups.transferred', $topup), ['bill' => UploadedFile::fake()->image('b2.jpg', 600, 900)])->assertSessionHas('success');
        $second = $topup->fresh()->payment_bill;
        $this->assertNotSame($first, $second);
        Storage::disk(MemberKyc::DISK)->assertMissing($first);
        Storage::disk(MemberKyc::DISK)->assertExists($second);

        // Lệnh quá hạn: không báo chuyển khoản / không tự hủy được nữa
        $late = WalletTopup::start($m, 100000);
        $this->travel(25)->hours();
        $this->post(route('member.topups.transferred', $late), $this->billUpload())->assertSessionHas('error');
        $this->get(route('member.topups.show', $late))->assertSee('data-topup-cancelled', false)->assertDontSee('data-bill-form', false);
        // Lệnh đã cộng ví không hủy / trả về được
        $this->actingAs($this->staff('Kế toán'), 'web')->post(route('backend.wallet-topups.confirm', $topup), ['payment_reference' => 'FT5', 'paid_amount' => '100000']);
        $this->post(route('backend.wallet-topups.cancel', $topup), ['reason' => 'Thử hủy sau khi cộng'])->assertSessionHas('error');
        $this->post(route('backend.wallet-topups.reject', $topup), ['reason' => 'Thử trả về sau khi cộng'])->assertSessionHas('error');
        $this->assertSame([WalletTopup::STATUS_PAID, 100000], [$topup->fresh()->status, $m->walletBalance(self::R)]);
        // Hai lệnh không dùng chung một mã giao dịch
        $again = WalletTopup::start($m, 100000);
        $this->post(route('backend.wallet-topups.confirm', $again), ['payment_reference' => 'fT5', 'paid_amount' => '100000'])->assertSessionHasErrors('payment_reference');
    }

    public function test_topup_amount_limits(): void
    {
        $m = Member::factory()->create();
        $this->actingAs($m, 'member')->from(route('member.topups.index'));
        $this->post(route('member.topups.store'), ['amount' => ''])->assertSessionHasErrors(['amount' => 'Nhập số tiền muốn nạp.']);
        $this->post(route('member.topups.store'), ['amount' => '1.000.000.001'])->assertSessionHasErrors(['amount' => 'Số tiền quá lớn.']);
        $this->post(route('member.topups.store'), ['amount' => 'mot tram nghin'])->assertSessionHasErrors('amount');
        $this->post(route('member.topups.store'), ['amount' => '100.000'])->assertRedirect();
        $this->assertSame([100000], WalletTopup::pluck('amount')->all());
    }

    public function test_staff_notifications_for_wallet_order_and_topup(): void
    {
        NotificationSettings::save(['telegram_enabled' => true, 'telegram_token' => '1234567890:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw9',
            'telegram_chat_id' => '-1001234567890', 'events' => ['order_created' => true, 'order_transferred' => true, 'topup_transferred' => true]]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $buyer = Member::factory()->create(['full_name' => 'Phạm Văn D']);
        $this->fund($buyer, 1000000);

        $this->payByWallet($buyer, $this->product(), 1)->assertSessionHas('success');
        $order = Order::sole();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'Đơn hàng mới '.$order->code) && str_contains($r['text'], 'đã thanh toán bằng ví')
            && str_contains($r['text'], 'Thanh toán: ví tái tiêu dùng') && ! str_contains($r['text'], 'Hạn thanh toán'));

        $topup = WalletTopup::start($buyer, 300000);
        $this->post(route('member.topups.transferred', $topup), $this->billUpload())->assertSessionHas('success');
        Http::assertSent(fn (HttpRequest $r) => str_contains($r['text'], 'Thành viên báo đã chuyển khoản nạp ví — '.$topup->code)
            && str_contains($r['text'], '300.000 đ') && str_contains($r['text'], 'Phạm Văn D') && str_contains($r['text'], route('backend.wallet-topups.show', $topup)));
    }

    public function test_history_filter_and_backend_lists(): void
    {
        $m = Member::factory()->create();
        $this->fund($m, 70000, self::C);
        $this->actingAs($m, 'member')->post(route('member.wallet.convert.store'), ['amount' => '20000']);
        $this->get(route('member.wallet.history', ['vi' => self::C]))->assertOk()->assertSee('Chuyển sang ví tái tiêu dùng')->assertDontSee('Chuyển từ ví rút tiền');
        $this->get(route('member.wallet.history', ['vi' => 'khac']))->assertOk()->assertSee('Chuyển từ ví rút tiền'); // vi lạ = tất cả

        $new = WalletTopup::start($m, 100000);
        $awaiting = WalletTopup::start($m, 200000);
        $awaiting->forceFill(['status' => WalletTopup::STATUS_AWAITING, 'confirm_requested_at' => now()])->save();
        $this->actingAs($this->staff('Kế toán'), 'web');
        $this->get(route('backend.wallet-topups.index'))->assertOk()->assertSee($awaiting->code)->assertDontSee($new->code);
        $this->get(route('backend.wallet-topups.index', ['tab' => 'new']))->assertSee($new->code)->assertDontSee($awaiting->code);
        $this->get(route('backend.wallet-topups.index', ['tab' => 'all', 'q' => $m->referral_code]))->assertSee($new->code)->assertSee($awaiting->code);
        $this->get(route('backend.wallet-topups.index', ['tab' => 'all', 'q' => 'khongtontai']))->assertSee('Không tìm thấy lệnh nào');
        $this->get(route('backend.wallet-topups.show', $new))->assertOk()->assertSee('confirm-topup-form', false)->assertDontSee('reject-topup-form', false);
    }
}
