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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Đồng chia — kịch bản nhiều tháng theo tài liệu đã chốt (document/dong-chia-va-thuong-nam.html): top 100 nhận đủ thì ra,
 * hàng chờ vào thay; mốc đơn; thứ tự theo lúc xác nhận thanh toán; tháng trống / quá hạn; đổi cấu hình; khóa / mở khóa;
 * và một bài chạy ngẫu nhiên nhiều tháng so với mô hình tính độc lập.
 */
class ProfitShareScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const R = WalletTransaction::WALLET_RECONSUME;

    private User $accountant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->accountant = User::factory()->create(['group_id' => Group::where('name', 'Kế toán')->value('id')]);
        $this->travelTo(Carbon::create(2026, 10, 1, 8));
    }

    /** @return list<Member> */
    private function members(string $prefix, int $count): array
    {
        return array_map(fn ($i) => Member::factory()->create(['full_name' => $prefix.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]), range(1, $count));
    }

    private function paid(Member $member, int $amount, Carbon|string $paidAt, array $extra = []): Order
    {
        return Order::factory()->status(Order::STATUS_PAID)->create([
            'member_id' => $member->id, 'goods_amount' => $amount, 'total_amount' => $amount, 'paid_amount' => $amount,
            'paid_at' => $paidAt instanceof Carbon ? $paidAt : Carbon::parse($paidAt), 'payment_reference' => 'SC'.(++$this->seq), ...$extra,
        ]);
    }

    /** Duyệt tháng $period vào ngày $day tháng sau (mặc định ngày 2), theo đúng bảng vừa tính. */
    private function approve(string $period, int $day = 2): ProfitSharePeriod
    {
        $this->travelTo(Carbon::parse($period.'-01')->addMonthNoOverflow()->setDay($day)->setTime(9, 0));
        $result = ProfitShare::approve($period, ProfitShare::calculate($period)['fingerprint'], $this->accountant);
        $this->assertInstanceOf(ProfitSharePeriod::class, $result, is_string($result) ? $result : '');

        return $result;
    }

    private function ctv(string $period): array
    {
        return collect(ProfitShare::calculate($period)['groups'])->first(fn ($g) => $g['level']->name === 'Cộng tác viên');
    }

    /** Dòng chia đã duyệt của nhóm CTV tháng $period: member_id => [rank, status, amount, received_before, exits]. */
    private function lines(string $period): array
    {
        return ProfitShareLine::whereHas('group', fn ($q) => $q->where('level_name', 'Cộng tác viên')->whereHas('period', fn ($p) => $p->where('period', $period)))
            ->get()->mapWithKeys(fn (ProfitShareLine $l) => [$l->member_id => [$l->rank, $l->status, $l->amount, $l->received_before, $l->exits]])->all();
    }

    private function ids(array $members): array
    {
        return array_map(fn (Member $m) => $m->id, $members);
    }

    // ───────────────────────── Top 100: 15 người đầu nhận đủ, hàng chờ vào thay ─────────────────────────

    public function test_first_15_reach_the_cap_and_exit_then_the_waiting_list_moves_into_the_top_100(): void
    {
        $a = $this->members('A', 15);   // vào tháng 10
        $b = $this->members('B', 100);  // vào tháng 11 → B086–B100 ngoài top
        $d = $this->members('D', 310);  // vào tháng 12 → đẩy quỹ lớn
        $e = $this->members('E', 50);   // vào tháng 1
        foreach ($a as $i => $m) {
            $this->paid($m, 3000000, Carbon::create(2026, 10, 1, 9)->addMinutes($i));
        }
        foreach ($b as $i => $m) {
            $this->paid($m, 14900000, Carbon::create(2026, 11, 2, 9)->addMinutes($i));
        }
        foreach ($d as $i => $m) {
            $this->paid($m, 14900000, Carbon::create(2026, 12, 3, 9)->addMinutes($i));
        }
        foreach ($e as $i => $m) {
            $this->paid($m, 3000000, Carbon::create(2027, 1, 4, 9)->addMinutes($i));
        }

        // ── Tháng 10: 15 người, quỹ 15 × 3tr × 95% × 5% = 2.137.500 → 142.500 / người
        $this->approve('2026-10');
        $oct = $this->lines('2026-10');
        $this->assertCount(15, $oct);
        $this->assertSame([[1, 'paid', 142500, 0, false]], array_values(array_unique(array_map(fn ($l) => [1, $l[1], $l[2], $l[3], $l[4]], $oct), SORT_REGULAR)));

        // ── Tháng 11: 115 người; top 100 = A001–A015 (vào trước) + B001–B085; B086–B100 chờ (hạng 101–115)
        $nov = $this->ctv('2026-11');
        $this->assertSame([1490000000, 70775000, 115, 100, 707750], [$nov['order_total'], $nov['pool'], $nov['member_count'], $nov['paid_count'], $nov['per_head']]);
        $this->assertSame([...$this->ids($a), ...$this->ids(array_slice($b, 0, 85))], $nov['rows']->where('status', 'paid')->pluck('member_id')->all());
        $this->assertSame($this->ids(array_slice($b, 85)), $nov['rows']->where('status', 'waiting')->pluck('member_id')->all());
        $this->assertSame(range(101, 115), $nov['rows']->where('status', 'waiting')->pluck('rank')->values()->all());
        $this->approve('2026-11');
        $this->assertSame(142500 + 707750, $a[0]->walletBalance(self::R));
        $this->assertSame(0, $b[99]->walletBalance(self::R));

        // ── Tháng 12: quỹ 310 × 14,9tr × 4,75% = 219.402.500 → 2.194.025 / người. Nhóm A chỉ còn thiếu 2.149.750 → nhận phần
        // còn thiếu, đủ 3tr và RA; B001–B085 lên 2.901.775; B086–B100 + D001–D310 vẫn chờ (hạng 101–425)
        $dec = $this->ctv('2026-12');
        $this->assertSame([219402500, 425, 100, 2194025], [$dec['pool'], $dec['member_count'], $dec['paid_count'], $dec['per_head']]);
        $rowsA = $dec['rows']->whereIn('member_id', $this->ids($a));
        $this->assertSame([2149750], $rowsA->pluck('amount')->unique()->values()->all());
        $this->assertTrue($rowsA->every(fn ($r) => $r['exits'] && $r['note'] === 'Chỉ nhận phần còn thiếu cho đủ mức'));
        $this->assertSame([2194025], $dec['rows']->whereIn('member_id', $this->ids(array_slice($b, 0, 85)))->pluck('amount')->unique()->values()->all());
        $this->assertSame(325, $dec['rows']->where('status', 'waiting')->count());
        $this->assertSame([15 * 2149750 + 85 * 2194025, 219402500 - (15 * 2149750 + 85 * 2194025)], [$dec['distributed'], $dec['remainder']]);
        $this->approve('2026-12');
        foreach ($a as $m) {
            $this->assertSame(3000000, $m->walletBalance(self::R), $m->full_name);
            $this->assertSame('2026-12', ProfitShareEntry::where('member_id', $m->id)->value('exited_period'));
        }
        $this->assertSame(707750 + 2194025, $b[0]->walletBalance(self::R));

        // ── Tháng 1: 15 người A đã RA → B086–B100 được đưa vào top (hạng 86–100); D (vào tháng 12) và E (vào tháng 1) chờ.
        // Nhóm còn 425 − 15 + 50 = 460 người. Quỹ 50 × 3tr × 4,75% = 7.125.000 → 71.250 / người; B001–B085 còn thiếu 98.225
        // nên nhận đủ 71.250
        $jan = $this->ctv('2027-01');
        $this->assertSame([7125000, 460, 100, 71250], [$jan['pool'], $jan['member_count'], $jan['paid_count'], $jan['per_head']]);
        $this->assertSame($this->ids($b), $jan['rows']->where('status', 'paid')->pluck('member_id')->all());
        $this->assertSame(range(86, 100), $jan['rows']->whereIn('member_id', $this->ids(array_slice($b, 85)))->pluck('rank')->values()->all());
        $this->assertSame([0], $jan['rows']->whereIn('member_id', $this->ids(array_slice($b, 85)))->pluck('received_before')->unique()->values()->all());
        $this->assertSame($this->ids($a), $jan['exited']->pluck('member_id')->all());
        $this->assertSame($d[0]->id, $jan['rows']->firstWhere('rank', 101)['member_id']);
        $this->assertSame($e[49]->id, $jan['rows']->firstWhere('rank', 460)['member_id']);
        $this->approve('2027-01');
        $this->assertSame(71250, $b[99]->walletBalance(self::R));
        $this->assertSame(3000000, $a[0]->walletBalance(self::R)); // đã ra: không nhận thêm
        $this->assertSame(707750 + 2194025 + 71250, $b[0]->walletBalance(self::R));

        // Tổng tiền đã cộng ví = tổng đã chia của 4 tháng; mỗi tháng cộng ví đúng bằng phần chia
        $this->assertSame((int) ProfitSharePeriod::sum('distributed_total'), (int) WalletTransaction::where('type', WalletTransaction::TYPE_PROFIT_SHARE)->sum('amount'));

        // Màn hình tháng 1: B100 hạng 100 được chia; A trong mục "đã nhận đủ từ các tháng trước"
        $this->actingAs($this->accountant, 'web')->get(route('backend.profit-share.show', '2027-01'))->assertOk()
            ->assertSee('Đã được chia — top 100 (100 người)')->assertSee('Hàng chờ — ngoài top 100, chưa được chia (360 người)')
            ->assertSee('Đã nhận đủ và ra khỏi nhóm từ các tháng trước (15 người)')->assertSee('Nhận đủ tháng 12/2026');
    }

    // ───────────────────────── Các kịch bản khác trong tài liệu ─────────────────────────

    public function test_order_bands_single_order_rule_and_one_seat_per_group(): void
    {
        [$exact, $below, $top, $agentEdge, $both, $twice] = $this->members('M', 6);
        $this->paid($exact, 3000000, '2026-10-02 08:00');        // đúng mốc → CTV
        $this->paid($below, 2999999, '2026-10-02 08:01');        // thiếu 1đ → không vào
        $this->paid($below, 2999999, '2026-10-02 08:02');        // cộng dồn ~6tr → vẫn không vào
        $this->paid($top, 14999999, '2026-10-02 08:03');         // sát mốc trên → vẫn CTV
        $this->paid($agentEdge, 15000000, '2026-10-02 08:04');   // đúng 15tr → Đại lý, không phải CTV
        $this->paid($both, 3000000, '2026-10-02 08:05');         // CTV
        $this->paid($both, 20000000, '2026-10-02 08:06');        // + Đại lý → đứng cả hai nhóm
        $first = $this->paid($twice, 5000000, '2026-10-02 08:07');
        $this->paid($twice, 6000000, '2026-10-20 08:00');        // đơn vào gói thứ hai: góp quỹ, không thêm suất

        $data = ProfitShare::calculate('2026-10');
        [$ctv, $agent] = [$data['groups'][0], $data['groups'][1]];
        $this->assertSame([$exact->id, $top->id, $both->id, $twice->id], $ctv['rows']->pluck('member_id')->all());
        $this->assertSame([$agentEdge->id, $both->id], $agent['rows']->pluck('member_id')->all());
        $this->assertSame(3000000 + 14999999 + 3000000 + 5000000 + 6000000, $ctv['order_total']);
        $this->assertSame(5, $ctv['orders']->count());
        $this->assertSame([true, true, true, true, false], $ctv['orders']->pluck('new_seat')->all());
        $this->assertSame($first->id, $ctv['rows']->firstWhere('member_id', $twice->id)['order']->id);
        $this->assertSame([3000000, 15000000], [$ctv['rows'][0]['cap'], $agent['rows'][0]['cap']]);

        // Hai nhóm tính độc lập: người ở cả hai nhóm nhận cả hai phần
        $this->approve('2026-10');
        $this->assertSame($ctv['per_head'] + $agent['per_head'], $both->walletBalance(self::R));
        $this->assertSame(2, ProfitShareEntry::where('member_id', $both->id)->count());
    }

    public function test_ranking_follows_payment_confirmation_time_not_order_time(): void
    {
        [$early, $late, $tieA, $tieB] = $this->members('R', 4);
        $this->travelTo(Carbon::create(2026, 10, 1, 8));
        $orderedFirst = $this->paid($early, 3000000, '2026-10-10 09:00');   // đặt trước nhưng kế toán xác nhận ngày 10
        $this->paid($late, 3000000, '2026-10-05 09:00');                     // đặt sau, xác nhận ngày 5 → đứng trước
        $this->assertLessThan(Order::where('member_id', $late->id)->value('id'), $orderedFirst->id);
        $t1 = $this->paid($tieA, 3000000, '2026-10-12 09:00');               // cùng giây: đơn có id nhỏ hơn đứng trước
        $t2 = $this->paid($tieB, 3000000, '2026-10-12 09:00');

        AffiliateSettings::save(['profit_share_slots' => 2]);
        $ctv = $this->ctv('2026-10');
        $this->assertSame([$late->id, $early->id, $tieA->id, $tieB->id], $ctv['rows']->pluck('member_id')->all());
        $this->assertSame(['paid', 'paid', 'waiting', 'waiting'], $ctv['rows']->pluck('status')->all());
        $this->assertLessThan($t2->id, $t1->id);
    }

    public function test_empty_month_missed_month_and_approving_out_of_order(): void
    {
        [$m] = $this->members('X', 1);
        $this->paid($m, 3000000, '2026-10-03 08:00');
        $this->approve('2026-10');
        $this->assertSame(142500, $m->walletBalance(self::R));

        // Tháng 11 không có đơn vào gói mới → quỹ 0: vẫn duyệt được, không ai được cộng
        $nov = $this->ctv('2026-11');
        $this->assertSame([0, 1, 0], [$nov['pool'], $nov['member_count'], $nov['distributed']]);
        $period = $this->approve('2026-11');
        $this->assertSame([0, 0], [$period->distributed_total, $period->paid_count]);
        $this->assertSame(142500, $m->walletBalance(self::R));
        $this->actingAs($this->accountant, 'web')->get(route('backend.profit-share.show', '2026-11'))
            ->assertSee('quỹ bằng 0 — không ai được chia')->assertSee('Không có tiền chia (quỹ tháng này bằng 0 hoặc quá nhỏ)');

        // Tháng 12 có đơn nhưng kế toán không duyệt trong 1–5/1 → quá hạn, tiền tháng 12 không chia, số đã nhận không đổi
        [$n] = $this->members('Y', 1);
        $this->paid($n, 3000000, '2026-12-10 08:00');
        $this->travelTo(Carbon::create(2027, 1, 6, 9));
        $this->assertSame('Chỉ duyệt được từ 01/01/2027 đến hết 05/01/2027.', ProfitShare::approve('2026-12', ProfitShare::calculate('2026-12')['fingerprint'], $this->accountant));
        $this->assertSame(0, $n->walletBalance(self::R));

        // Tháng 1 vẫn duyệt bình thường, người vào tháng 12 vẫn giữ chỗ (xếp sau X), đã nhận trước = 0
        $this->paid($n, 4000000, '2027-01-10 08:00');
        $jan = $this->ctv('2027-01');
        $this->assertSame([[$m->id, 142500], [$n->id, 0]], $jan['rows']->map(fn ($r) => [$r['member_id'], $r['received_before']])->all());
        $this->approve('2027-01');
        $this->assertSame([142500 + 95000, 95000], [$m->walletBalance(self::R), $n->walletBalance(self::R)]);
        $this->assertSame(['2026-10', '2026-11', '2027-01'], ProfitSharePeriod::orderBy('period')->pluck('period')->all());
    }

    public function test_config_changes_keep_existing_caps_and_invalidate_an_open_sheet(): void
    {
        [$old, $new] = $this->members('C', 2);
        $this->paid($old, 3000000, '2026-10-03 08:00');
        $this->approve('2026-10');

        // Đổi mốc Cộng tác viên 3tr → 4tr: người đã vào giữ mức ra 3tr; đơn 3,5tr mới không còn là đơn vào gói
        Level::where('name', 'Cộng tác viên')->update(['threshold_amount' => 4000000]);
        $this->paid($new, 3500000, '2026-11-03 08:00');
        $this->paid($new, 4000000, '2026-11-04 08:00');
        $nov = $this->ctv('2026-11');
        $this->assertSame([[$old->id, 3000000], [$new->id, 4000000]], $nov['rows']->map(fn ($r) => [$r['member_id'], $r['cap']])->all());
        $this->assertSame(4000000, $nov['order_total']);

        // Kế toán mở trang rồi ai đó đổi tham số → bảng đang xem không còn đúng, duyệt bị từ chối
        $this->travelTo(Carbon::create(2026, 12, 1, 9));
        $fingerprint = ProfitShare::calculate('2026-11')['fingerprint'];
        AffiliateSettings::save(['profit_share_percent' => '6']);
        $this->assertStringContainsString('Số liệu đã thay đổi', ProfitShare::approve('2026-11', $fingerprint, $this->accountant));
        $this->assertSame(228000, ProfitShare::calculate('2026-11')['pool_total']); // 4tr × 95% × 6%
        AffiliateSettings::save(['profit_share_slots' => 1]);
        $this->assertSame([1, 'waiting'], [$this->ctv('2026-11')['paid_count'], $this->ctv('2026-11')['rows'][1]['status']]);
    }

    public function test_locked_member_is_skipped_but_keeps_the_place_after_unlocking(): void
    {
        AffiliateSettings::save(['profit_share_slots' => 2]);
        [$p1, $p2, $p3] = $this->members('L', 3);
        foreach ([$p1, $p2, $p3] as $i => $m) {
            $this->paid($m, 3000000, Carbon::create(2026, 10, 2 + $i, 8));
        }
        $p1->forceFill(['status' => Member::STATUS_LOCKED])->saveQuietly();
        $oct = $this->ctv('2026-10');
        $this->assertSame([[$p2->id, 1, 'paid'], [$p3->id, 2, 'paid']], $oct['rows']->map(fn ($r) => [$r['member_id'], $r['rank'], $r['status']])->all());
        $this->assertSame([$p1->id], $oct['skipped']->pluck('member_id')->all());
        $this->approve('2026-10');
        $this->assertSame([0, 213750, 213750], [$p1->walletBalance(self::R), $p2->walletBalance(self::R), $p3->walletBalance(self::R)]);
        $this->assertSame('skipped', $this->lines('2026-10')[$p1->id][1]);

        // Mở khóa: về lại đúng chỗ theo lúc vào nhóm (đứng đầu), đẩy người thứ 3 ra hàng chờ; tháng bị khóa không được bù
        $p1->forceFill(['status' => Member::STATUS_ACTIVE])->saveQuietly();
        $this->paid($p3, 3000000, '2026-11-05 08:00');
        $nov = $this->ctv('2026-11');
        $this->assertSame([[$p1->id, 'paid', 0], [$p2->id, 'paid', 213750], [$p3->id, 'waiting', 213750]],
            $nov['rows']->map(fn ($r) => [$r['member_id'], $r['status'], $r['received_before']])->all());
    }

    // ───────────────────────── Chạy ngẫu nhiên nhiều tháng, so với mô hình tính độc lập ─────────────────────────

    public static function seeds(): array
    {
        return ['seed 20261125' => [20261125], 'seed 7' => [7], 'seed 42' => [42], 'seed 1001' => [1001], 'seed 31337' => [31337], 'seed 2027' => [2027]];
    }

    #[DataProvider('seeds')]
    public function test_randomized_months_match_an_independent_reference_model(int $seed): void
    {
        mt_srand($seed);
        AffiliateSettings::save(['profit_share_slots' => 3]);
        $members = $this->members('Z', 18);
        $bands = [[3000000, 15000000], [15000000, 150000000]]; // CTV, Đại lý (2 cấp VP không có đơn)
        $amounts = [1000000, 2500000, 2999999, 3000000, 5000000, 10000000, 14999999, 14999999, 14999999, 15000000, 20000000, 30000000];
        $seats = [[], []];      // band => member_id => [paid_ts, order_id, cap]
        $received = [[], []];   // band => member_id => đã nhận
        $paidByMember = [];     // member_id => tổng đồng chia đã cộng ví
        $months = array_map(fn ($i) => Carbon::create(2027, 1, 1)->addMonthsNoOverflow($i)->format('Y-m'), range(0, 11));

        foreach ($months as $index => $period) {
            [$start] = ProfitShare::range($period);
            $monthOrders = [];
            for ($k = 0, $n = mt_rand(2, 12); $k < $n; $k++) {
                $m = $members[mt_rand(0, count($members) - 1)];
                $o = $this->paid($m, $amounts[mt_rand(0, count($amounts) - 1)], $start->copy()->addDays(mt_rand(0, 26))->addMinutes(mt_rand(0, 600)));
                $monthOrders[] = $o;
            }
            // Đổi trạng thái khóa của một vài người trước kỳ duyệt
            foreach ([mt_rand(0, 17), mt_rand(0, 17)] as $pick) {
                if (mt_rand(0, 2) === 0) {
                    $mm = $members[$pick];
                    $mm->forceFill(['status' => $mm->fresh()->isLocked() ? Member::STATUS_ACTIVE : Member::STATUS_LOCKED])->saveQuietly();
                }
            }
            $skip = $index === 3; // tháng thứ 4 bỏ lỡ hạn duyệt

            // Mô hình: suất mới từ đơn vào gói đầu tiên (theo lúc thanh toán, rồi id)
            usort($monthOrders, fn ($x, $y) => [$x->paid_at->getTimestamp(), $x->id] <=> [$y->paid_at->getTimestamp(), $y->id]);
            $pools = [0, 0];
            foreach ($monthOrders as $o) {
                foreach ($bands as $bi => [$lo, $hi]) {
                    if ($o->goods_amount >= $lo && $o->goods_amount < $hi) {
                        $pools[$bi] += $o->goods_amount;
                        $seats[$bi][$o->member_id] ??= [$o->paid_at->getTimestamp(), $o->id, $lo];
                    }
                }
            }
            if ($skip) {
                $this->travelTo($start->copy()->addMonthNoOverflow()->setDay(6)->setTime(9, 0));
                $this->assertIsString(ProfitShare::approve($period, ProfitShare::calculate($period)['fingerprint'], $this->accountant));

                continue;
            }
            $this->travelTo($start->copy()->addMonthNoOverflow()->setDay(3)->setTime(9, 0));
            $locked = Member::whereIn('id', array_map(fn ($m) => $m->id, $members))->where('status', Member::STATUS_LOCKED)->pluck('id')->all();
            $expected = [];
            foreach ($bands as $bi => $_) {
                $pool = intdiv(intdiv($pools[$bi] * 9500, 10000) * 500, 10000);
                $order = $seats[$bi];
                uasort($order, fn ($x, $y) => [$x[0], $x[1]] <=> [$y[0], $y[1]]);
                $eligible = array_values(array_filter(array_keys($order), fn ($id) => ($received[$bi][$id] ?? 0) < $order[$id][2] && ! in_array($id, $locked, true)));
                $top = array_slice($eligible, 0, 3);
                $perHead = $top ? intdiv($pool, count($top)) : 0;
                foreach ($eligible as $rank => $id) {
                    $amount = $rank < 3 ? min($perHead, $order[$id][2] - ($received[$bi][$id] ?? 0)) : 0;
                    $expected[$bi][$id] = [$rank + 1, $rank < 3 ? 'paid' : 'waiting', $amount];
                    $received[$bi][$id] = ($received[$bi][$id] ?? 0) + $amount;
                    $paidByMember[$id] = ($paidByMember[$id] ?? 0) + $amount;
                }
            }

            $data = ProfitShare::calculate($period);
            foreach ([0, 1] as $bi) {
                $actual = $data['groups'][$bi]['rows']->mapWithKeys(fn ($r) => [$r['member_id'] => [$r['rank'], $r['status'], $r['amount']]])->all();
                $this->assertSame($expected[$bi] ?? [], $actual, "Tháng {$period}, nhóm {$bi}");
            }
            $this->assertInstanceOf(ProfitSharePeriod::class, ProfitShare::approve($period, $data['fingerprint'], $this->accountant));

            foreach ($members as $m) {
                $this->assertSame($paidByMember[$m->id] ?? 0, $m->walletBalance(self::R), "Ví {$m->full_name} sau tháng {$period}");
            }
            foreach ([0, 1] as $bi) {
                foreach ($received[$bi] as $id => $sum) {
                    $this->assertLessThanOrEqual($seats[$bi][$id][2], $sum, 'Không ai nhận quá mức ra');
                }
            }
        }

        // Số dư từng ví = tổng dòng ví; không có dòng ví đồng chia nào âm hoặc lệch chuỗi số dư
        foreach ($members as $m) {
            $run = 0;
            foreach (WalletTransaction::where('member_id', $m->id)->where('wallet', self::R)->orderBy('id')->get() as $t) {
                $run += $t->amount;
                $this->assertSame($run, $t->balance_after);
                $this->assertGreaterThan(0, $t->amount);
            }
        }
        $this->assertSame(count($months) - 1, ProfitSharePeriod::count()); // trừ tháng bỏ lỡ hạn
        $this->assertGreaterThan(0, array_sum($paidByMember));
        $this->assertTrue(DB::table('profit_share_entries')->whereNotNull('exited_period')->exists(), 'Bài chạy phải có người nhận đủ và ra khỏi nhóm');
    }
}
