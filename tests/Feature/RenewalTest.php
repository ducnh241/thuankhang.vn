<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Support\Renewal;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00');
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function project(string $date, array $attrs = [], ?string $ownership = 'mine'): Project
    {
        // Tên cố định, zero-pad để không tên nào là chuỗi con của tên khác (tên giả domainName() làm assertDontSee trồi sụt)
        static $n = 0;
        $project = Project::factory()->create(['name' => sprintf('DA%03d', ++$n), 'next_renewal_date' => $date, 'renewal_hosting_amount' => 1_500_000, 'renewal_domain_amount' => 450_000, ...$attrs]);
        if ($ownership) {
            $project->hostings()->attach(Hosting::factory()->create(['ownership' => $ownership]));
        }

        return $project;
    }

    public function test_defaults_to_this_week(): void
    {
        $root = $this->root();
        $week = $this->project('2026-10-08');     // tuần 05/10–11/10
        $later = $this->project('2026-10-20');    // cùng tháng, khác tuần

        $this->actingAs($root)->get('/backend/renewal')->assertOk()
            ->assertViewHas('filter', fn ($f) => $f['period'] === 'this_week')
            ->assertSee('Từ 05/10/2026 đến 11/10/2026')
            ->assertSee($week->name)->assertDontSee($later->name);
        $this->actingAs($root)->get('/backend/renewal?period=bogus')->assertViewHas('filter', fn ($f) => $f['period'] === 'this_week');
    }

    public function test_rows_are_grouped_and_tinted_by_state(): void
    {
        $root = $this->root();
        $pending = $this->project('2026-10-20');                                   // năm gia hạn kế tiếp = năm nay → chưa gia hạn
        $stale = $this->project('2024-10-21');                                     // năm cũ → chưa gia hạn
        $renewed = $this->project('2027-10-22');                                   // năm kế tiếp > năm nay → đã gia hạn
        $cancelled = $this->project('2026-10-23', ['status' => 'cancelled']);      // đã hủy gia hạn
        $closed = $this->project('2027-10-24', ['status' => 'closed']);            // đã đóng → cùng nhóm đã hủy, kể cả khi năm > năm nay
        $self = $this->project('2026-10-05', [], 'customer');                     // chỉ dùng hosting của khách → khách tự gia hạn
        $noHosting = $this->project('2026-10-26', [], null);                      // chưa gắn hosting → mình lo, chưa gia hạn

        $page = $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertOk()
            ->assertViewHas('states', fn ($s) => $s === [
                $pending->id => 'not_renewed', $stale->id => 'not_renewed', $renewed->id => 'renewed',
                $cancelled->id => 'cancelled', $closed->id => 'cancelled', $self->id => 'self_renew', $noHosting->id => 'not_renewed',
            ])
            ->assertViewHas('counts', fn ($c) => $c === ['all' => 7, 'not_renewed' => 3, 'self_renew' => 1, 'renewed' => 1, 'cancelled' => 2])
            // Tiền dự kiến chỉ tính nhóm mình còn phải gia hạn
            ->assertViewHas('expected', 3 * 1_950_000.0);
        $html = $page->getContent();

        // Nhóm của từng hàng (lớp renewal-row-<nhóm>: chỉ nhóm đã hủy có CSS — mờ đi)
        $row = fn (Project $p) => preg_match('#<tr data-renewal-row="([a-z_]+)"(?:(?!</tr>).)*?>'.$p->name.'<#s', $html, $m) ? $m[1] : null;
        $this->assertSame('not_renewed', $row($pending));
        $this->assertSame('not_renewed', $row($stale));
        $this->assertSame('renewed', $row($renewed));
        $this->assertSame('cancelled', $row($cancelled));
        $this->assertSame('cancelled', $row($closed));
        $this->assertSame('self_renew', $row($self));
        $this->assertSame('not_renewed', $row($noHosting));
        foreach (['not_renewed', 'self_renew', 'renewed', 'cancelled'] as $state) {
            $this->assertMatchesRegularExpression('#<tr data-renewal-row="'.$state.'" data-row-key="\d+" class="ant-table-row renewal-row-'.$state.'( renewal-box-end)?">#', $html);
        }

        // Bảng chia khối: mỗi nhóm 1 tiêu đề (chấm màu như tab, số dự án) đứng trước các hàng của nhóm; tiền dự kiến thu
        // ghi ở tiêu đề khối "Chưa gia hạn"
        preg_match_all('#<tr class="renewal-group" data-group="([a-z_]+)">.*?renewal-group-count">(\d+) dự án<#s', $html, $groups);
        $this->assertSame(['not_renewed', 'self_renew', 'renewed', 'cancelled'], $groups[1]);
        $this->assertSame(['3', '1', '1', '2'], $groups[2]);
        $this->assertMatchesRegularExpression('#data-group="not_renewed">(?:(?!</tr>).)*id="renewal-expected"[^>]*>Dự kiến thu <strong class="tabular-nums">5\.850\.000</strong>#s', $html);
        foreach (['not_renewed', 'self_renew', 'renewed', 'cancelled'] as $state) {
            $this->assertMatchesRegularExpression('#data-group="'.$state.'">(?:(?!<tr data-renewal-row).)*</tr>\s*<tr data-renewal-row="'.$state.'"#s', $html);
        }
        // Khối "Chưa gia hạn" đóng khung: chỉ hàng cuối của khối mang lớp vẽ cạnh dưới khung
        $this->assertSame(1, substr_count($html, 'renewal-box-end'));
        $this->assertMatchesRegularExpression('#class="ant-table-row renewal-row-not_renewed renewal-box-end">(?:(?!</tr>).)*>'.$noHosting->name.'<#s', $html);
        // Tag màu theo độ gấp chỉ ở nhóm chưa gia hạn; trạng thái dự án bình thường (đã bàn giao / bảo trì) không ghi
        $this->assertSame(0, substr_count($html, '>Đã bàn giao</span>'));

        // Tab kiêm chú thích màu; nhóm đã hủy hiện trạng thái dự án thay cho "Còn n ngày"
        $page->assertSeeInOrder(['Chưa gia hạn', '3', 'Khách tự gia hạn', '1', 'Đã gia hạn', '1', 'Đã hủy gia hạn', '2'])
            ->assertSee('Đã đóng dự án')
            // Khách tự gia hạn: không báo đỏ quá hạn, chỉ ghi số ngày
            ->assertSee('Quá 5 ngày')->assertDontSee('Quá hạn 5 ngày');
        // Dự án đã hủy / đã đóng: không có nút ghi nhận gia hạn hay hủy gia hạn
        foreach ([$cancelled, $closed, $renewed] as $p) {
            $page->assertDontSee('data-renew-open="{&quot;id&quot;:'.$p->id.',', false)->assertDontSee('data-cancel-open="{&quot;id&quot;:'.$p->id.',', false);
        }
        // Khách tự gia hạn: không ghi nhận gia hạn (không thu tiền qua mình) nhưng vẫn báo hủy được
        $page->assertDontSee('data-renew-open="{&quot;id&quot;:'.$self->id.',', false)->assertSee('data-cancel-open="{&quot;id&quot;:'.$self->id.',', false);
        $page->assertSee('data-renew-open="{&quot;id&quot;:'.$pending->id.',', false)
            ->assertSee('data-message-open="'.$pending->id.'"', false)->assertDontSee('data-message-open="'.$cancelled->id.'"', false);

        // Lọc theo từng nhóm
        $names = fn (string $url) => $this->actingAs($root)->get($url)->viewData('projects')->pluck('name')->all();
        $this->assertSame([$pending->name, $stale->name, $noHosting->name], $names('/backend/renewal?period=this_month&renewed=not_renewed'));
        $this->assertSame([$self->name], $names('/backend/renewal?period=this_month&renewed=self_renew'));
        $this->assertSame([$renewed->name], $names('/backend/renewal?period=this_month&renewed=renewed'));
        $this->assertSame([$cancelled->name, $closed->name], $names('/backend/renewal?period=this_month&renewed=cancelled'));
    }

    public function test_rows_sorted_by_group_then_days_left(): void
    {
        $root = $this->root();
        $cancelledEarly = $this->project('2026-10-01', ['status' => 'cancelled']);
        $renewedLate = $this->project('2027-10-30');
        $soon = $this->project('2026-10-12');           // còn 2 ngày
        $renewedEarly = $this->project('2027-10-02');
        $overdue = $this->project('2025-10-03');        // quá hạn 7 ngày
        $today = $this->project('2026-10-10');          // hôm nay
        $closedLate = $this->project('2026-10-28', ['status' => 'closed']);
        $later = $this->project('2026-10-25');          // còn 15 ngày
        $selfLate = $this->project('2026-10-11', [], 'customer');   // khách tự gia hạn, còn 1 ngày
        $selfEarly = $this->project('2026-10-04', [], 'customer');  // khách tự gia hạn, quá 6 ngày

        $ids = $this->actingAs($root)->get('/backend/renewal?period=this_month')->viewData('projects')->pluck('id')->all();
        $this->assertSame([
            $overdue->id, $today->id, $soon->id, $later->id,   // chưa gia hạn (hosting của mình): còn -7, 0, 2, 15 ngày
            $selfEarly->id, $selfLate->id,                    // chưa gia hạn, khách tự gia hạn: còn -6, 1 ngày
            $renewedEarly->id, $renewedLate->id,              // đã gia hạn
            $cancelledEarly->id, $closedLate->id,             // đã hủy / đã đóng
        ], $ids);
    }

    public function test_overdue_shows_every_group_and_not_renewed_tab_is_the_debt(): void
    {
        $root = $this->root();
        $late = $this->project('2026-03-01');
        $cancelled = $this->project('2026-03-02', ['status' => 'cancelled']);
        $closed = $this->project('2026-03-03', ['status' => 'closed']);
        $renewed = $this->project('2027-03-04');
        $self = $this->project('2026-03-05', [], 'customer');   // khách tự gia hạn
        $this->project('2026-10-10');                             // hôm nay: chưa qua hạn

        // "Quá hạn" = đến hạn từ 01/01 tới hôm qua: hiện đủ các nhóm để soát lại, không sót dự án nào
        $this->assertSame([$late->id, $self->id, $renewed->id, $cancelled->id, $closed->id],
            $this->actingAs($root)->get('/backend/renewal?period=overdue')->viewData('projects')->pluck('id')->all());
        // Phần thật sự còn nợ: tab "Chưa gia hạn"
        $this->assertSame([$late->id], $this->actingAs($root)->get('/backend/renewal?period=overdue&renewed=not_renewed')->viewData('projects')->pluck('id')->all());
    }

    public function test_renewed_means_next_year_greater_than_period_year(): void
    {
        $due = CarbonImmutable::parse('2026-10-20');
        $this->assertTrue(Renewal::isRenewed(Carbon::parse('2027-10-20'), $due));
        $this->assertFalse(Renewal::isRenewed(Carbon::parse('2026-10-20'), $due));
        $this->assertFalse(Renewal::isRenewed(Carbon::parse('2021-10-20'), $due));
        // 29/02: kỳ năm không nhuận rơi vào 28/02, vẫn so theo năm
        $this->assertTrue(Renewal::isRenewed(Carbon::parse('2028-02-29'), CarbonImmutable::parse('2027-02-28')));
        // Cuối tháng 12 xem kỳ tháng 1 năm sau: so với năm của kỳ (2027), không phải năm hôm nay (2026)
        $this->assertFalse(Renewal::isRenewed(Carbon::parse('2027-01-05'), CarbonImmutable::parse('2027-01-05')));
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('renewal')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $project = $this->project('2025-10-20');

        $this->actingAs($noAccess)->get('/backend/renewal?period=this_month')->assertForbidden();
        $this->actingAs($viewer)->get('/backend/renewal?period=this_month')->assertOk()
            ->assertSee($project->name)
            ->assertDontSee('data-renew-open="', false)
            ->assertDontSee('Mẫu thông báo');
        $this->actingAs($viewer)->post("/backend/renewal/{$project->id}/renew", ['hosting_amount' => '1', 'domain_amount' => '0', 'paid_at' => '2026-10-10'])->assertForbidden();
        $this->actingAs($viewer)->post("/backend/renewal/{$project->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($viewer)->get('/backend/renewal/template')->assertForbidden();
        $this->actingAs($viewer)->put('/backend/renewal/template', ['template_hosting' => 'a', 'template_full' => 'b'])->assertForbidden();
        $this->assertSame(0, Payment::count());
    }

    public function test_period_filter_compares_day_month_and_ignores_year(): void
    {
        $root = $this->root();
        $thisMonthOld = $this->project('2023-10-25');     // năm cũ nhưng tháng 10 → vẫn vào "tháng này"
        $renewed = $this->project('2027-10-02');          // đã gia hạn (năm sau)
        $nextMonth = $this->project('2026-11-05');
        $overdue = $this->project('2026-03-01');
        $week = $this->project('2026-10-11');             // 10/10/2026 là thứ Bảy → tuần 05/10–11/10

        $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertOk()
            ->assertSee($thisMonthOld->name)->assertSee($renewed->name)->assertSee($week->name)
            ->assertDontSee($nextMonth->name)->assertDontSee($overdue->name);

        $this->actingAs($root)->get('/backend/renewal?period=next_month')
            ->assertSee($nextMonth->name)->assertDontSee($thisMonthOld->name);

        $this->actingAs($root)->get('/backend/renewal?period=this_week')
            ->assertSee($week->name)->assertDontSee($thisMonthOld->name)->assertDontSee($nextMonth->name);

        // Quá hạn: hạn năm nay đã qua và chưa gia hạn (02/10/2027 đã gia hạn nên không tính)
        $this->actingAs($root)->get('/backend/renewal?period=overdue&renewed=not_renewed')
            ->assertSee($overdue->name)->assertDontSee($renewed->name)->assertDontSee($week->name);

        // Khoảng tùy chọn vắt qua năm 20/12 → 10/01
        $newYear = $this->project('2024-01-05');
        $this->actingAs($root)->get('/backend/renewal?period=custom&from=2026-12-20&to=2027-01-10')
            ->assertSee($newYear->name)->assertDontSee($thisMonthOld->name);

        // Tình trạng gia hạn
        $this->actingAs($root)->get('/backend/renewal?period=this_month&renewed=renewed')->assertSee($renewed->name)->assertDontSee($thisMonthOld->name);
        $this->actingAs($root)->get('/backend/renewal?period=this_month&renewed=not_renewed')->assertSee($thisMonthOld->name)->assertDontSee($renewed->name);

        // Tham số rác không gây lỗi
        $this->actingAs($root)->get('/backend/renewal?period=xyz&from=abc&status[]=1&hosting=1 OR 1')->assertOk();
    }

    public function test_messages_only_for_own_hosting_and_hosting_filter(): void
    {
        $root = $this->root();
        // Tên cố định: tên giả (domainName) có thể trùng chuỗi con trên trang → assertDontSee trồi sụt
        $mine = $this->project('2026-10-20', ['status' => 'maintenance', 'name' => 'Dự án hosting của mình']);
        $customerHosting = $this->project('2026-10-21', ['name' => 'Dự án hosting của khách'], 'customer');
        $noHosting = $this->project('2026-10-22', ['status' => 'closed', 'name' => 'Dự án không gắn hosting'], null);

        $page = $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertOk();
        // Chỉ dự án dùng hosting của mình mới có thông báo soạn sẵn
        $page->assertSee('data-message-open="'.$mine->id.'"', false)
            ->assertDontSee('data-message-open="'.$customerHosting->id.'"', false)
            ->assertDontSee('data-message-open="'.$noHosting->id.'"', false);
        $page->assertSee('1.950.000');
        // Không còn ô lọc trạng thái: tham số cũ trên URL bị bỏ qua; giá trị lọc hosting lạ cũng bỏ qua
        $page->assertDontSee('name="status"', false)->assertSee('data-filter="hosting"', false);
        foreach (['hosting=none', 'hosting[]=mine', 'status=closed', 'status=not_cancelled'] as $old) {
            $this->assertSame(3, $this->actingAs($root)->get('/backend/renewal?period=this_month&'.$old)->viewData('projects')->count(), $old);
        }
        // Lọc hosting: của mình = có hosting của mình; của khách = chỉ chạy trên hosting của khách; chưa gắn hosting chỉ hiện
        // khi không lọc. Số trên tab và tiền dự kiến theo bộ lọc
        $mixed = $this->project('2026-10-23', ['name' => 'Dự án hosting cả hai']);
        $mixed->hostings()->attach(Hosting::factory()->create(['ownership' => 'customer']));
        $ids = fn (string $q) => $this->actingAs($root)->get('/backend/renewal?period=this_month&'.$q)->viewData('projects')->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$mine->id, $mixed->id])->sort()->values()->all(), $ids('hosting=mine'));
        $this->assertSame([$customerHosting->id], $ids('hosting=customer'));
        $customerPage = $this->actingAs($root)->get('/backend/renewal?period=this_month&hosting=customer')
            ->assertSee('Hosting: Của khách')
            ->assertViewHas('counts', fn ($c) => $c === ['all' => 1, 'not_renewed' => 0, 'self_renew' => 1, 'renewed' => 0, 'cancelled' => 0])
            ->assertViewHas('expected', 0.0);
        $customerPage->assertSee('href="'.e(route('backend.renewals.index', ['period' => 'this_month'])).'" class="filter-pill-clear"', false);
        $this->actingAs($root)->get('/backend/renewal?period=this_month&hosting=mine')->assertViewHas('expected', 2 * 1_950_000.0);
    }

    public function test_renew_creates_renewal_payment_and_moves_date(): void
    {
        $root = $this->root();
        $project = $this->project('2025-10-20', ['price' => 5_000_000]);
        $from = route('backend.renewals.index', ['period' => 'this_month']);

        // Trang đưa đúng kỳ gia hạn 20/10/2026 vào nút gia hạn
        $this->actingAs($root)->get($from)->assertSee('&quot;due&quot;:&quot;2026-10-20&quot;', false)->assertSee('Đang lưu: 20/10/2025');

        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/renew", [
            'due' => '2026-10-20', 'hosting_amount' => '1.500.000', 'domain_amount' => '450.000', 'paid_at' => '2026-10-09', 'method' => 'bank_transfer', 'note' => '',
        ])->assertRedirect($from)->assertSessionHasNoErrors()->assertSessionHas('success');

        $payment = Payment::sole();
        $this->assertSame('renewal', $payment->type);
        $this->assertSame('1950000.00', $payment->amount);
        $this->assertSame('Gia hạn năm 2026 [Hosting: 1.500.000 + Domain: 450.000]', $payment->note);
        // Ngày lưu năm cũ 2025: trả cho kỳ 20/10/2026 → kế tiếp 20/10/2027 và dòng chuyển sang "Đã gia hạn" (main để 2026 → vẫn báo chưa gia hạn)
        $this->assertSame('2027-10-20', $project->fresh()->next_renewal_date->toDateString());
        $this->actingAs($root)->get($from.'&renewed=renewed')->assertSee($project->name)->assertDontSee('data-renew-open="', false);

        // Bấm lại / tab cũ gửi lại cùng kỳ → không tạo thêm khoản thu
        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/renew", ['due' => '2026-10-20', 'hosting_amount' => '1.500.000', 'domain_amount' => '0', 'paid_at' => '2026-10-10'])
            ->assertSessionHasErrorsIn('renew', 'due');
        $this->assertSame(1, Payment::count());
        $this->assertSame('2027-10-20', $project->fresh()->next_renewal_date->toDateString());

        // Ghi chú tự nhập + chỉ hosting
        $other = $this->project('2026-10-25');
        $this->actingAs($root)->post("/backend/renewal/{$other->id}/renew", ['due' => '2026-10-25', 'hosting_amount' => '1.500.000', 'domain_amount' => '0', 'paid_at' => '2026-10-10', 'note' => 'Khách CK'])->assertSessionHasNoErrors();
        $this->assertSame('Khách CK [Hosting: 1.500.000]', Payment::latest('id')->first()->note);
        $this->assertSame('2027-10-25', $other->fresh()->next_renewal_date->toDateString());
    }

    public function test_renew_overdue_and_stale_dates(): void
    {
        $root = $this->root();
        // Quá hạn từ tháng 2, ngày lưu từ 2019
        $stale = $this->project('2019-02-28');
        $this->actingAs($root)->get('/backend/renewal?period=overdue')->assertSee($stale->name)->assertSee('Quá hạn 224 ngày');
        $this->actingAs($root)->post("/backend/renewal/{$stale->id}/renew", ['due' => '2026-02-28', 'hosting_amount' => '100', 'domain_amount' => '0', 'paid_at' => '2026-10-10'])->assertSessionHasNoErrors();
        $this->assertSame('2027-02-28', $stale->fresh()->next_renewal_date->toDateString());
        $this->actingAs($root)->get('/backend/renewal?period=overdue'); // đọc thông báo flash
        $this->actingAs($root)->get('/backend/renewal?period=overdue&renewed=not_renewed')->assertDontSee($stale->name);

        // 29/02: kỳ năm không nhuận là 28/02, năm nhuận kế tiếp giữ 29/02
        $leap = $this->project('2024-02-29');
        $this->actingAs($root)->post("/backend/renewal/{$leap->id}/renew", ['due' => '2027-02-28', 'hosting_amount' => '100', 'domain_amount' => '0', 'paid_at' => '2026-10-10'])->assertSessionHasNoErrors();
        $this->assertSame('2028-02-29', $leap->fresh()->next_renewal_date->toDateString());
    }

    public function test_year_boundary(): void
    {
        $root = $this->root();
        Carbon::setTestNow('2026-12-15 09:00:00');
        $january = $this->project('2027-01-15');      // hạn tháng 1 năm sau, CHƯA gia hạn
        $prepaid = $this->project('2028-01-20');      // đã gia hạn trước cho kỳ 01/2027
        $leap = $this->project('2024-02-29');

        // Main báo nhầm "đã gia hạn" vì năm 2027 > 2026
        $this->actingAs($root)->get('/backend/renewal?period=next_month&renewed=not_renewed')
            ->assertSee($january->name)->assertDontSee($prepaid->name)->assertSee('Còn 31 ngày');
        $this->actingAs($root)->get('/backend/renewal?period=next_month&renewed=renewed')
            ->assertSee($prepaid->name)->assertDontSee($january->name);

        $this->actingAs($root)->post("/backend/renewal/{$january->id}/renew", ['due' => '2027-01-15', 'hosting_amount' => '100', 'domain_amount' => '0', 'paid_at' => '2026-12-15'])->assertSessionHasNoErrors();
        $this->assertSame('2028-01-15', $january->fresh()->next_renewal_date->toDateString());

        // Tháng 2/2027 (không nhuận): hạn 29/02 vẫn phải hiện
        Carbon::setTestNow('2027-02-10 09:00:00');
        $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertSee($leap->name)->assertSee('28/02/2027');

        // Khoảng tùy chọn vắt qua năm: sắp theo ngày thực (12/2026 trước 01/2027)
        Carbon::setTestNow('2026-12-15 09:00:00');
        $december = $this->project('2020-12-28');
        $this->actingAs($root)->get('/backend/renewal?period=custom&from=2026-12-20&to=2027-01-31')
            ->assertSeeInOrder([$december->name, $january->name]);
    }

    public function test_renew_validation(): void
    {
        $root = $this->root();
        $project = $this->project('2026-10-20');
        $from = route('backend.renewals.index');
        $ok = ['due' => '2026-10-20', 'hosting_amount' => '1', 'domain_amount' => '0', 'paid_at' => '2026-10-10', 'project_id' => $project->id];

        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'hosting_amount' => '0'])
            ->assertRedirect($from)->assertSessionHasErrorsIn('renew', 'hosting_amount');
        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/renew", ['hosting_amount' => 'abc', 'domain_amount' => '-5', 'paid_at' => 'xx'])
            ->assertSessionHasErrorsIn('renew', ['hosting_amount', 'domain_amount', 'paid_at', 'due']);
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'method' => 'bitcoin'])->assertSessionHasErrorsIn('renew', 'method');
        // Kỳ không khớp ngày–tháng của dự án / quá xa hôm nay / sai định dạng
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'due' => '2026-10-21'])->assertSessionHasErrorsIn('renew', 'due');
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'due' => '2029-10-20'])->assertSessionHasErrorsIn('renew', 'due');
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'due' => '20/10/2026'])->assertSessionHasErrorsIn('renew', 'due');
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'due' => ['x']])->assertSessionHasErrorsIn('renew', 'due');
        $this->assertSame(0, Payment::count());
        $this->assertSame('2026-10-20', $project->fresh()->next_renewal_date->toDateString());

        // Trang mở lại hộp thoại kèm lỗi
        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/renew", [...$ok, 'hosting_amount' => '0']);
        $this->actingAs($root)->get($from)->assertSee('Tổng tiền gia hạn phải lớn hơn 0')->assertSee('id="renew-modal" data-url="'.route('backend.renewals.renew', '__ID__').'"', false)->assertSee('data-reopen="'.$project->id.'"', false);

        // Dự án đã xóa → 404
        $project->delete();
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/renew", $ok)->assertNotFound();
        $this->actingAs($root)->post("/backend/renewal/{$project->id}/cancel", ['reason' => 'x'])->assertNotFound();
    }

    public function test_cancel_renewal(): void
    {
        $root = $this->root();
        $project = $this->project('2026-10-20', ['note' => 'Ghi chú cũ']);

        $this->actingAs($root)->post("/backend/renewal/{$project->id}/cancel", ['reason' => ''])->assertSessionHasErrorsIn('cancel', 'reason');
        $this->assertSame('delivered', $project->fresh()->status);

        $this->actingAs($root)->post("/backend/renewal/{$project->id}/cancel", ['reason' => 'Khách chuyển bên khác'])->assertSessionHasNoErrors();
        $project->refresh();
        $this->assertSame('cancelled', $project->status);
        // Giữ ngày gia hạn: dự án vẫn hiện ở nhóm "Đã hủy gia hạn" đúng kỳ để soát lại
        $this->assertSame('2026-10-20', $project->next_renewal_date->toDateString());
        $this->assertSame("Ghi chú cũ\n[Khách hủy gia hạn ngày 10/10/2026] Lý do: Khách chuyển bên khác", $project->note);

        $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertSee('hủy gia hạn'); // đọc flash
        $page = $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertSee($project->name);
        $this->assertSame('cancelled', $page->viewData('states')[$project->id]);
        $this->assertSame(0.0, $page->viewData('expected'));
        $this->assertSame([$project->id], $this->actingAs($root)->get('/backend/renewal?period=this_month&renewed=cancelled')->viewData('projects')->pluck('id')->all());
    }

    public function test_template_edit_and_render(): void
    {
        $root = $this->root();
        $project = $this->project('2026-10-20');
        $project->customer->update(['name' => 'Anh <Ba>']);

        // Chưa lưu → dùng mẫu mặc định
        $this->actingAs($root)->get('/backend/renewal/template')->assertOk()->assertSee('{customer_name}');

        $this->actingAs($root)->put('/backend/renewal/template', ['template_hosting' => '', 'template_full' => 'x'])->assertSessionHasErrors('template_hosting');
        // Ô xóa trắng rồi lưu lỗi: vẫn trống (không hiện lại mẫu cũ ngay trên dòng báo lỗi)
        $this->actingAs($root)->from('/backend/renewal/template')->followingRedirects()
            ->put('/backend/renewal/template', ['template_hosting' => '', 'template_full' => 'x'])
            ->assertSee('data-template aria-label="Thông báo gia hạn hosting"></textarea>', false);
        $this->actingAs($root)->put('/backend/renewal/template', [
            'template_hosting' => 'Chào {customer_name}, phí {hosting_amount} {currency} hạn {next_renewal_date}',
            'template_full' => 'Tổng {total_amount}',
        ])->assertRedirect(route('backend.renewals.template'));
        $this->assertSame('Tổng {total_amount}', Setting::get(Renewal::KEY_TEMPLATE_FULL));

        $this->assertSame('Chào Anh <Ba>, phí 1.500.000 VND hạn 20/10/2026', Renewal::render(Renewal::templates()['hosting'], $project->fresh('customer')));

        // Tên khách có HTML được escape trong JSON nhúng trang
        $this->actingAs($root)->get('/backend/renewal?period=this_month')->assertOk()->assertDontSee('Anh <Ba>', false)
            ->assertViewHas('messages', fn ($messages) => $messages[$project->id]['full'] === 'Tổng 1.950.000');
    }

    public function test_helpers(): void
    {
        $today = CarbonImmutable::parse('2026-10-10');
        $due = Renewal::occurrence(Carbon::parse('2019-10-20'), $today->startOfMonth(), $today->endOfMonth(), $today);
        $this->assertSame('2026-10-20', $due->toDateString());
        $this->assertSame(10, Renewal::daysLeft($due, $today));
        $this->assertFalse(Renewal::isRenewed(Carbon::parse('2026-10-20'), $due));
        $this->assertTrue(Renewal::isRenewed(Carbon::parse('2027-10-20'), $due));
        $this->assertTrue(Renewal::inRange(Carbon::parse('2020-01-02'), CarbonImmutable::parse('2026-12-27'), CarbonImmutable::parse('2027-01-03')));
        $this->assertFalse(Renewal::inRange(Carbon::parse('2020-06-02'), CarbonImmutable::parse('2026-12-27'), CarbonImmutable::parse('2027-01-03')));
        $this->assertTrue(Renewal::inRange(Carbon::parse('2020-06-02'), CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2027-01-01')));
        // Khoảng dài hơn 1 năm: lấy kỳ gần hôm nay nhất
        $this->assertSame('2026-06-02', Renewal::occurrence(Carbon::parse('2020-06-02'), CarbonImmutable::parse('2025-01-01'), CarbonImmutable::parse('2027-12-31'), $today)->toDateString());
    }

    public function test_dialogs_reopen_with_entered_values_after_errors(): void
    {
        $root = $this->root();
        $project = $this->project('2026-10-20');
        $from = '/backend/renewal?period=this_month';

        // Ngày thu để trống → hộp ghi nhận mở lại đúng dự án, ô ngày thu TRỐNG (không tự điền hôm nay), giữ ghi chú / phương thức
        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/renew", ['project_id' => $project->id, 'due' => '2026-10-20', 'hosting_amount' => '1.000.000', 'domain_amount' => '0', 'paid_at' => '', 'method' => 'cash', 'note' => 'Ghi chú cũ']);
        $html = $this->actingAs($root)->get($from)->getContent();
        $this->assertStringContainsString('data-reopen="'.$project->id.'"', $html);
        $this->assertStringContainsString('<input id="renew_paid_at" name="paid_at" value=""', $html);
        $this->assertMatchesRegularExpression('#<option value="cash"\s+selected#', $html);
        $this->assertStringContainsString('>Ghi chú cũ</textarea>', $html);

        // Lý do hủy trống → hộp hủy mở lại đúng dự án, lỗi dưới ô lý do
        $this->actingAs($root)->from($from)->post("/backend/renewal/{$project->id}/cancel", ['project_id' => $project->id, 'reason' => '']);
        $html = $this->actingAs($root)->get($from)->getContent();
        $this->assertMatchesRegularExpression('#id="cancel-modal" data-url="[^"]+"\s+data-reopen="'.$project->id.'"#', $html);
        $this->assertMatchesRegularExpression('#name="reason"(?:(?!</form>).)*?role="alert"#s', $html);
    }
}
