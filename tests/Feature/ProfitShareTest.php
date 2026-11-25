<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\Order;
use App\Models\ProfitShareEntry;
use App\Models\ProfitShareLine;
use App\Models\ProfitSharePeriod;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ProfitShare;
use App\Support\AffiliateSettings;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Đồng chia (chốt 24/11/2026): nhóm theo cấp (mốc ≥ 3tr), vào nhóm bằng MỘT đơn đủ mốc, quỹ tháng = đơn vào gói thanh toán
 * trong tháng × 95% × 5%, chia đều cho top N người vào trước, nhận đủ mốc thì ra, kế toán duyệt ngày 1–5 tháng sau → ví
 * tái tiêu dùng.
 */
class ProfitShareTest extends TestCase
{
    use RefreshDatabase;

    private const R = WalletTransaction::WALLET_RECONSUME;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->travelTo(Carbon::create(2026, 11, 20, 10));
    }

    private function member(string $name, array $attributes = []): Member
    {
        return Member::factory()->create(['full_name' => $name, ...$attributes]);
    }

    private function paid(Member $member, int $amount, string $paidAt, string $method = Order::PAYMENT_TRANSFER): Order
    {
        return Order::factory()->status(Order::STATUS_PAID)->create([
            'member_id' => $member->id, 'goods_amount' => $amount, 'total_amount' => $amount, 'paid_at' => Carbon::parse($paidAt),
            'paid_amount' => $amount, 'payment_method' => $method,
        ]);
    }

    private function staff(string $group): User
    {
        return User::factory()->create(['group_id' => Group::where('name', $group)->value('id')]);
    }

    private function group(array $data, string $name): array
    {
        return collect($data['groups'])->first(fn ($g) => $g['level']->name === $name);
    }

    public function test_groups_pool_ranking_and_equal_split(): void
    {
        $early = $this->member('Vào Tháng Mười');
        $a = $this->member('An');
        $b = $this->member('Bình');
        $c = $this->member('Cộng Dồn');
        $d = $this->member('Đại Lý');
        $this->paid($early, 3000000, '2026-10-20 09:00');
        $this->paid($a, 3000000, '2026-11-01 08:00');
        $this->paid($b, 5000000, '2026-11-05 08:00', Order::PAYMENT_WALLET); // trả bằng ví vẫn tính
        $this->paid($c, 1500000, '2026-11-02 08:00'); // hai đơn nhỏ cộng lại 3tr: không vào gói
        $this->paid($c, 1500000, '2026-11-03 08:00');
        $this->paid($d, 15000000, '2026-11-06 08:00');
        $this->paid($a, 2999999, '2026-11-07 08:00'); // dưới mốc: không tính

        $data = ProfitShare::calculate('2026-11');
        $this->assertSame(['Cộng tác viên', 'Đại lý', 'VP tỉnh cấp 1', 'VP tỉnh cấp 2'], array_map(fn ($g) => $g['level']->name, $data['groups']));

        $ctv = $this->group($data, 'Cộng tác viên');
        // Quỹ chỉ tính đơn trong tháng 11: (3tr + 5tr) × 95% × 5% = 380.000; chia 3 người (kể cả người vào từ tháng 10)
        $this->assertSame([2, 8000000, 7600000, 380000, 3, 3, 126666, 379998, 2],
            [$ctv['orders']->count(), $ctv['order_total'], $ctv['base'], $ctv['pool'], $ctv['member_count'], $ctv['paid_count'], $ctv['per_head'], $ctv['distributed'], $ctv['remainder']]);
        $this->assertSame(['Vào Tháng Mười', 'An', 'Bình'], $ctv['rows']->map(fn ($r) => $r['member']->full_name)->all());
        $this->assertSame([1, 2, 3], $ctv['rows']->pluck('rank')->all());
        $this->assertNotContains($c->id, $ctv['rows']->pluck('member_id')->all());

        $agent = $this->group($data, 'Đại lý');
        $this->assertSame([15000000, 712500, 1, 712500], [$agent['order_total'], $agent['pool'], $agent['member_count'], $agent['per_head']]);
        $this->assertSame(15000000, $agent['rows'][0]['cap']);
        $this->assertSame([23000000, 1092500, 1092498, 4], [$data['order_total'], $data['pool_total'], $data['distributed_total'], $data['paid_count']]);

        // Tháng 10: chỉ có đơn của người vào tháng 10
        $oct = $this->group(ProfitShare::calculate('2026-10'), 'Cộng tác viên');
        $this->assertSame([142500, 1, 142500], [$oct['pool'], $oct['member_count'], $oct['per_head']]);

        // Màn hình tạm tính
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.profit-share.show', '2026-11'))->assertOk()
            ->assertSee('Đang diễn ra — tạm tính')->assertDontSee('approve-form', false)
            ->assertSeeInOrder(['Nhóm Cộng tác viên', 'Cách tính', '8.000.000 đ', '7.600.000 đ', '380.000 đ', '126.666 đ',
                'Đơn vào gói thanh toán trong tháng (2)', 'An', '01/11/2026 08:00', 'Bình', 'Ví tái tiêu dùng', '5.000.000 đ', 'Vào nhóm lần đầu',
                'Được chia — tất cả (3 người)', 'Vào Tháng Mười', '20/10/2026 09:00', '+126.666 đ', 'An', 'Bình'])
            ->assertDontSee('Cộng Dồn');
    }

    public function test_top_slots_waiting_list_locked_members_and_partial_last_payment(): void
    {
        AffiliateSettings::save(['profit_share_slots' => 2]);
        [$m1, $m2, $m3, $m4] = [$this->member('Một'), $this->member('Hai'), $this->member('Ba'), $this->member('Bốn')];
        $o1 = $this->paid($m1, 3000000, '2026-11-01 08:00');
        $this->paid($m2, 3000000, '2026-11-02 08:00');
        $this->paid($m3, 3000000, '2026-11-03 08:00');
        $this->paid($m4, 3000000, '2026-11-04 08:00');
        $m2->forceFill(['status' => Member::STATUS_LOCKED])->saveQuietly();
        // Một đã nhận gần đủ từ trước: chỉ còn thiếu 50.000
        ProfitShareEntry::create(['level_id' => Level::where('name', 'Cộng tác viên')->value('id'), 'member_id' => $m1->id, 'order_id' => $o1->id,
            'joined_at' => $o1->paid_at, 'order_amount' => 3000000, 'cap_amount' => 3000000, 'received_amount' => 2950000]);

        $ctv = $this->group(ProfitShare::calculate('2026-11'), 'Cộng tác viên');
        // Quỹ 12tr × 4,75% = 570.000; Hai bị khóa không xếp; top 2 = Một, Ba → 285.000 mỗi người; Một chỉ nhận 50.000
        $this->assertSame([570000, 3, 2, 285000], [$ctv['pool'], $ctv['member_count'], $ctv['paid_count'], $ctv['per_head']]);
        $this->assertSame([['Một', 1, ProfitShareLine::STATUS_PAID, 50000, true], ['Ba', 2, ProfitShareLine::STATUS_PAID, 285000, false], ['Bốn', 3, ProfitShareLine::STATUS_WAITING, 0, false]],
            $ctv['rows']->map(fn ($r) => [$r['member']->full_name, $r['rank'], $r['status'], $r['amount'], $r['exits']])->all());
        $this->assertSame(['Hai'], $ctv['skipped']->map(fn ($r) => $r['member']->full_name)->all());
        $this->assertSame([335000, 235000], [$ctv['distributed'], $ctv['remainder']]);

        $this->travelTo(Carbon::create(2026, 12, 2, 9));
        $html = $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.profit-share.show', '2026-11'))->assertOk()
            ->assertSee('Chờ duyệt')->assertSee('approve-form', false)
            ->assertSeeInOrder(['Được chia — top 2 (2 người)', 'Một', '+50.000 đ', 'Nhận đủ — ra khỏi nhóm', 'Chỉ nhận phần còn thiếu', 'Ba', '+285.000 đ',
                'Hàng chờ — ngoài top 2', 'Bốn', 'Ngoài top', 'Không được chia tháng này — tài khoản bị khóa', 'Hai'])
            ->getContent();
        preg_match('/name="fingerprint" value="([0-9a-f]{64})"/', $html, $m);
        $this->post(route('backend.profit-share.approve', '2026-11'), ['fingerprint' => $m[1]])->assertSessionHas('success');

        $this->assertSame([3000000, '2026-11'], [ProfitShareEntry::where('member_id', $m1->id)->value('received_amount'), ProfitShareEntry::where('member_id', $m1->id)->value('exited_period')]);
        $this->assertSame([50000, 285000, 0, 0], [$m1->walletBalance(self::R), $m3->walletBalance(self::R), $m4->walletBalance(self::R), $m2->walletBalance(self::R)]);
        // Người chờ / bị khóa cũng được lưu suất (giữ thứ tự vào nhóm)
        $this->assertSame(4, ProfitShareEntry::count());

        // Tháng 12: Một đã ra; Bốn được vào top
        $this->paid($m4, 3000000, '2026-12-03 08:00');
        $dec = $this->group(ProfitShare::calculate('2026-12'), 'Cộng tác viên');
        $this->assertSame(['Ba', 'Bốn'], $dec['rows']->map(fn ($r) => $r['member']->full_name)->all());
        $this->assertSame([285000, 0], $dec['rows']->pluck('received_before')->all());
        $this->assertSame(['Một'], $dec['exited']->map(fn ($r) => $r['member']->full_name)->all());
    }

    public function test_approval_only_from_day_1_to_5_of_next_month_and_credits_reconsume_wallet(): void
    {
        $a = $this->member('An');
        $this->paid($a, 3000000, '2026-11-10 08:00');
        $accountant = $this->staff('Kế toán');
        $fingerprint = ProfitShare::calculate('2026-11')['fingerprint'];
        $approve = fn () => $this->actingAs($accountant, 'web')->from(route('backend.profit-share.show', '2026-11'))
            ->post(route('backend.profit-share.approve', '2026-11'), ['fingerprint' => $fingerprint]);

        // Tháng chưa kết thúc
        $approve()->assertSessionHas('error', 'Chỉ duyệt được từ 01/12/2026 đến hết 05/12/2026.');
        // Quá hạn (ngày 6)
        $this->travelTo(Carbon::create(2026, 12, 6, 0, 0, 1));
        $approve()->assertSessionHas('error');
        $this->get(route('backend.profit-share.show', '2026-11'))->assertSee('Quá hạn duyệt')->assertDontSee('approve-form', false);
        $this->assertSame(0, ProfitSharePeriod::count());

        // Ngày 5, 23:59
        $this->travelTo(Carbon::create(2026, 12, 5, 23, 59));
        $approve()->assertSessionHas('success', 'Đã duyệt đồng chia tháng 11/2026: cộng 142.500 đ vào ví tái tiêu dùng cho 1 lượt người.');
        $this->assertSame([142500, 0], [$a->walletBalance(self::R), $a->walletBalance(WalletTransaction::WALLET_CASH)]);
        $tx = WalletTransaction::where('type', WalletTransaction::TYPE_PROFIT_SHARE)->sole();
        $this->assertSame(['Đồng chia tháng 11/2026 — nhóm Cộng tác viên', 142500], [$tx->note, $tx->amount]);
        $this->assertNotNull($tx->profit_share_line_id);
        $period = ProfitSharePeriod::sole();
        $this->assertSame([$accountant->id, 3000000, 142500, 142500, 1], [$period->approved_by, $period->order_total, $period->pool_total, $period->distributed_total, $period->paid_count]);

        // Bấm lại: không cộng hai lần
        $approve()->assertSessionHas('error', 'Tháng này đã được duyệt.');
        $this->assertSame(142500, $a->walletBalance(self::R));

        // Xem lại: ảnh chụp lúc duyệt, kèm số dư ví sau khi cộng
        $this->get(route('backend.profit-share.show', '2026-11'))->assertOk()
            ->assertSee('Đã duyệt lúc')->assertSee($accountant->name)->assertSee('ví TTD sau: 142.500 đ')->assertDontSee('approve-form', false)
            ->assertSeeInOrder(['Đơn vào gói thanh toán trong tháng (1)', 'An', 'Đã được chia', '+142.500 đ']);
        $this->get(route('backend.profit-share.index'))->assertOk()->assertSee('Tháng 11/2026')->assertSee('Đã duyệt')->assertSee('142.500 đ');
        // Thành viên thấy trong lịch sử ví
        $this->actingAs($a, 'member')->get(route('member.wallet.history'))->assertSee('Đồng chia')->assertSee('+142.500đ');
    }

    public function test_stale_sheet_is_refused_and_permissions(): void
    {
        $a = $this->member('An');
        $b = $this->member('Bình');
        $this->paid($a, 3000000, '2026-11-10 08:00');
        $this->paid($b, 3000000, '2026-11-11 08:00');
        $fingerprint = ProfitShare::calculate('2026-11')['fingerprint'];
        $this->travelTo(Carbon::create(2026, 12, 1, 8));

        // Sau khi kế toán mở trang, một thành viên bị khóa → bảng thay đổi → không duyệt theo bảng cũ
        $b->forceFill(['status' => Member::STATUS_LOCKED])->saveQuietly();
        $this->actingAs($this->staff('Kế toán'), 'web')->post(route('backend.profit-share.approve', '2026-11'), ['fingerprint' => $fingerprint])
            ->assertSessionHas('error', fn ($msg) => str_contains($msg, 'Số liệu đã thay đổi'));
        $this->assertSame(0, ProfitSharePeriod::count());

        $this->actingAs($this->staff('CSKH'), 'web')->get(route('backend.profit-share.index'))->assertForbidden();
        $this->actingAs($this->staff('Vận hành'), 'web')->post(route('backend.profit-share.approve', '2026-11'), ['fingerprint' => $fingerprint])->assertForbidden();
        $this->actingAs($this->staff('Kế toán'), 'web')->get(route('backend.profit-share.show', '2026-13'))->assertNotFound();
        $this->get(route('backend.profit-share.index'))->assertOk()->assertSeeInOrder(['Tháng 11/2026', 'Chờ duyệt — trong hạn']);
    }

    public function test_director_and_department_managers_can_only_view_and_only_approvers_credit_wallets(): void
    {
        $a = $this->member('An');
        $this->paid($a, 3000000, '2026-11-10 08:00');
        $accountant = $this->staff('Kế toán');
        $director = $this->staff('Giám đốc');
        $manager = $this->staff('Quản lý phòng ban');
        $this->travelTo(Carbon::create(2026, 12, 2, 9));
        $fingerprint = ProfitShare::calculate('2026-11')['fingerprint'];

        foreach ([$director, $manager] as $viewer) {
            $this->actingAs($viewer, 'web')->get(route('backend.profit-share.index'))->assertOk()->assertSee('Tháng 11/2026');
            $this->get(route('backend.profit-share.show', '2026-11'))->assertOk()
                ->assertSee('Chờ duyệt')->assertSee('+142.500 đ')->assertSee('data-view-only', false)->assertDontSee('approve-form', false)
                ->assertSeeInOrder(['Người có quyền duyệt:', $accountant->name, 'Kế toán']);
            $this->post(route('backend.profit-share.approve', '2026-11'), ['fingerprint' => $fingerprint])->assertForbidden();
        }
        $this->assertSame([0, 0], [ProfitSharePeriod::count(), $a->walletBalance(self::R)]);
        // Nhóm xem không có quyền nào khác ngoài xem đồng chia
        $this->actingAs($director, 'web')->get(route('backend.orders.index'))->assertForbidden();
        $this->get(route('backend.wallet-topups.index'))->assertForbidden();

        // Người có quyền duyệt mới cộng được ví
        $this->actingAs($accountant, 'web')->get(route('backend.profit-share.show', '2026-11'))->assertSee('approve-form', false)->assertDontSee('data-view-only', false);
        $this->post(route('backend.profit-share.approve', '2026-11'), ['fingerprint' => $fingerprint])->assertSessionHas('success');
        $this->assertSame(142500, $a->walletBalance(self::R));
        $this->actingAs($director, 'web')->get(route('backend.profit-share.show', '2026-11'))->assertSee('Đã duyệt lúc')->assertSee($accountant->name);

        // Chưa nhóm nào được tick quyền duyệt: báo rõ trên trang
        Group::where('name', 'Kế toán')->update(['permission_data' => json_encode(['backend_access', 'profit_share'])]);
        $this->get(route('backend.profit-share.show', '2026-12'))->assertSee('chưa có ai');
    }

    public function test_viewing_and_recalculating_never_writes_to_the_database(): void
    {
        $a = $this->member('An');
        $b = $this->member('Bình');
        $this->paid($a, 3000000, '2026-11-02 08:00');
        $this->paid($b, 15000000, '2026-11-03 08:00');
        $tables = ['orders', 'members', 'wallet_transactions', 'profit_share_entries', 'profit_share_periods', 'profit_share_groups',
            'profit_share_lines', 'levels', 'settings', 'audit_logs', 'commission_ledger'];
        $viewers = [$this->staff('Giám đốc'), $this->staff('Kế toán')];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy(DB::raw('1'))->get()->toJson()])->all();
        $before = $snapshot();

        // Xem nhiều lần (đang diễn ra, trong hạn duyệt, quá hạn), bởi người chỉ xem lẫn người duyệt, và đổi tham số → tính lại
        foreach ([Carbon::create(2026, 11, 25, 9), Carbon::create(2026, 12, 3, 9), Carbon::create(2026, 12, 7, 9)] as $now) {
            $this->travelTo($now);
            foreach ($viewers as $user) {
                $this->actingAs($user, 'web')->get(route('backend.profit-share.index'))->assertOk();
                $this->get(route('backend.profit-share.show', '2026-11'))->assertOk();
                $this->get(route('backend.profit-share.show', '2026-10'))->assertOk();
            }
            ProfitShare::calculate('2026-11');
        }
        $this->assertSame(array_keys($before), array_keys($snapshot()));
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, $snapshot()[$table], "Bảng {$table} bị thay đổi khi chỉ xem");
        }

        // Tính lại: sửa cấu hình (vd tỉ lệ quỹ 5% → 4%) thì tải lại trang là ra số mới ngay, không cần xóa / chạy lại gì.
        // 5%: 3tr × 95% × 5% + 15tr × 95% × 5% = 142.500 + 712.500; 4%: 114.000 + 570.000
        $this->assertSame(855000, ProfitShare::calculate('2026-11')['pool_total']);
        AffiliateSettings::save(['profit_share_percent' => '4']);
        $this->assertSame(684000, ProfitShare::calculate('2026-11')['pool_total']);
        $this->get(route('backend.profit-share.show', '2026-11'))->assertSee('684.000 đ');
    }
}
