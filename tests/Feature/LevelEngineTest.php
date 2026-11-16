<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Level;
use App\Models\LevelHistory;
use App\Models\Member;
use App\Models\Order;
use App\Models\User;
use App\Services\LevelEngine;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 7 – Engine lên cấp: đơn được xác nhận thanh toán → cộng tiền hàng (gồm VAT, không gồm ship) vào doanh số cộng dồn,
 * kích hoạt ở đơn đầu, xét cấp theo mốc (đạt mốc = ≥). Nhảy nhiều cấp trong một đơn, không hạ cấp, không cộng đôi.
 * Cấp mặc định (seeder): Chưa kích hoạt 0 · Nhân viên 1 · Cộng tác viên 3tr · Đại lý 15tr · 150tr · 300tr.
 */
class LevelEngineTest extends TestCase
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
    }

    /** Tạo đơn của thành viên với tiền hàng $goods (+30.000 ship) rồi kế toán xác nhận thanh toán. */
    private function payOrder(Member $member, int $goods, array $attributes = [])
    {
        $order = Order::factory()->create(['member_id' => $member->id, 'goods_amount' => $goods, 'shipping_fee' => 30000, 'total_amount' => $goods + 30000, ...$attributes]);

        return $this->confirm($order);
    }

    private function confirm(Order $order, array $data = [])
    {
        return $this->actingAs($this->accountant, 'web')->post(route('backend.orders.confirm-payment', $order),
            ['payment_reference' => 'FT'.(++$this->reference), 'paid_amount' => (string) $order->total_amount, ...$data]);
    }

    private function levelOf(Member $member): string
    {
        return $member->fresh()->level->name;
    }

    public function test_first_paid_order_activates_and_makes_member_staff(): void
    {
        $member = Member::factory()->create(['full_name' => 'Nguyễn Văn A']);
        $this->assertSame(['Chưa kích hoạt', 0, null], [$this->levelOf($member), $member->cumulative_amount, $member->activated_at]);

        // Đặt đơn chưa thanh toán: chưa tính gì
        $order = Order::factory()->create(['member_id' => $member->id, 'goods_amount' => 200000, 'shipping_fee' => 30000, 'total_amount' => 230000]);
        $this->assertSame(['Chưa kích hoạt', 0], [$this->levelOf($member), $member->fresh()->cumulative_amount]);

        // Kế toán xác nhận thanh toán: cộng tiền hàng (không cộng phí ship), kích hoạt, lên "Nhân viên"
        $this->confirm($order)->assertSessionHas('success', "Đã xác nhận thanh toán đơn {$order->code}. Tài khoản thành viên được kích hoạt. Thành viên lên cấp Nhân viên.");
        $member->refresh();
        $this->assertSame(['Nhân viên', 200000], [$member->level->name, $member->cumulative_amount]);
        $this->assertNotNull($member->activated_at);
        $this->assertNotNull($order->fresh()->sales_counted_at);

        $history = LevelHistory::sole();
        $this->assertSame([$member->id, 'Chưa kích hoạt', 'Nhân viên', $order->id, 200000],
            [$history->member_id, $history->fromLevel->name, $history->toLevel->name, $history->order_id, $history->cumulative_after]);
        $log = AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)->latest('id')->first();
        $this->assertSame("Đơn {$order->code} đã thanh toán: doanh số cộng dồn 0 → 200.000 đ; kích hoạt tài khoản; lên cấp Chưa kích hoạt → Nhân viên", $log->description);

        // Đơn thứ hai nhỏ: cộng dồn tiếp, vẫn "Nhân viên", không ghi thêm vết lên cấp
        $this->payOrder($member, 300000)->assertSessionHas('success', fn ($message) => ! str_contains($message, 'lên cấp') && ! str_contains($message, 'kích hoạt'));
        $this->assertSame(['Nhân viên', 500000], [$this->levelOf($member), $member->fresh()->cumulative_amount]);
        $this->assertSame(1, LevelHistory::count());
    }

    public function test_cumulative_purchases_reach_levels_at_their_thresholds(): void
    {
        // Mua 2tr rồi 1,5tr → lên Cộng tác viên ở đơn thứ 2 (đặc tả Step 7)
        $a = Member::factory()->create();
        $this->payOrder($a, 2_000_000);
        $this->assertSame('Nhân viên', $this->levelOf($a));
        $this->payOrder($a, 1_500_000)->assertSessionHas('success', fn ($m) => str_contains($m, 'Thành viên lên cấp Cộng tác viên.'));
        $this->assertSame(['Cộng tác viên', 3_500_000], [$this->levelOf($a), $a->fresh()->cumulative_amount]);

        // Đúng mốc là đạt: 2.999.999 chưa lên, thêm 1 đ (đủ 3tr) thì lên; 15tr đúng → Đại lý
        $b = Member::factory()->create();
        $this->payOrder($b, 2_999_999);
        $this->assertSame('Nhân viên', $this->levelOf($b));
        $this->payOrder($b, 1);
        $this->assertSame(['Cộng tác viên', 3_000_000], [$this->levelOf($b), $b->fresh()->cumulative_amount]);
        $this->payOrder($b, 11_999_999);
        $this->assertSame('Cộng tác viên', $this->levelOf($b)); // 14.999.999: dưới 15tr
        $this->payOrder($b, 1);
        $this->assertSame('Đại lý', $this->levelOf($b)); // đủ 15tr

        // Một đơn 16tr từ 0 → nhảy thẳng Đại lý; một vết lên cấp duy nhất
        $c = Member::factory()->create();
        $this->payOrder($c, 16_000_000)->assertSessionHas('success', fn ($m) => str_contains($m, 'lên cấp Đại lý.'));
        $this->assertSame('Đại lý', $this->levelOf($c));
        $jump = LevelHistory::where('member_id', $c->id)->sole();
        $this->assertSame(['Chưa kích hoạt', 'Đại lý', 16_000_000], [$jump->fromLevel->name, $jump->toLevel->name, $jump->cumulative_after]);

        // 150tr và 300tr: hai cấp trên cùng (tên theo cấu hình)
        [$fifth, $sixth] = Level::ordered()->pluck('name')->slice(4, 2)->values()->all();
        $this->payOrder($c, 133_999_999);
        $this->assertSame('Đại lý', $this->levelOf($c)); // 149.999.999
        $this->payOrder($c, 1);
        $this->assertSame($fifth, $this->levelOf($c));
        $this->payOrder($c, 150_000_000);
        $this->assertSame([$sixth, 300_000_000], [$this->levelOf($c), $c->fresh()->cumulative_amount]);
        $this->payOrder($c, 50_000_000);
        $this->assertSame($sixth, $this->levelOf($c)); // đã ở cấp cao nhất
    }

    public function test_levels_follow_config_not_names_and_never_go_down(): void
    {
        // Đổi tên cấp + đổi mốc trong cấu hình: engine xét theo mốc, không theo tên
        Level::where('name', 'Cộng tác viên')->update(['name' => 'Đối tác bạc', 'threshold_amount' => 5_000_000]);
        $member = Member::factory()->create();
        $this->payOrder($member, 4_000_000);
        $this->assertSame('Nhân viên', $this->levelOf($member)); // mốc mới 5tr
        $this->payOrder($member, 1_000_000);
        $this->assertSame('Đối tác bạc', $this->levelOf($member));

        // Nâng mốc lên cao hơn doanh số hiện có: không ai bị hạ cấp, đơn sau cũng không kéo xuống
        Level::where('name', 'Đối tác bạc')->update(['threshold_amount' => 9_000_000]);
        $this->payOrder($member, 100_000);
        $this->assertSame(['Đối tác bạc', 5_100_000], [$this->levelOf($member), $member->fresh()->cumulative_amount]);

        // Cấp bị tắt thì bỏ qua khi xét: 16tr mà "Đại lý" đang tắt → dừng ở cấp bật cao nhất đã đạt
        Level::where('name', 'Đại lý')->update(['active' => false]);
        $other = Member::factory()->create();
        $this->payOrder($other, 16_000_000);
        $this->assertSame('Đối tác bạc', $this->levelOf($other));
        // Bật lại: thành viên đủ điều kiện được nâng ở đơn kế tiếp
        Level::where('name', 'Đại lý')->update(['active' => true]);
        $this->payOrder($other, 10_000);
        $this->assertSame('Đại lý', $this->levelOf($other));
    }

    public function test_an_order_is_counted_exactly_once(): void
    {
        $member = Member::factory()->create();
        $order = Order::factory()->create(['member_id' => $member->id, 'goods_amount' => 3_000_000, 'shipping_fee' => 0, 'total_amount' => 3_000_000]);

        $this->confirm($order)->assertSessionHas('success');
        $this->confirm($order)->assertSessionHas('error'); // xác nhận lại đơn đã thanh toán
        $this->assertSame(3_000_000, $member->fresh()->cumulative_amount);
        // Gọi lại engine cho cùng đơn (vd webhook gửi lặp): không cộng đôi
        $again = LevelEngine::orderPaid($order->fresh());
        $this->assertFalse($again['counted']);
        $this->assertSame([3_000_000, 'Cộng tác viên', 1], [$member->fresh()->cumulative_amount, $this->levelOf($member), LevelHistory::count()]);

        // Đơn đã hủy được khôi phục thành đã thanh toán (tiền về muộn): cũng được cộng, một lần
        $late = Order::factory()->create(['member_id' => $member->id, 'goods_amount' => 500_000, 'shipping_fee' => 0, 'total_amount' => 500_000,
            'status' => Order::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_source' => Order::CANCEL_EXPIRED]);
        $this->confirm($late, ['payment_note' => 'Tiền về sau khi quá hạn'])->assertSessionHas('success');
        $this->assertSame(3_500_000, $member->fresh()->cumulative_amount);

        // Đơn chưa thanh toán / bị hủy không tính
        Order::factory()->create(['member_id' => $member->id, 'goods_amount' => 9_000_000]);
        Order::factory()->create(['member_id' => $member->id, 'goods_amount' => 9_000_000, 'status' => Order::STATUS_CANCELLED]);
        $this->assertSame(3_500_000, $member->fresh()->cumulative_amount);
    }

    public function test_manual_activation_then_purchases(): void
    {
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $member = Member::factory()->create();

        // Kích hoạt thủ công: lên "Nhân viên", doanh số vẫn 0, có vết lên cấp (không gắn đơn)
        $this->actingAs($ops, 'web')->post(route('backend.members.activate', $member), ['reason' => 'Khách đã mua tại văn phòng'])->assertSessionHas('success');
        $manual = LevelHistory::sole();
        $this->assertSame(['Nhân viên', null, 0, 'Kích hoạt thủ công'], [$manual->toLevel->name, $manual->order_id, $manual->cumulative_after, $manual->note]);
        $activatedAt = $member->fresh()->activated_at;

        // Đơn đầu sau đó: không "kích hoạt" lại, giữ ngày kích hoạt cũ, cấp không đổi cho tới khi đạt mốc
        $this->payOrder($member, 1_000_000)->assertSessionHas('success', fn ($m) => ! str_contains($m, 'kích hoạt') && ! str_contains($m, 'lên cấp'));
        $this->assertTrue($activatedAt->equalTo($member->fresh()->activated_at));
        $this->assertSame(['Nhân viên', 1_000_000, 1], [$this->levelOf($member), $member->fresh()->cumulative_amount, LevelHistory::count()]);
        $this->payOrder($member, 2_000_000);
        $this->assertSame('Cộng tác viên', $this->levelOf($member));
    }

    public function test_member_and_admin_see_level_progress(): void
    {
        $member = Member::factory()->create(['full_name' => 'Nguyễn Văn A']);

        // Chưa mua: trang tài khoản hiện các cấp theo cấu hình + lời nhắc mua đơn đầu
        $this->actingAs($member, 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('data-level-progress', false)->assertSee('Mua đơn hàng đầu tiên để kích hoạt')
            ->assertSeeInOrder(['Nhân viên', 'Có đơn đầu tiên', 'Cộng tác viên', 'Từ 3.000.000đ', 'Đại lý', 'Từ 15.000.000đ', 'Từ 150.000.000đ', 'Từ 300.000.000đ']);

        $this->payOrder($member, 2_000_000);
        $order = Order::where('member_id', $member->id)->sole();
        $this->actingAs($member->fresh(), 'member')->get('/tai-khoan')->assertOk()
            ->assertSee('Đã mua: <strong class="text-slate-700" data-member-cumulative>2.000.000đ</strong>', false)
            ->assertSee('Mua thêm <strong class="text-slate-900">1.000.000đ</strong> để lên <strong class="text-slate-900">Cộng tác viên</strong>', false)
            ->assertSee('aria-valuenow="66"', false)->assertSee('✓ Nhân viên')->assertDontSee('✓ Cộng tác viên')->assertDontSee('Mua đơn hàng đầu tiên để kích hoạt');

        // Quản trị: doanh số cộng dồn, còn thiếu bao nhiêu, lịch sử lên cấp kèm đơn
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $this->actingAs($ops, 'web')->get(route('backend.members.show', $member))->assertOk()
            ->assertSee('2.000.000 đ')->assertSee('còn 1.000.000 đ để lên Cộng tác viên')
            ->assertSeeInOrder(['Lịch sử lên cấp', 'Chưa kích hoạt → Nhân viên', $order->code, 'doanh số cộng dồn 2.000.000 đ'])
            ->assertSee("Đơn {$order->code} đã thanh toán: doanh số cộng dồn 0 → 2.000.000 đ; kích hoạt tài khoản; lên cấp Chưa kích hoạt → Nhân viên");

        // Cấp cao nhất: không còn cấp kế tiếp
        $this->payOrder($member, 300_000_000);
        $this->actingAs($member->fresh(), 'member')->get('/tai-khoan')->assertSee('Bạn đang ở cấp cao nhất')->assertSee('aria-valuenow="100"', false);
    }

    public function test_backfill_command_counts_old_paid_orders_once(): void
    {
        $member = Member::factory()->create();
        // Đơn đã thanh toán từ trước khi có engine (chưa được cộng), theo thứ tự thời gian thanh toán
        Order::factory()->status(Order::STATUS_COMPLETED)->create(['member_id' => $member->id, 'goods_amount' => 2_000_000, 'paid_at' => now()->subDays(3)]);
        Order::factory()->status(Order::STATUS_PAID)->create(['member_id' => $member->id, 'goods_amount' => 1_500_000, 'paid_at' => now()->subDay()]);
        Order::factory()->create(['member_id' => $member->id, 'goods_amount' => 9_000_000]); // chưa thanh toán: bỏ qua

        $this->artisan('levels:count-paid-orders --dry-run')->expectsOutputToContain('Sẽ tính 2 đơn.')->assertSuccessful();
        $this->assertSame(0, $member->fresh()->cumulative_amount);

        $this->artisan('levels:count-paid-orders')->expectsOutputToContain('Đã tính 2 đơn.')->assertSuccessful();
        $member->refresh();
        $this->assertSame(['Cộng tác viên', 3_500_000], [$member->level->name, $member->cumulative_amount]);
        $this->assertTrue($member->activated_at->isSameDay(now()->subDays(3))); // kích hoạt từ đơn đầu tiên
        $this->assertSame(['Nhân viên', 'Cộng tác viên'], LevelHistory::orderBy('id')->get()->map(fn ($h) => $h->toLevel->name)->all());

        $this->artisan('levels:count-paid-orders')->expectsOutputToContain('Không có đơn đã thanh toán nào chưa được tính.')->assertSuccessful();
        $this->assertSame(3_500_000, $member->fresh()->cumulative_amount);
    }
}
