<?php

namespace Tests\Feature;

use App\Http\Controllers\Backend\RenewalController;
use App\Models\Customer;
use App\Models\Group;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Rà các trường hợp biên của màn Nhắc gia hạn (/backend/renewal): kỳ xem, kỳ tùy chọn, nhóm / tab / số đếm,
 * tiền dự kiến, các chốt chặn ghi nhận / hủy gia hạn, hiển thị.
 */
class RenewalScenarioTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 09:00:00'); // thứ Bảy
        $this->root = User::factory()->superAdmin()->create();
    }

    private function project(string $date, array $attrs = [], ?string $ownership = 'mine'): Project
    {
        static $n = 0;
        $project = Project::factory()->create(['name' => sprintf('KB%03d', ++$n), 'next_renewal_date' => $date, 'renewal_hosting_amount' => 1_000_000, 'renewal_domain_amount' => 200_000, ...$attrs]);
        if ($ownership) {
            $project->hostings()->attach(Hosting::factory()->create(['ownership' => $ownership]));
        }

        return $project;
    }

    private function page(string $query = ''): TestResponse
    {
        return $this->actingAs($this->root)->get('/backend/renewal'.($query !== '' ? '?'.$query : ''))->assertOk();
    }

    private function ids(string $query): array
    {
        return $this->page($query)->viewData('projects')->pluck('id')->all();
    }

    private function range(TestResponse $page): array
    {
        return [$page->viewData('from')->toDateString(), $page->viewData('to')->toDateString()];
    }

    // ---- Kỳ xem ----

    public function test_period_ranges_and_header(): void
    {
        $this->assertSame(['2026-10-05', '2026-10-11'], $this->range($this->page()->assertSee('Từ 05/10/2026 đến 11/10/2026')));
        $this->assertSame(['2026-10-01', '2026-10-31'], $this->range($this->page('period=this_month')));
        $this->assertSame(['2026-11-01', '2026-11-30'], $this->range($this->page('period=next_month')));
        $this->assertSame(['2026-01-01', '2026-10-09'], $this->range($this->page('period=overdue')->assertSee('Đã qua hạn từ 01/01/2026 đến 09/10/2026')));

        // Thứ Hai / Chủ nhật: tuần luôn từ thứ Hai tới Chủ nhật, có hôm nay
        Carbon::setTestNow('2026-10-12 08:00:00');
        $this->assertSame(['2026-10-12', '2026-10-18'], $this->range($this->page()));
        Carbon::setTestNow('2026-10-18 23:00:00');
        $this->assertSame(['2026-10-12', '2026-10-18'], $this->range($this->page()));

        // Tháng 12 → "tháng tới" là tháng 1 năm sau; 31/01 → tháng 2 (không tràn sang tháng 3)
        Carbon::setTestNow('2026-12-20 08:00:00');
        $this->assertSame(['2027-01-01', '2027-01-31'], $this->range($this->page('period=next_month')));
        Carbon::setTestNow('2027-01-31 08:00:00');
        $this->assertSame(['2027-02-01', '2027-02-28'], $this->range($this->page('period=next_month')));
        Carbon::setTestNow('2028-02-10 08:00:00');
        $this->assertSame(['2028-02-01', '2028-02-29'], $this->range($this->page('period=this_month')));
    }

    public function test_overdue_on_january_first_is_empty(): void
    {
        $this->project('2026-12-31');
        $this->project('2027-01-01');
        Carbon::setTestNow('2027-01-01 08:00:00');

        $this->page('period=overdue')->assertViewHas('projects', fn ($p) => $p->isEmpty())
            ->assertSee('Năm 2027 chưa có kỳ nào qua hạn')->assertSee('Không có dự án nào cần gia hạn');
    }

    public function test_today_follows_vietnam_time(): void
    {
        // 18:00 UTC ngày 10/10 = 01:00 ngày 11/10 giờ Việt Nam
        Carbon::setTestNow(Carbon::parse('2026-10-10 18:00:00', 'UTC'));
        $due = $this->project('2026-10-11');

        $this->page()->assertSee('Hết hạn hôm nay')->assertSee($due->name);
        $this->page('period=overdue')->assertDontSee($due->name);  // hôm nay chưa tính là quá hạn
    }

    // ---- Kỳ tùy chọn ----

    public function test_custom_range_normalization(): void
    {
        // Đảo ngược thì đổi chỗ
        $this->assertSame(['2026-10-01', '2026-10-20'], $this->range($this->page('period=custom&from=2026-10-20&to=2026-10-01')));
        // Thiếu một đầu: lấy đầu / cuối tháng này
        $this->assertSame(['2026-10-15', '2026-10-31'], $this->range($this->page('period=custom&from=2026-10-15')));
        $this->assertSame(['2026-10-01', '2026-10-15'], $this->range($this->page('period=custom&to=2026-10-15')));
        // Ngày không có thật / sai định dạng / mảng → bỏ qua (không bị PHP đổi 30/02 thành 02/03)
        foreach (['from=2026-02-30', 'from=2026-13-01', 'from=abc', 'from[]=2026-10-01', 'from=10/10/2026', 'from=2026-1-5'] as $bad) {
            $this->assertSame(['2026-10-01', '2026-10-31'], $this->range($this->page('period=custom&'.$bad)), $bad);
        }
        // Tối đa 1 năm: khoảng dài bị cắt, trang vẫn nhanh
        $this->assertSame(['2026-01-01', '2027-01-01'], $this->range($this->page('period=custom&from=2026-01-01&to=2030-12-31')));
        $started = microtime(true);
        $this->project('2026-05-05');
        $this->assertSame(['0001-01-01', '0002-01-01'], $this->range($this->page('period=custom&from=0001-01-01&to=9999-12-31')));
        $this->assertLessThan(5, microtime(true) - $started);

        // Nút chọn kỳ hiện tên kỳ, khoảng ngày ghi ở chân bảng; không còn lịch chọn khoảng ngày (kỳ tùy chọn chỉ vào qua link)
        $this->page('period=custom&from=2026-10-01&to=2026-10-20')->assertSee('Kỳ: Tùy chọn')->assertSee('Từ 01/10/2026 đến 20/10/2026')
            ->assertDontSee('data-picker="range"', false);
        $this->page('period=this_month')->assertSee('Kỳ: Tháng này')->assertSee('Từ 01/10/2026 đến 31/10/2026');
    }

    // ---- Nhóm, tab, số đếm ----

    public function test_tab_counts_add_up_and_match_rows(): void
    {
        $this->project('2026-10-01');                                   // chưa gia hạn, quá hạn
        $this->project('2026-10-25', ['status' => 'lead']);             // các trạng thái còn gia hạn đều tính
        $this->project('2026-10-26', ['status' => 'in_progress']);
        $this->project('2026-10-27', [], null);                         // chưa gắn hosting → mình lo
        $mixed = $this->project('2026-10-28');                          // hosting của mình + của khách → mình lo
        $mixed->hostings()->attach(Hosting::factory()->create(['ownership' => 'customer']));
        $this->project('2026-10-02', [], 'customer');                   // khách tự gia hạn
        $this->project('2027-10-03');                                   // đã gia hạn
        $this->project('2027-10-04', [], 'customer');                   // đã gia hạn (hosting khách): vẫn là đã gia hạn
        $this->project('2026-10-05', ['status' => 'cancelled']);
        $this->project('2026-10-06', ['status' => 'closed'], 'customer');
        $this->project('2026-10-07')->delete();                         // đã xóa → không hiện
        Project::factory()->create(['next_renewal_date' => null, 'deliver_date' => null]); // không có ngày nào → không xếp được vào kỳ
        $this->project('2026-11-07');                                   // ngoài kỳ

        $page = $this->page('period=this_month');
        $counts = $page->viewData('counts');
        $this->assertSame(['all' => 10, 'not_renewed' => 5, 'self_renew' => 1, 'renewed' => 2, 'cancelled' => 2], $counts);
        $this->assertSame($counts['all'], array_sum(array_diff_key($counts, ['all' => 0])));
        $this->assertSame(1, $page->viewData('states')[$mixed->id] === 'not_renewed' ? 1 : 0);

        foreach (RenewalController::STATES as $state) {
            $tab = $this->page('period=this_month&renewed='.$state);
            $this->assertCount($counts[$state], $tab->viewData('projects'), $state);
            // Tab đang chọn vẫn thấy số của mọi tab (đếm theo cả kỳ, không theo tab)
            $this->assertSame($counts, $tab->viewData('counts'));
            $tab->assertSee('role="tab" aria-selected="true" class="product-view is-active"', false);
        }
        // Tham số lạ → Tất cả
        $this->assertCount(10, $this->page('period=this_month&renewed=xyz')->viewData('projects'));
        $this->assertCount(10, $this->page('period=this_month&renewed[]=renewed')->viewData('projects'));
    }

    public function test_links_keep_tab_and_period(): void
    {
        $page = $this->page('period=custom&from=2026-10-01&to=2026-10-20&renewed=renewed');
        $html = html_entity_decode($page->getContent());
        // Tab giữ kỳ tùy chọn
        $this->assertStringContainsString('/backend/renewal?period=custom&from=2026-10-01&to=2026-10-20&renewed=not_renewed"', $html);
        $this->assertStringContainsString('/backend/renewal?period=custom&from=2026-10-01&to=2026-10-20"', $html); // Tất cả
        // Menu kỳ: 4 kỳ, giữ tab, bỏ from/to, không có "Tùy chọn" (tuần này là kỳ mặc định nên không ghi period)
        $this->assertStringContainsString('/backend/renewal?renewed=renewed"', $html);
        foreach (['this_month', 'next_month', 'overdue'] as $period) {
            $this->assertStringContainsString('/backend/renewal?period='.$period.'&renewed=renewed"', $html);
        }
        preg_match('#<template id="filter-period">(.*?)</template>#s', $html, $menuHtml);
        $this->assertStringNotContainsString('period=custom', $menuHtml[1]);
        // Lọc hosting: giữ kỳ (cả khoảng ngày tùy chọn) và tab
        $this->assertStringContainsString('/backend/renewal?period=custom&from=2026-10-01&to=2026-10-20&renewed=renewed&hosting=mine"', $html);
        $this->assertStringContainsString('/backend/renewal?period=custom&from=2026-10-01&to=2026-10-20&renewed=renewed&hosting=customer"', $html);

        // Mục đang chọn được đánh dấu
        $menu = $this->page('period=next_month')->getContent();
        $this->assertMatchesRegularExpression('#<li class="ant-dropdown-menu-item ant-dropdown-menu-item-selected" role="menuitem"><a href="[^"]*period=next_month"#', $menu);
        $this->assertSame(1, substr_count($menu, 'ant-dropdown-menu-item-selected'));
    }

    // ---- Tiền dự kiến ----

    public function test_expected_amount_follows_group_and_tab(): void
    {
        $this->project('2026-10-20');                                       // 1.200.000
        $this->project('2026-10-21', ['renewal_domain_amount' => 0]);       // 1.000.000
        $this->project('2026-10-22', [], 'customer');                       // khách tự gia hạn → không tính
        $this->project('2027-10-23');                                       // đã gia hạn → không tính
        $this->project('2026-10-24', ['status' => 'cancelled']);            // đã hủy → không tính

        $this->page('period=this_month')->assertViewHas('expected', 2_200_000.0)->assertSee('5 dự án')->assertSee('2.200.000');
        $this->page('period=this_month&renewed=not_renewed')->assertViewHas('expected', 2_200_000.0)->assertSee('2 dự án');
        foreach (['self_renew', 'renewed', 'cancelled'] as $state) {
            $this->page('period=this_month&renewed='.$state)->assertViewHas('expected', 0.0);
        }
    }

    // ---- Ghi nhận gia hạn: chốt chặn ----

    public function test_renew_is_refused_where_the_button_is_hidden(): void
    {
        $from = '/backend/renewal?period=this_month';
        $payload = fn (string $due) => ['due' => $due, 'hosting_amount' => '1.000.000', 'domain_amount' => '0', 'paid_at' => '2026-10-10'];
        $cases = [
            'cancelled' => [$this->project('2026-10-20', ['status' => 'cancelled']), 'Đã hủy gia hạn'],
            'closed' => [$this->project('2026-10-21', ['status' => 'closed']), 'Đã đóng dự án'],
            'self_renew' => [$this->project('2026-10-22', [], 'customer'), 'khách tự gia hạn'],
        ];
        foreach ($cases as $label => [$project, $text]) {
            $this->actingAs($this->root)->from($from)->post("/backend/renewal/{$project->id}/renew", $payload($project->next_renewal_date->toDateString()))
                ->assertRedirect($from)->assertSessionHas('error', fn ($m) => str_contains($m, $text))->assertSessionHasNoErrors();
            $this->assertSame($project->next_renewal_date->toDateString(), $project->fresh()->next_renewal_date->toDateString(), $label);
        }
        $noDate = Project::factory()->create(['next_renewal_date' => null, 'deliver_date' => null]);
        $noDate->hostings()->attach(Hosting::factory()->create(['ownership' => 'mine']));
        $this->actingAs($this->root)->from($from)->post("/backend/renewal/{$noDate->id}/renew", $payload('2026-10-20'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'chưa có ngày gia hạn lẫn ngày bàn giao'));
        $this->assertNull($noDate->fresh()->next_renewal_date);
        $this->assertSame(0, Payment::count());

        // Thông báo lỗi hiện ở đầu trang (hộp thoại không mở lại vì không có dữ liệu cũ)
        $this->actingAs($this->root)->from($from)->post("/backend/renewal/{$cases['cancelled'][0]->id}/renew", $payload('2026-10-20'));
        $this->actingAs($this->root)->get($from)->assertSee('Không ghi nhận gia hạn');
    }

    public function test_renew_paid_at_bounds(): void
    {
        $project = $this->project('2026-10-20');
        $post = fn (string $paidAt) => $this->actingAs($this->root)->post("/backend/renewal/{$project->id}/renew", ['due' => '2026-10-20', 'hosting_amount' => '1000', 'domain_amount' => '0', 'paid_at' => $paidAt]);

        $post('2027-10-11')->assertSessionHasErrorsIn('renew', 'paid_at');   // quá 1 năm tới
        $post('1900-01-01')->assertSessionHasErrorsIn('renew', 'paid_at');
        $post('2026-02-30')->assertSessionHasErrorsIn('renew', 'paid_at');
        $this->assertSame(0, Payment::count());
        $post('2027-10-10')->assertSessionHasNoErrors();                      // đúng 1 năm: được (như form khoản thu)
        $this->assertSame('2027-10-10', Payment::sole()->paid_at->toDateString());
        $this->assertSame('2027-10-20', $project->fresh()->next_renewal_date->toDateString());
    }

    // ---- Hủy gia hạn: chốt chặn ----

    public function test_cancel_is_refused_for_cancelled_or_closed_and_allowed_for_self_renew(): void
    {
        $closed = $this->project('2026-10-20', ['status' => 'closed', 'note' => 'Ghi chú cũ']);
        $cancelled = $this->project('2026-10-21', ['status' => 'cancelled', 'note' => 'Đã hủy từ trước']);
        $self = $this->project('2026-10-22', [], 'customer');

        foreach ([$closed, $cancelled] as $project) {
            $this->actingAs($this->root)->from('/backend/renewal')->post("/backend/renewal/{$project->id}/cancel", ['reason' => 'Thử lại'])
                ->assertRedirect('/backend/renewal')->assertSessionHas('error');
            $fresh = $project->fresh();
            $this->assertSame([$project->status, $project->note, '2026-10-2'.($project->is($closed) ? '0' : '1')], [$fresh->status, $fresh->note, $fresh->next_renewal_date->toDateString()]);
        }

        $this->actingAs($this->root)->post("/backend/renewal/{$self->id}/cancel", ['reason' => 'Khách chuyển hosting'])->assertSessionHas('success');
        $this->assertSame('cancelled', $self->fresh()->status);
        $this->assertStringContainsString('Lý do: Khách chuyển hosting', $self->fresh()->note);
    }

    // ---- Không có ngày gia hạn: tính theo ngày bàn giao ----

    public function test_falls_back_to_delivery_date_and_never_hides_projects(): void
    {
        // Đã hủy bằng nút cũ (bị xóa ngày gia hạn) → theo ngày bàn giao, hiện ở nhóm hủy
        $oldCancelled = $this->project('2026-10-01', ['status' => 'cancelled', 'next_renewal_date' => null, 'deliver_date' => '2021-10-15']);
        // Còn chạy, chưa nhập ngày gia hạn: kỳ đầu = 1 năm sau bàn giao
        $due = $this->project('2026-10-01', ['next_renewal_date' => null, 'deliver_date' => '2025-10-20']);
        $justDelivered = $this->project('2026-10-01', ['next_renewal_date' => null, 'deliver_date' => '2026-10-03']); // năm bàn giao: chưa tới kỳ
        $self = $this->project('2026-10-01', ['next_renewal_date' => null, 'deliver_date' => '2024-10-22'], 'customer');
        $undated = Project::factory()->create(['name' => 'Không ngày nào', 'next_renewal_date' => null, 'deliver_date' => null]);

        $page = $this->page('period=this_month');
        $states = $page->viewData('states');
        $this->assertSame('cancelled', $states[$oldCancelled->id]);
        $this->assertSame('not_renewed', $states[$due->id]);
        $this->assertSame('renewed', $states[$justDelivered->id]);
        $this->assertSame('self_renew', $states[$self->id]);
        $this->assertSame('2026-10-15', $page->viewData('due')[$oldCancelled->id]->toDateString());
        $this->assertSame('2026-10-20', $page->viewData('due')[$due->id]->toDateString());
        $page->assertSee('Theo ngày bàn giao: 15/10/2021')->assertSee('Theo ngày bàn giao: 20/10/2025')
            ->assertViewHas('expected', 1_200_000.0);
        // Dự án không có ngày nào: báo riêng, có link
        $page->assertSee('1 dự án chưa có ngày gia hạn lẫn ngày bàn giao')->assertSee(route('backend.projects.show', $undated));

        // Ghi nhận gia hạn dự án chưa có ngày gia hạn: kỳ theo ngày bàn giao, lưu ngày gia hạn kế tiếp
        $this->actingAs($this->root)->post("/backend/renewal/{$due->id}/renew", ['due' => '2026-10-20', 'hosting_amount' => '1.000.000', 'domain_amount' => '200.000', 'paid_at' => '2026-10-10'])
            ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, '20/10/2026 → 20/10/2027'));
        $this->assertSame('2027-10-20', $due->fresh()->next_renewal_date->toDateString());
        $this->assertSame(1_200_000.0, (float) Payment::sole()->amount);
        // Kỳ sai so với ngày bàn giao → từ chối
        $this->actingAs($this->root)->post("/backend/renewal/{$justDelivered->id}/renew", ['due' => '2026-10-04', 'hosting_amount' => '1', 'domain_amount' => '0', 'paid_at' => '2026-10-10'])
            ->assertSessionHasErrorsIn('renew', 'due');
        // Không có ngày nào → không ghi nhận được
        $this->actingAs($this->root)->from('/backend/renewal')->post("/backend/renewal/{$undated->id}/renew", ['due' => '2026-10-20', 'hosting_amount' => '1', 'domain_amount' => '0', 'paid_at' => '2026-10-10'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'chưa có ngày gia hạn lẫn ngày bàn giao'));
        $this->assertSame(1, Payment::count());
    }

    public function test_every_project_with_a_date_shows_up_in_some_month(): void
    {
        // Ngày rải khắp năm, đủ trạng thái / loại hosting / năm cũ / chỉ có ngày bàn giao
        $statuses = ['lead', 'in_progress', 'delivered', 'maintenance', 'closed', 'cancelled'];
        $made = [];
        foreach (range(1, 12) as $month) {
            foreach ([1, 15, 28] as $i => $day) {
                $date = sprintf('%d-%02d-%02d', 2019 + ($month + $i) % 9, $month, $day);
                $attrs = ['status' => $statuses[($month + $day) % 6]];
                if ($day === 15) {
                    $attrs += ['next_renewal_date' => null, 'deliver_date' => $date];
                }
                $made[] = $this->project($date, $attrs, ['mine', 'customer', null][$month % 3])->id;
            }
        }
        $leap = $this->project('2024-02-29', ['status' => 'cancelled']);
        $made[] = $leap->id;

        // Gộp 12 tháng của năm nay bằng kỳ tùy chọn: mỗi dự án xuất hiện đúng 1 lần
        $seen = [];
        foreach (range(1, 12) as $month) {
            $from = sprintf('2026-%02d-01', $month);
            $to = Carbon::parse($from)->endOfMonth()->toDateString();
            $seen = [...$seen, ...$this->ids("period=custom&from={$from}&to={$to}")];
        }
        sort($seen);
        sort($made);
        $this->assertSame($made, $seen);
    }

    // ---- Hiển thị ----

    public function test_row_content_escaping_and_trashed_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Khách <b>đậm</b>']);
        $project = $this->project('2026-10-20', ['name' => 'Dự án <script>alert(1)</script>', 'customer_id' => $customer->id, 'primary_domain' => 'xss.test']);
        $customer->delete(); // khách đã xóa mềm vẫn hiện tên (quan hệ withTrashed)

        $page = $this->page('period=this_month');
        $html = $page->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>đậm</b>', $html);
        $page->assertSee('Dự án &lt;script&gt;alert(1)&lt;/script&gt;', false)->assertSee('>xss.test</a> · Khách &lt;b&gt;đậm&lt;/b&gt;', false);
        // Thông báo soạn sẵn có tên khách (đã xóa) và dữ liệu nút gia hạn là JSON đã escape trong thuộc tính
        $this->assertStringContainsString('Khách <b>đậm</b>', $page->viewData('messages')[$project->id]['full']);
        $this->assertStringContainsString('data-renew-open="{&quot;id&quot;:'.$project->id.',&quot;name&quot;:&quot;D\\u1ef1 \\u00e1n &lt;script&gt;', $html);
    }

    public function test_domain_opens_website_in_new_tab(): void
    {
        $customer = Customer::factory()->create(['name' => 'Chị Lan']);
        $this->project('2026-10-20', ['primary_domain' => 'shop-lan.vn', 'customer_id' => $customer->id]);
        $this->project('2026-10-21', ['primary_domain' => 'không phải domain', 'customer_id' => $customer->id]);
        $noDomain = $this->project('2026-10-22', ['primary_domain' => null, 'customer_id' => $customer->id]);

        $html = $this->page('period=this_month')->getContent();
        $this->assertStringContainsString('<a href="https://shop-lan.vn" target="_blank" rel="noopener noreferrer" class="renewal-domain" title="Mở https://shop-lan.vn ở tab mới">shop-lan.vn</a> · Chị Lan', $html);
        // Domain không hợp lệ: chỉ hiện chữ, không thành link
        $this->assertStringContainsString('>không phải domain · Chị Lan</div>', $html);
        $this->assertStringNotContainsString('href="https://không', $html);
        // Không có domain: chỉ tên khách, không có dấu " · " thừa
        $this->assertMatchesRegularExpression('#>'.$noDomain->name.'<.*?wrap-anywhere">Chị Lan</div>#s', $html);
    }

    public function test_empty_states(): void
    {
        $this->page()->assertSee('Không có dự án nào cần gia hạn')->assertSee('Chọn kỳ khác');
        $this->project('2026-10-06');
        $this->page('renewed=renewed')->assertSee('Không có dự án nào cần gia hạn')->assertSee('Thử chọn tab &quot;Tất cả&quot; hoặc kỳ khác.', false)->assertDontSee('Bỏ lọc');
    }

    public function test_viewer_sees_list_without_actions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('renewal')->create()->id]);
        $project = $this->project('2026-10-08');

        $this->actingAs($viewer)->get('/backend/renewal')->assertOk()->assertSee($project->name)
            ->assertDontSee('data-renew-open="', false)->assertDontSee('data-cancel-open="', false)->assertDontSee(route('backend.renewals.template'))
            ->assertDontSee(route('backend.projects.show', $project)); // không có quyền dự án → tên không phải link
    }
}
