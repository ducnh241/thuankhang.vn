<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Group;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 15:00:00');
    }

    private function pay(Project $project, float $amount, string $date, string $type = 'new'): void
    {
        Payment::factory()->create(['project_id' => $project->id, 'amount' => $amount, 'paid_at' => $date, 'type' => $type]);
    }

    public function test_sections_follow_permissions(): void
    {
        $onlyAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions()->create()->id]);
        $this->actingAs($onlyAccess)->get('/backend')->assertOk()
            ->assertViewHas('revenue', null)->assertViewHas('renewals', null)->assertViewHas('recentPayments', null)
            ->assertSee('chưa được cấp quyền xem mục nào')
            ->assertDontSee('data-stat="revenue"', false)->assertDontSee('data-todo', false);

        $renewalOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('renewal')->create()->id]);
        $this->actingAs($renewalOnly)->get('/backend')->assertOk()
            ->assertSee('data-todo', false)
            ->assertViewHas('hostings', null)->assertViewHas('recentProjects', null)
            ->assertDontSee('data-stat="debt"', false)->assertDontSee('Khoản thu mới nhất');

        $this->actingAs(User::factory()->superAdmin()->create(['name' => 'Đức']))->get('/backend')->assertOk()
            ->assertSee('Chào buổi chiều, Đức')->assertSee('Thứ bảy, 10/10/2026')
            ->assertSee('data-stat="revenue"', false)->assertSee('data-stat="projects"', false)
            ->assertSee('data-stat="customers"', false)->assertSee('data-stat="debt"', false)
            ->assertSee('Khoản thu mới nhất')->assertSee('Dự án mới nhất');
    }

    public function test_kpis(): void
    {
        $root = User::factory()->superAdmin()->create();
        $a = Project::factory()->create(['status' => 'in_progress', 'price' => 10_000_000, 'start_date' => '2026-10-02']);
        $b = Project::factory()->create(['status' => 'maintenance', 'price' => 3_000_000, 'start_date' => '2025-01-01']);
        Project::factory()->create(['status' => 'cancelled', 'price' => 9_000_000]);   // đã hủy: không tính đang chạy / công nợ
        Project::factory()->create(['status' => 'lead', 'price' => 0]);

        $this->pay($a, 4_000_000, '2026-10-05');
        $this->pay($b, 3_000_000, '2026-10-10');
        $this->pay($b, 2_000_000, '2026-09-08');   // trong 1–10/9 → kỳ so sánh
        $this->pay($b, 9_000_000, '2026-09-25');   // ngoài 1–10/9 → chỉ vào "cả tháng trước"
        Customer::factory()->create(['created_at' => '2026-09-01']);

        $this->actingAs($root)->get('/backend')->assertOk()
            ->assertViewHas('revenue', fn ($r) => $r['current'] === 7_000_000.0 && $r['previous'] === 2_000_000.0
                && $r['previous_full'] === 11_000_000.0 && $r['growth'] === 250.0
                && count($r['months']) === 6 && $r['months'][5]['label'] === 'T10' && $r['months'][4]['value'] === 11_000_000.0)
            ->assertViewHas('projectStats', fn ($s) => $s['total'] === 4 && $s['running'] === 2 && $s['in_progress'] === 1 && $s['new_this_month'] === 1)
            // 4 khách tạo theo dự án (hôm nay) + 1 khách tháng trước
            ->assertViewHas('customerStats', fn ($s) => $s['total'] === 5 && $s['new_this_month'] === 4)
            // Công nợ: dự án a còn 6tr (b đã trả dư); dự án đã hủy và giá 0 không tính
            ->assertViewHas('debt', fn ($d) => $d['total'] === 6_000_000.0 && $d['count'] === 1)
            ->assertSee('so với 1–10/9');
    }

    public function test_attention_list_matches_linked_pages(): void
    {
        $root = User::factory()->superAdmin()->create();
        $make = fn (string $date, array $attrs = []) => Project::factory()->create(['next_renewal_date' => $date, 'renewal_hosting_amount' => 1_000_000, 'renewal_domain_amount' => 200_000, ...$attrs]);

        $soon = $make('2025-10-15');                                   // năm cũ, đến kỳ 15/10 → sắp gia hạn
        $make('2026-11-05');                                            // còn 26 ngày → sắp gia hạn
        $make('2026-11-20');                                            // ngoài 30 ngày
        $make('2027-10-20');                                            // đã gia hạn kỳ 20/10/2026
        $make('2026-10-25', ['status' => 'cancelled']);                 // đã hủy → không tính
        $make('2026-10-26', ['status' => 'closed']);                    // đã đóng → không tính
        $make('2026-03-01');                                            // quá hạn
        $make('2026-04-01', ['status' => 'cancelled']);                 // quá hạn nhưng đã hủy → không tính
        $make('2019-03-16', ['status' => 'closed']);                    // đã đóng, ngày gia hạn cũ từ 2019 → không tính
        $make('2026-10-27')->hostings()->attach(Hosting::factory()->create(['ownership' => 'customer']));  // khách tự gia hạn → không tính
        $make('2026-05-01')->hostings()->attach(Hosting::factory()->create(['ownership' => 'customer']));  // quá hạn nhưng khách tự gia hạn → không tính

        Hosting::factory()->create(['expire_date' => '2026-10-20', 'is_active' => true]);
        Hosting::factory()->create(['expire_date' => '2026-10-01', 'is_active' => true]);   // đã quá hạn → vẫn cần chú ý
        Hosting::factory()->create(['expire_date' => '2026-10-15', 'is_active' => false]);  // ngừng dùng → bỏ
        Hosting::factory()->create(['expire_date' => '2027-01-01', 'is_active' => true]);

        $response = $this->actingAs($root)->get('/backend')->assertOk()
            ->assertViewHas('renewals', fn ($r) => $r['upcoming_count'] === 2 && $r['overdue_count'] === 1
                && $r['upcoming_amount'] === 2_400_000.0 && $r['upcoming'][0]['project']->is($soon) && $r['upcoming'][0]['days'] === 5)
            ->assertViewHas('hostings', fn ($h) => $h->count() === 2)
            ->assertSee('5 việc cần chú ý', false)->assertSee('Còn 5 ngày');

        // Bấm sang trang đích phải thấy đúng số đó
        $rows = fn ($r) => $r->viewData('projects')->count();
        $this->assertSame(2, $rows($this->actingAs($root)->get('/backend/renewal?period=custom&from=2026-10-10&to=2026-11-09&renewed=not_renewed')));
        $this->assertSame(1, $rows($this->actingAs($root)->get('/backend/renewal?period=overdue&renewed=not_renewed')));
        // Danh sách hosting không còn bộ lọc: ô dashboard dẫn về danh sách đầy đủ (hosting cần chú ý hiện màu vàng / đỏ)
        $response->assertSee('href="'.route('backend.hostings.index').'"', false);
    }

    public function test_recent_lists_and_empty_state(): void
    {
        $root = User::factory()->superAdmin()->create();
        $this->actingAs($root)->get('/backend')->assertOk()
            ->assertSee('Chưa có khoản thu nào.')->assertSee('Chưa có dự án nào.')
            ->assertSee('Không có dự án nào đến hạn gia hạn trong 30 ngày tới.')
            ->assertSee('Mọi dự án đã thu đủ');

        $deleted = Project::factory()->create(['name' => 'Dự án đã xóa']);
        $this->pay($deleted, 5_000_000, '2026-10-09');
        $deleted->delete();
        $p = Project::factory()->create(['name' => '<b>Dự án mới</b>']);
        $this->pay($p, 1_500_000, '2026-10-10', 'renewal');

        $this->actingAs($root)->get('/backend')
            ->assertViewHas('recentPayments', fn ($c) => $c->count() === 1)
            ->assertSee('+1.500.000')->assertSee('&lt;b&gt;Dự án mới&lt;/b&gt;', false)
            ->assertDontSee('Dự án đã xóa');
    }

    public function test_single_permission_cards_are_not_empty(): void
    {
        Hosting::factory()->create(['expire_date' => '2026-10-20', 'name' => 'VPS sắp hết']);
        Hosting::factory()->create(['expire_date' => '2027-06-01']);

        // Chỉ quyền hosting: thẻ "Cần xử lý" có ô hosting + danh sách, không có phần gia hạn
        $hostingOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('hosting')->create()->id]);
        $this->actingAs($hostingOnly)->get('/backend')->assertOk()
            ->assertSee('data-todo', false)->assertSee('1 hosting')->assertSee('VPS sắp hết')
            ->assertDontSee('Gia hạn trong 30 ngày')->assertDontSee('Xem tất cả gia hạn');

        // Chỉ quyền thanh toán: thấy doanh thu + khoản thu, không thấy công nợ / dự án
        $paymentOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('payment')->create()->id]);
        $this->actingAs($paymentOnly)->get('/backend')->assertOk()
            ->assertSee('data-stat="revenue"', false)->assertSee('Khoản thu mới nhất')
            ->assertDontSee('data-stat="debt"', false)->assertDontSee('data-stat="projects"', false)->assertDontSee('data-todo', false)
            ->assertDontSee('Xem báo cáo doanh thu');
    }

    public function test_revenue_card_texts_and_month_boundaries(): void
    {
        $root = User::factory()->superAdmin()->create();
        // Cả 2 kỳ đều 0: không hiện mũi tên tăng
        $this->actingAs($root)->get('/backend')->assertSee('Chưa có khoản thu (1–10/9 cũng chưa có)')->assertDontSee('▲ +0,0%');

        $p = Project::factory()->create();
        $this->pay($p, 1_000_000, '2026-10-03');
        $this->pay($p, 1_000_000, '2026-09-03');
        $this->actingAs($root)->get('/backend')->assertSee('Bằng cùng kỳ 1–10/9');

        // 31/03: tháng trước (tháng 2) chỉ có 28 ngày → so với cả tháng 2, không tràn sang tháng 3
        Carbon::setTestNow('2027-03-31 10:00:00');
        $this->pay($p, 2_000_000, '2027-02-28');
        $this->pay($p, 4_000_000, '2027-03-31');
        $this->actingAs($root)->get('/backend')
            ->assertViewHas('revenue', fn ($r) => $r['current'] === 4_000_000.0 && $r['previous'] === 2_000_000.0 && $r['growth'] === 100.0)
            ->assertSee('Chào buổi sáng')->assertSee('so với 1–28/2'); // tháng 2 chỉ có 28 ngày: so với 1–28/2

        // 01/01: tháng trước là tháng 12 năm trước; chưa có "quá hạn trong năm"
        Carbon::setTestNow('2027-01-01 20:00:00');
        Project::factory()->create(['next_renewal_date' => '2026-06-01']);
        $this->pay($p, 500_000, '2026-12-01');
        $this->actingAs($root)->get('/backend')
            ->assertViewHas('revenue', fn ($r) => $r['previous'] === 500_000.0 && $r['months'][4]['label'] === 'T12')
            ->assertViewHas('renewals', fn ($r) => $r['overdue_count'] === 0)
            ->assertSee('Chào buổi tối');
    }

    public function test_due_tags_and_more_line(): void
    {
        $root = User::factory()->superAdmin()->create();
        // 0 ngày đỏ, 1–7 ngày cam (tính cả đúng 7), từ 8 ngày xanh; chỉ hiện 6 dự án đầu
        foreach (['2026-10-10' => 'Hạn hôm nay', '2026-10-13' => 'Hạn còn 3', '2026-10-17' => 'Hạn còn 7', '2026-10-18' => 'Hạn còn 8',
            '2026-10-30' => 'Hạn còn 20', '2026-11-04' => 'Hạn còn 25', '2026-11-08' => 'Hạn còn 29'] as $date => $name) {
            Project::factory()->create(['next_renewal_date' => $date, 'name' => $name, 'renewal_hosting_amount' => 1_000_000, 'renewal_domain_amount' => 200_000]);
        }
        Hosting::factory()->create(['expire_date' => '2026-10-08', 'is_active' => true]);
        Hosting::factory()->create(['expire_date' => '2026-10-22', 'is_active' => true]);

        $response = $this->actingAs($root)->get('/backend')->assertOk();
        // Chỉ xét thẻ "Cần xử lý" (danh sách "Dự án mới nhất" bên dưới cũng có tên các dự án này)
        $todo = Str::between($response->getContent(), 'data-todo', 'Doanh thu 6 tháng');
        $this->assertStringNotContainsString('Hạn còn 29', $todo);
        $this->assertStringContainsString('Hạn còn 25', $todo);

        $response->assertViewHas('renewals', fn ($r) => $r['upcoming_count'] === 7 && count($r['upcoming']) === 6)
            ->assertSee('ant-tag-red">Hôm nay', false)
            ->assertSee('ant-tag-orange">Còn 3 ngày', false)->assertSee('ant-tag-orange">Còn 7 ngày', false)
            ->assertSee('ant-tag-blue">Còn 8 ngày', false)->assertSee('ant-tag-blue">Còn 25 ngày', false)
            ->assertSeeInOrder(['Hạn hôm nay', 'Hạn còn 3', 'Hạn còn 7', 'Hạn còn 8', 'Hạn còn 20', 'Hạn còn 25'])
            ->assertSee('Còn 1 dự án nữa')
            // Trên điện thoại cột phí ẩn, phí hiện dưới tên dự án
            ->assertSee('Phí gia hạn 1.200.000')
            // Hosting: quá hạn đỏ, còn 12 ngày xanh
            ->assertSee('ant-tag-red">Quá hạn 2 ngày', false)->assertSee('ant-tag-blue">Còn 12 ngày', false);
    }

    public function test_revenue_card_growth_directions(): void
    {
        $root = User::factory()->superAdmin()->create();
        $p = Project::factory()->create();

        // Tháng trước cùng kỳ chưa có → "Mới", không có phần trăm
        $this->pay($p, 1_000_000, '2026-10-03');
        $this->actingAs($root)->get('/backend')->assertViewHas('revenue', fn ($r) => $r['growth'] === null)
            ->assertSee('Mới so với cùng kỳ tháng trước')->assertDontSee('anticon-arrow-up', false);

        // Giảm: mũi tên xuống màu đỏ
        $this->pay($p, 4_000_000, '2026-09-04');
        $this->actingAs($root)->get('/backend')->assertViewHas('revenue', fn ($r) => $r['growth'] === -75.0)
            ->assertSee('text-[#f5222d]">', false)->assertSee('anticon-arrow-down', false)->assertSee('-75,0%')
            ->assertDontSee('anticon-arrow-up', false)
            // Icon thẻ nằm ở hàng tiêu đề, trước con số
            ->assertSeeInOrder(['data-stat="revenue"', 'ant-statistic-title', 'anticon-fund', 'ant-statistic-content-value'], false);
    }

    public function test_upcoming_renewals_across_year_end(): void
    {
        Carbon::setTestNow('2026-12-20 09:00:00');
        $root = User::factory()->superAdmin()->create();
        $jan = Project::factory()->create(['next_renewal_date' => '2027-01-10', 'name' => 'Hạn tháng 1']);
        $dec = Project::factory()->create(['next_renewal_date' => '2020-12-25', 'name' => 'Hạn 25/12 (năm cũ)']);
        Project::factory()->create(['next_renewal_date' => '2028-01-05']);   // đã gia hạn kỳ 01/2027

        $this->actingAs($root)->get('/backend')
            ->assertViewHas('renewals', fn ($r) => $r['upcoming_count'] === 2
                && $r['upcoming'][0]['project']->is($dec) && $r['upcoming'][0]['days'] === 5
                && $r['upcoming'][1]['project']->is($jan) && $r['upcoming'][1]['days'] === 21)
            ->assertSeeInOrder(['Hạn 25/12 (năm cũ)', 'Hạn tháng 1']);
    }
}
