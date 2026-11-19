<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\CommissionRate;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Http\Requests\Backend\Affiliate\AffiliateSettingRequest;
use App\Support\AffiliateSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chia hoa hồng (19/11/2026): mỗi khoản hoa hồng X → 70% ví có thể rút, 20% ví tái tiêu dùng, 10% thuế TNCN khấu trừ.
 * Ví dụ của chủ dự án: khách vào 12.000.000 − 5% phí DN = 11.400.000 × 20% = 2.280.000 → rút được 1.596.000.
 */
class WalletSplitTest extends TestCase
{
    use RefreshDatabase;

    private User $accountant;

    private int $reference = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $this->travel(2)->hours();
        foreach (['Nhân viên' => [0, 0], 'Cộng tác viên' => [10, 7], 'Đại lý' => [20, 7]] as $name => [$f1, $f2]) {
            $levelId = Level::where('name', $name)->value('id');
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F1', 'rate_percent' => $f1, 'effective_from' => now()->subHour()]);
            CommissionRate::create(['level_id' => $levelId, 'tier' => 'F2', 'rate_percent' => $f2, 'effective_from' => now()->subHour()]);
        }
    }

    private function member(?string $level, ?Member $referrer = null, array $attributes = []): Member
    {
        return Member::factory()->create([
            'referrer_id' => $referrer?->id,
            ...($level ? ['level_id' => Level::where('name', $level)->value('id'), 'activated_at' => now()->subDays(5)] : []),
            ...$attributes,
        ]);
    }

    /** Đơn có các dòng sản phẩm [tên, số lượng, đơn giá]; tiền hàng = tổng các dòng, ship 30.000. */
    private function order(Member $buyer, array $items): Order
    {
        $goods = array_sum(array_map(fn ($item) => $item[1] * $item[2], $items));
        $order = Order::factory()->create([
            'member_id' => $buyer->id, 'goods_amount' => $goods, 'vat_amount' => 0, 'shipping_fee' => 30000, 'total_amount' => $goods + 30000,
        ]);
        foreach ($items as [$name, $qty, $price]) {
            OrderItem::create([
                'order_id' => $order->id, 'product_id' => Product::factory()->create()->id, 'product_name' => $name, 'qty' => $qty,
                'unit_price' => $price, 'vat_rate' => 0, 'line_amount' => $qty * $price, 'vat_amount' => 0,
            ]);
        }

        return $order;
    }

    private function pay(Order $order)
    {
        return $this->actingAs($this->accountant, 'web')->post(route('backend.orders.confirm-payment', $order),
            ['payment_reference' => 'FT'.(++$this->reference), 'paid_amount' => (string) $order->total_amount]);
    }

    /** [hoa hồng, ví có thể rút, ví tái tiêu dùng, thuế, % tái tiêu dùng, % thuế] */
    private function split(Order $order, string $tier): array
    {
        $line = Commission::where('order_id', $order->id)->where('tier', $tier)->sole();

        return [$line->amount, $line->cash_amount, $line->reconsume_amount, $line->tax_amount, $line->reconsume_percent, $line->tax_percent];
    }

    public function test_owner_example_splits_70_20_10_into_two_wallets_and_tax(): void
    {
        $f2 = $this->member('Đại lý');
        $f1 = $this->member('Đại lý', $f2);
        $order = $this->order($this->member(null, $f1), [['Sản phẩm A', 2, 5_000_000], ['Sản phẩm B', 1, 2_000_000]]);
        $this->pay($order)->assertSessionHas('success');

        // 12.000.000 × 95% = 11.400.000 → F1 20% = 2.280.000; F2 7% = 798.000
        $this->assertSame([2_280_000, 1_596_000, 456_000, 228_000, '20.00', '10.00'], $this->split($order, 'F1'));
        $this->assertSame([798_000, 558_600, 159_600, 79_800, '20.00', '10.00'], $this->split($order, 'F2'));

        $this->assertSame([1_596_000, 456_000], [$f1->walletBalance(), $f1->walletBalance(WalletTransaction::WALLET_RECONSUME)]);
        $this->assertSame([558_600, 159_600], [$f2->walletBalance(), $f2->walletBalance(WalletTransaction::WALLET_RECONSUME)]);
        $this->assertSame([['cash', 'commission', 1_596_000, 1_596_000], ['reconsume', 'commission', 456_000, 456_000]],
            WalletTransaction::where('member_id', $f1->id)->orderBy('id')->get()->map(fn ($t) => [$t->wallet, $t->type, $t->amount, $t->balance_after])->all());

        // Đơn thứ hai: số dư sau bút toán tính riêng từng ví
        $this->pay($this->order($this->member(null, $f1), [['Sản phẩm C', 1, 1_000_000]]));   // 950.000 × 20% = 190.000
        $this->assertSame([1_596_000 + 133_000, 456_000 + 38_000], [$f1->walletBalance(), $f1->walletBalance(WalletTransaction::WALLET_RECONSUME)]);
        $this->assertSame(494_000, WalletTransaction::where('member_id', $f1->id)->where('wallet', 'reconsume')->latest('id')->value('balance_after'));

        $summary = $f1->walletSummary();
        $this->assertSame([1_729_000, 494_000, 2_470_000, 247_000, 1_729_000], [$summary['balance'], $summary['reconsume'], $summary['total'], $summary['tax'], $summary['month_cash']]);
    }

    public function test_rounding_goes_to_the_cash_wallet_and_settings_change_the_split(): void
    {
        $top = $this->member('Cộng tác viên');
        // 42.345 → cơ sở 40.227 → 10% = 4.022: thuế floor(402,2) = 402, tái tiêu dùng floor(804,4) = 804, rút được 2.816
        $order = $this->order($this->member(null, $top), [['Kẹo', 1, 42_345]]);
        $this->pay($order);
        $this->assertSame([4_022, 2_816, 804, 402, '20.00', '10.00'], $this->split($order, 'F1'));

        Setting::put('aff_pit_percent', '5.00');
        Setting::put('aff_reconsume_percent', '0.00');
        $next = $this->order($this->member(null, $top), [['Trà', 1, 1_000_000]]);           // 950.000 × 10% = 95.000
        $this->pay($next);
        $this->assertSame([95_000, 90_250, 0, 4_750, '0.00', '5.00'], $this->split($next, 'F1'));
        $this->assertSame(0, WalletTransaction::where('commission_id', Commission::where('order_id', $next->id)->value('id'))->where('wallet', 'reconsume')->count());
    }

    public function test_settings_reject_tax_plus_reconsume_over_100_percent(): void
    {
        $values = collect(AffiliateSettings::all())->only(AffiliateSettingRequest::fieldKeys())->map(fn ($v) => $v === null ? '' : (string) $v)->all();
        $this->actingAs(User::factory()->superAdmin()->create(), 'web')
            ->put('/backend/affiliate/settings', [...$values, 'pit_percent' => '50', 'reconsume_percent' => '60'])
            ->assertSessionHasErrors('reconsume_percent');
        $this->assertSame(['10.00', '20.00'], [AffiliateSettings::get('pit_percent'), AffiliateSettings::get('reconsume_percent')]);
    }

    public function test_member_wallet_page_shows_both_wallets_and_full_breakdown(): void
    {
        $f1 = $this->member('Đại lý');
        $buyer = $this->member(null, $f1, ['full_name' => 'Đinh Thu Hà']);
        $order = $this->order($buyer, [['Sản phẩm A', 2, 5_000_000], ['Sản phẩm B', 1, 2_000_000]]);
        $this->pay($order);

        $this->actingAs($f1->fresh(), 'member')->get('/tai-khoan')->assertOk()
            ->assertSeeInOrder(['Ví có thể rút', 'data-wallet-balance>1.596.000đ<', 'Ví tái tiêu dùng', 'data-wallet-reconsume>456.000đ<'], false);

        $this->get('/tai-khoan/vi')->assertOk()
            ->assertSee('data-wallet-balance>1.596.000đ<', false)->assertSee('data-wallet-reconsume>456.000đ<', false)
            ->assertDontSee('data-wallet-total', false)->assertDontSee('data-wallet-tax', false)
            // Dòng gọn (bấm mở popup) rồi popup chi tiết cách tính
            ->assertSeeInOrder(['data-wallet-rows', 'data-dialog-open="commission-', 'F1', 'Đinh Thu Hà', $order->code, '+1.596.000đ', 'vào ví có thể rút',
                '<dialog id="commission-', 'Đinh Thu Hà', 'Tiền hàng', 'không tính phí giao hàng', '12.000.000đ',
                'Sản phẩm A × 2', '10.000.000đ', 'Sản phẩm B × 1', '2.000.000đ',
                '− 5% phí doanh nghiệp', '−600.000đ',
                'Cơ sở tính hoa hồng', '11.400.000đ',
                'Hoa hồng = 11.400.000đ × 20%', '(cấp Đại lý)', '2.280.000đ',
                'Ví có thể rút (70%)', '+1.596.000đ',
                'Ví tái tiêu dùng (20%)', '+456.000đ',
                'Thuế thu nhập cá nhân (10%)', '−228.000đ'], false)
            ->assertDontSee($buyer->phone);
    }

    public function test_history_filters_by_period_and_compares_with_the_previous_period(): void
    {
        // Tỉ lệ có hiệu lực từ trước mọi đơn mẫu
        $levelId = Level::where('name', 'Đại lý')->value('id');
        CommissionRate::create(['level_id' => $levelId, 'tier' => 'F1', 'rate_percent' => 20, 'effective_from' => '2025-01-01 00:00:00']);
        $f1 = $this->member('Đại lý', null, ['activated_at' => '2025-01-01 00:00:00']);
        $buyer = $this->member(null, $f1);
        $at = function (string $date, int $goods) use ($buyer) {
            $this->travelTo(\Illuminate\Support\Carbon::parse($date));
            $order = $this->order($buyer, [['SP', 1, $goods]]);
            $this->pay($order);

            return $order;
        };
        $lastYear = $at('2025-12-10 09:00', 1_000_000);      // 190.000
        $october = $at('2026-10-05 09:00', 2_000_000);       // 380.000
        $lastWeek = $at('2026-11-12 09:00', 1_000_000);      // 190.000 (thứ Năm tuần trước)
        $thisWeek = $at('2026-11-17 09:00', 3_000_000);      // 570.000 (thứ Ba tuần này)
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-19 10:00'));
        $this->actingAs($f1->fresh(), 'member');

        // Mặc định tháng này: 2 đơn tháng 11, so với tháng 10
        $this->get('/tai-khoan/vi')->assertOk()
            ->assertSeeInOrder(['aria-current="page"', 'Tháng này'], false)
            ->assertSee('data-period-total>760.000đ<', false)->assertSee('Tháng trước: <span class="text-slate-700">380.000đ</span>', false)
            ->assertSee('▲ +100% so với tháng trước')
            ->assertSee($thisWeek->code)->assertSee($lastWeek->code)->assertDontSee($october->code)->assertDontSee($lastYear->code)
            ->assertSee('data-wallet-chart', false);

        $this->get('/tai-khoan/vi?ky=tuan')->assertOk()->assertSee('data-period-total>570.000đ<', false)
            ->assertSee($thisWeek->code)->assertDontSee($lastWeek->code)->assertSee('▲ +200% so với tuần trước');
        $this->get('/tai-khoan/vi?ky=thang-truoc')->assertOk()->assertSee('data-period-total>380.000đ<', false)
            ->assertSee($october->code)->assertDontSee($thisWeek->code);
        $this->get('/tai-khoan/vi?ky=nam')->assertOk()->assertSee('data-period-total>1.140.000đ<', false)
            ->assertDontSee($lastYear->code)->assertSee('Năm trước: <span class="text-slate-700">190.000đ</span>', false);
        // Kỳ lạ → về tháng này
        $this->get('/tai-khoan/vi?ky=abc')->assertOk()->assertSeeInOrder(['aria-current="page"', 'Tháng này'], false);

        // Dữ liệu biểu đồ tuần: lũy kế theo ngày, kỳ này dừng ở hôm nay (thứ Năm)
        $html = $this->get('/tai-khoan/vi?ky=tuan')->getContent();
        preg_match("/data-points='([^']+)'/", $html, $m);
        $this->assertSame([['T2', 'Thứ 2', 0, 0], ['T3', 'Thứ 3', 570000, 0], ['T4', 'Thứ 4', 570000, 0], ['T5', 'Thứ 5', 570000, 190000],
            ['T6', 'Thứ 6', null, 190000], ['T7', 'Thứ 7', null, 190000], ['CN', 'Chủ nhật', null, 190000]], json_decode(html_entity_decode($m[1]), true));

        // Kỳ không có gì: không vẽ biểu đồ
        $this->actingAs($buyer->fresh(), 'member')->get('/tai-khoan/vi')->assertOk()
            ->assertDontSee('data-wallet-chart', false)->assertSee('Không có hoa hồng nào trong tháng này');
    }

    public function test_admin_sees_split_on_member_ledger_and_order(): void
    {
        $f1 = $this->member('Đại lý');
        $order = $this->order($this->member(null, $f1), [['Sản phẩm A', 1, 12_000_000]]);
        $this->pay($order);

        $this->actingAs($this->accountant, 'web')->get(route('backend.members.commissions', $f1))->assertOk()
            ->assertSee('data-wallet-balance>1.596.000 đ<', false)->assertSee('data-wallet-reconsume>456.000 đ<', false)->assertSee('data-wallet-tax>228.000 đ<', false)
            ->assertSeeInOrder([$order->code, '2.280.000 đ', '+1.596.000 đ', '70%', '+456.000 đ', '20%', '−228.000 đ', '10%'], false);
        $this->get(route('backend.orders.show', $order))->assertOk()
            ->assertSee('Ví có thể rút +1.596.000 đ · tái tiêu dùng +456.000 đ · thuế TNCN −228.000 đ');
    }

    public function test_migration_splits_old_commissions_like_new_ones_append_only_and_once(): void
    {
        // Dữ liệu cũ: hoa hồng đã cộng 100% vào ví, chưa có thông tin chia
        Setting::put('aff_pit_percent', '0.00');
        Setting::put('aff_reconsume_percent', '0.00');
        $f2 = $this->member('Đại lý');
        $f1 = $this->member('Đại lý', $f2);
        $this->pay($order = $this->order($this->member(null, $f1), [['A', 1, 12_000_000]]));
        $this->pay($this->order($this->member(null, $f1, ['status' => Member::STATUS_ACTIVE]), [['B', 1, 1_000_000]]));
        DB::table('commission_ledger')->update(['reconsume_percent' => null, 'tax_percent' => null, 'cash_amount' => 0, 'reconsume_amount' => 0, 'tax_amount' => 0]);
        $oldTransactions = WalletTransaction::orderBy('id')->get()->toArray();
        $this->assertSame(2_280_000 + 190_000, $f1->walletBalance());
        Setting::where('key', 'aff_pit_percent')->delete();
        Setting::where('key', 'aff_reconsume_percent')->delete();

        $split = fn () => (new \ReflectionMethod($migration = require database_path('migrations/2026_11_28_000001_split_commission_wallets.php'), 'splitExisting'))->invoke($migration);
        $split();

        // Kết quả giống hệt đơn mới
        $this->assertSame([2_280_000, 1_596_000, 456_000, 228_000, '20.00', '10.00'], $this->split($order, 'F1'));
        $this->assertSame([798_000, 558_600, 159_600, 79_800, '20.00', '10.00'], $this->split($order, 'F2'));
        $this->assertSame([1_596_000 + 133_000, 456_000 + 38_000], [$f1->walletBalance(), $f1->walletBalance(WalletTransaction::WALLET_RECONSUME)]);
        $this->assertSame([558_600 + 46_550, 159_600 + 13_300], [$f2->walletBalance(), $f2->walletBalance(WalletTransaction::WALLET_RECONSUME)]);
        $this->assertSame(1_729_000, WalletTransaction::where('member_id', $f1->id)->where('wallet', 'cash')->latest('id')->value('balance_after'));

        // Chỉ ghi thêm: bút toán cũ còn nguyên; mỗi dòng thêm 1 bút toán trừ ví rút + 1 bút toán cộng ví tái tiêu dùng
        $this->assertSame($oldTransactions, WalletTransaction::whereIn('id', array_column($oldTransactions, 'id'))->orderBy('id')->get()->toArray());
        $this->assertSame([4, 4], [WalletTransaction::where('type', 'split')->count(), WalletTransaction::where('wallet', 'reconsume')->count()]);
        $this->assertSame(-(456_000 + 228_000), WalletTransaction::where('type', 'split')->where('member_id', $f1->id)->orderBy('id')->value('amount'));
        $this->assertTrue(AuditLog::where('auditable_type', Commission::class)->where('description', 'like', 'Chia lại 4 dòng hoa hồng cũ%')->exists());

        // Chạy lại không chia hai lần
        $count = WalletTransaction::count();
        $split();
        $this->assertSame([$count, 1_729_000], [WalletTransaction::count(), $f1->walletBalance()]);
    }
}
