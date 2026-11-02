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
use Tests\TestCase;

class RevenueReportTest extends TestCase
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

    /** Dữ liệu biểu đồ trong <script type="application/json" id="revenue-data"> (revenue.js vẽ từ đây). */
    private function chartData(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="revenue-data">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
    }

    /** Chữ hiển thị của phần tử đầu tiên khớp XPath; các phần tử con cách nhau một dấu cách như trên màn hình. */
    private function shown(string $html, string $xpath): ?string
    {
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $node = (new \DOMXPath($doc))->query($xpath)->item(0);
        $text = function (\DOMNode $n) use (&$text): string {
            $out = '';
            foreach ($n->childNodes as $c) $out .= $c->nodeType === XML_ELEMENT_NODE ? ' '.$text($c).' ' : $c->textContent;

            return $out;
        };

        return $node ? trim(preg_replace('/\s+/u', ' ', $text($node))) : null;
    }

    private function pay(Project $project, float $amount, string $date, string $type = 'new'): Payment
    {
        return Payment::factory()->create(['project_id' => $project->id, 'amount' => $amount, 'paid_at' => $date, 'type' => $type]);
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('report')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $project = Project::factory()->create();
        $this->pay($project, 1_000_000, '2026-10-05');

        $this->actingAs($noAccess)->get('/backend/report/revenue')->assertForbidden();
        $this->actingAs($noAccess)->get('/backend')->assertDontSee(route('backend.reports.revenue'));
        // Có quyền báo cáo nhưng không có quyền dự án/khách hàng → không có link sang đó
        $this->actingAs($viewer)->get('/backend/report/revenue')->assertOk()
            ->assertSee($project->customer->name)
            ->assertDontSee(route('backend.customers.edit', $project->customer))
            ->assertDontSee(route('backend.projects.show', $project));
    }

    public function test_period_revenue_and_comparison(): void
    {
        $root = $this->root();
        $a = Project::factory()->create(['price' => 10_000_000, 'start_date' => '2026-10-02']);
        $b = Project::factory()->create(['price' => 4_000_000, 'start_date' => '2026-09-15']);
        $deleted = Project::factory()->create(['price' => 9_000_000]);

        $this->pay($a, 3_000_000, '2026-10-01');
        $this->pay($a, 1_500_000, '2026-10-31', 'renewal');
        $this->pay($b, 2_000_000, '2026-09-30');
        $this->pay($b, 500_000, '2026-11-01');      // ngoài kỳ
        $this->pay($deleted, 7_000_000, '2026-10-05');
        $deleted->delete();                          // khoản thu của dự án đã xóa không tính

        $this->actingAs($root)->get('/backend/report/revenue')->assertOk()
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 4_500_000.0
                && $s['revenue_prev'] === 2_000_000.0
                && $s['revenue_growth'] === 125.0
                && $s['revenue_new'] === 3_000_000.0
                && $s['revenue_renewal'] === 1_500_000.0
                && $s['payment_count'] === 2
                && $s['new_count'] === 1 && $s['new_value'] === 10_000_000.0
                && $s['new_count_prev'] === 1);

        // Tháng trước: so với tháng 8 (bằng 0 → không so được)
        $this->actingAs($root)->get('/backend/report/revenue?period=last_month')
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 2_000_000.0 && $s['revenue_growth'] === null)
            ->assertSee('Kỳ trước chưa có số liệu')
            // Kỳ đang xem được đánh dấu trong nhóm nút chọn kỳ; topbar ghi kỳ + kỳ so sánh
            ->assertSee('class="ant-radio-button-wrapper ant-radio-button-wrapper-checked"  aria-current="page" ><span>Tháng trước</span>', false)
            ->assertSee('Tháng trước: 01/09/2026 – 30/09/2026 · so với 01/08/2026 – 31/08/2026');

        // Quý / năm
        $this->actingAs($root)->get('/backend/report/revenue?period=this_quarter')->assertViewHas('stats', fn ($s) => $s['revenue'] === 5_000_000.0);
        $this->actingAs($root)->get('/backend/report/revenue?period=this_year')->assertViewHas('stats', fn ($s) => $s['revenue'] === 7_000_000.0);
        $this->actingAs($root)->get('/backend/report/revenue?period=last_quarter')->assertViewHas('stats', fn ($s) => $s['revenue'] === 2_000_000.0);

        // Tùy chọn: kỳ trước cùng số ngày, liền trước (30/09–30/09 so với 29/09)
        $this->actingAs($root)->get('/backend/report/revenue?period=custom&from=2026-09-30&to=2026-10-01')
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 5_000_000.0)
            ->assertViewHas('prevFrom', fn ($d) => $d->toDateString() === '2026-09-28');
        // Đảo ngược from/to và ngày sai không gây lỗi
        $this->actingAs($root)->get('/backend/report/revenue?period=custom&from=2026-10-01&to=2026-09-30')->assertViewHas('stats', fn ($s) => $s['revenue'] === 5_000_000.0);
        $this->actingAs($root)->get('/backend/report/revenue?period=custom&from=2026-13-45&to=abc')->assertOk()->assertViewHas('from', fn ($d) => $d->toDateString() === '2026-10-01');
        $this->actingAs($root)->get('/backend/report/revenue?period[]=x&from[]=1&year[]=2')->assertOk();
    }

    public function test_monthly_chart_and_year_switch(): void
    {
        $root = $this->root();
        $p = Project::factory()->create();
        $this->pay($p, 1_000_000, '2026-03-15');
        $this->pay($p, 2_000_000, '2026-03-20', 'renewal');
        $this->pay($p, 5_000_000, '2025-03-01');
        $this->pay($p, 8_000_000, '2024-12-01');

        $this->actingAs($root)->get('/backend/report/revenue')
            ->assertViewHas('year', 2026)
            ->assertViewHas('monthly', fn ($m) => $m[3] === ['current' => 3_000_000.0, 'previous' => 5_000_000.0, 'new' => 1_000_000.0, 'renewal' => 2_000_000.0]);
        // Dữ liệu biểu đồ cột theo tháng: năm nay / năm trước, tháng chưa tới chưa có số năm nay, tháng hiện tại tô sáng
        $months = $this->chartData($this->actingAs($root)->get('/backend/report/revenue')->getContent())['months'];
        $this->assertSame(3_000_000, (int) $months['current'][2]);
        $this->assertSame(5_000_000, (int) $months['previous'][2]);
        $this->assertNull($months['current'][10]);  // tháng 11 chưa tới
        $this->assertSame(0, (int) $months['current'][9]); // tháng 10 (đang diễn ra) có số 0
        $this->assertSame(9, $months['active']);
        // Tooltip biểu đồ đường: thu mới / gia hạn từng tháng năm nay, tháng chưa tới không có
        $this->assertSame([1_000_000, 2_000_000], [(int) $months['new'][2], (int) $months['renewal'][2]]);
        $this->assertNull($months['new'][10]);
        $this->assertNull($months['renewal'][10]);
        // Nút năm trước / năm sau: lùi được tới năm có khoản thu đầu tiên (2024), không tiến quá năm nay
        $this->actingAs($root)->get('/backend/report/revenue')
            ->assertSee('href="'.route('backend.reports.revenue', ['year' => 2025]).'" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm trước"', false)
            ->assertSee('<button type="button" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm sau" aria-label="Năm sau" disabled>', false);
        $this->actingAs($root)->get('/backend/report/revenue?year=2024')
            ->assertSee('<button type="button" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm trước" aria-label="Năm trước" disabled>', false)
            ->assertSee('href="'.route('backend.reports.revenue', ['year' => 2025]).'" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm sau"', false);
        $this->assertSame(-1, $this->chartData($this->actingAs($root)->get('/backend/report/revenue?year=2024')->getContent())['months']['active']);
        $this->actingAs($root)->get('/backend/report/revenue?year=2025')
            ->assertViewHas('monthly', fn ($m) => $m[3]['current'] === 5_000_000.0 && $m[12]['previous'] === 8_000_000.0);
        // Năm ngoài khoảng bị chặn
        $this->actingAs($root)->get('/backend/report/revenue?year=99999')->assertOk()->assertViewHas('year', 2027);
    }

    public function test_debt_is_computed_per_project(): void
    {
        $root = $this->root();
        // Main tính SUM(giá) sau JOIN payments → giá bị cộng theo số khoản thu; ở đây phải ra đúng 6tr + 1tr
        $partial = Project::factory()->create(['price' => 10_000_000]);
        $this->pay($partial, 2_000_000, '2026-01-01');
        $this->pay($partial, 2_000_000, '2026-02-01');
        $unpaid = Project::factory()->create(['price' => 1_000_000]);
        $paid = Project::factory()->create(['price' => 3_000_000]);
        $this->pay($paid, 3_000_000, '2026-01-01');
        $over = Project::factory()->create(['price' => 1_000_000]);
        $this->pay($over, 5_000_000, '2026-01-01');                 // trả dư không làm giảm công nợ dự án khác
        Project::factory()->create(['price' => 50_000_000, 'status' => 'cancelled']); // đã hủy: không tính

        $this->actingAs($root)->get('/backend/report/revenue')
            ->assertViewHas('debtTotal', 7_000_000.0)
            ->assertViewHas('debtCount', 2)
            ->assertViewHas('debts', fn ($d) => $d->first()['project']->is($partial) && $d->first()['remaining'] === 6_000_000.0)
            ->assertSee('6.000.000');
    }

    public function test_top_three_trend_periods_are_orange(): void
    {
        $root = $this->root();
        $project = Project::factory()->create();
        // 12 tháng gần nhất (11/2025 → 10/2026): đỉnh ở T3, T5 = T2 (bằng nhau: kỳ trước đứng trước), T10 đang xem thấp
        foreach (['2025-12-05' => 24, '2026-02-05' => 53, '2026-03-05' => 93, '2026-04-05' => 40, '2026-05-05' => 53, '2026-10-02' => 6] as $date => $tr) {
            $this->pay($project, $tr * 1_000_000, $date);
        }

        $html = $this->actingAs($root)->get('/backend/report/revenue')->assertOk()->getContent();
        $trend = $this->chartData($html)['trend'];
        $this->assertCount(12, $trend);
        // Hạng 1–3 theo doanh thu; T5 = T2 thì kỳ trước (T2) xếp trên
        $top = array_column(array_filter($trend, fn ($t) => $t['top'] !== null), 'top', 'label');
        $this->assertSame(['T2' => 2, 'T3' => 1, 'T5' => 3], $top);
        $this->assertNull(end($trend)['top']); // kỳ đang xem thấp → không tô
        $this->assertTrue(end($trend)['running']);
        $this->assertStringContainsString('3 kỳ doanh thu cao nhất', $html);

        // Ít hơn 3 kỳ có doanh thu: chỉ tô các kỳ > 0
        Payment::query()->delete();
        $this->pay($project, 5_000_000, '2026-09-05');
        $html = $this->actingAs($root)->get('/backend/report/revenue')->getContent();
        $this->assertSame(['T9' => 1], array_column(array_filter($this->chartData($html)['trend'], fn ($t) => $t['top'] !== null), 'top', 'label'));
        $this->assertStringContainsString('1 kỳ doanh thu cao nhất', $html);

        // Không có doanh thu nào: không tô kỳ nào, không có chú thích
        Payment::query()->delete();
        $html = $this->actingAs($root)->get('/backend/report/revenue')->getContent();
        $this->assertSame([], array_filter(array_column($this->chartData($html)['trend'], 'top')));
        $this->assertStringNotContainsString('kỳ doanh thu cao nhất', $html);
    }

    public function test_renewal_forecast(): void
    {
        $root = $this->root();
        $mine = Hosting::factory()->create(['ownership' => 'mine']);
        $theirs = Hosting::factory()->create(['ownership' => 'customer']);
        $make = function (string $date, array $attrs = [], ?Hosting $hosting = null) {
            $p = Project::factory()->create(['next_renewal_date' => $date, 'renewal_hosting_amount' => 1_000_000, 'renewal_domain_amount' => 300_000, ...$attrs]);
            if ($hosting) {
                $p->hostings()->attach($hosting);
            }

            return $p;
        };

        $make('2026-11-05', [], $mine);                              // T11 dự kiến
        $make('2019-11-20', [], null);                               // năm cũ, chưa gắn hosting → vẫn tính T11
        $make('2027-12-01', [], $mine);                              // đã gia hạn kỳ 12/2026 → không tính
        $make('2026-12-10', [], $theirs);                            // hosting của khách → không tính
        $make('2026-12-15', ['status' => 'cancelled'], $mine);       // đã hủy → không tính
        $make('2021-11-13', ['status' => 'closed'], $mine);          // đã đóng, ngày gia hạn bỏ quên từ 2021 → không tính
        $make('2026-12-20', ['renewal_domain_amount' => 0], $mine);  // T12: 1tr
        $paidProject = $make('2027-03-01', [], $mine);
        $this->pay($paidProject, 1_300_000, '2026-03-02', 'renewal');  // thực thu T3
        $this->pay($paidProject, 9_000_000, '2026-03-02', 'new');      // thu mới không tính vào dòng tiền gia hạn

        $make('2026-10-25', [], $mine);                              // còn trong tháng này, chưa gia hạn → dự kiến T10
        $make('2026-10-05', [], $mine);                              // quá hạn đầu tháng → không vào dự kiến
        $this->pay($make('2027-10-02', [], $mine), 1_300_000, '2026-10-02', 'renewal'); // đã thu T10

        $this->actingAs($root)->get('/backend/report/revenue')
            ->assertViewHas('forecast', fn ($f) => $f['months'][11]['expected'] === 2_600_000.0
                && $f['months'][11]['expected_count'] === 2
                && $f['months'][11]['hosting'] === 2_000_000.0
                && $f['months'][12]['expected'] === 1_000_000.0
                && $f['months'][3]['paid'] === 1_300_000.0 && $f['months'][3]['expected'] === 0.0
                && $f['months'][10]['paid'] === 1_300_000.0 && $f['months'][10]['expected'] === 1_300_000.0 && $f['months'][10]['total'] === 2_600_000.0
                && $f['actual'] === 2_600_000.0
                && $f['upcoming'] === 4_900_000.0
                && $f['total'] === 7_500_000.0);
    }

    public function test_trashed_customer_and_group_permission(): void
    {
        $root = $this->root();
        $customer = Customer::factory()->create(['name' => 'Khách đã xóa']);
        $this->pay(Project::factory()->create(['customer_id' => $customer->id]), 2_000_000, '2026-10-03');
        $customer->delete();

        // Vẫn tính doanh thu, hiện tên nhưng không có link (trang sửa khách đã xóa là 404)
        $this->actingAs($root)->get('/backend/report/revenue')->assertOk()
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 2_000_000.0)
            ->assertSee('Khách đã xóa')
            ->assertDontSee(route('backend.customers.edit', $customer));

        // Quyền "Báo cáo doanh thu" có trong form nhóm quyền và gán được
        $this->actingAs($root)->get('/backend/groups/create')->assertOk()->assertSee('Báo cáo doanh thu');
    }

    public function test_customer_rankings(): void
    {
        $root = $this->root();
        // A giới thiệu B, B giới thiệu C (nhiều tầng); có vòng lặp C → A trong dữ liệu cũ
        $a = Customer::factory()->create(['name' => 'Khách A']);
        $b = Customer::factory()->create(['name' => 'Khách B', 'referrer_customer_id' => $a->id]);
        $c = Customer::factory()->create(['name' => 'Khách C', 'referrer_customer_id' => $b->id]);
        $a->update(['referrer_customer_id' => $c->id]);

        $this->pay(Project::factory()->create(['customer_id' => $a->id]), 1_000_000, '2026-10-01');
        $this->pay(Project::factory()->create(['customer_id' => $b->id]), 2_000_000, '2025-01-01');
        $this->pay(Project::factory()->create(['customer_id' => $c->id]), 4_000_000, '2026-10-02');

        $response = $this->actingAs($root)->get('/backend/report/revenue')->assertOk();
        $response->assertViewHas('ranking', function ($r) use ($a, $b, $c) {
            $total = $r['total']->keyBy(fn ($row) => $row['customer']->id);

            return $total[$a->id]['direct'] === 1_000_000.0 && $total[$a->id]['downline'] === 6_000_000.0
                && $total[$b->id]['downline'] === 5_000_000.0     // C và A (vòng lặp), không đếm lại chính B
                && $total[$c->id]['total'] === 7_000_000.0
                && $r['direct']->first()['customer']->is($c);
        });

        // Top khách trong kỳ (tháng 10): C 4tr, A 1tr; B không có thu trong kỳ
        $response->assertViewHas('topCustomers', fn ($t) => $t->count() === 2 && $t[0]['customer']->is($c) && $t[0]['total'] === 4_000_000.0)
            ->assertSee(route('backend.customers.edit', $c));
    }

    public function test_year_boundaries(): void
    {
        $root = $this->root();
        $p = Project::factory()->create(['next_renewal_date' => '2025-01-20', 'renewal_hosting_amount' => 500_000, 'renewal_domain_amount' => 0]);
        $this->pay($p, 1_000_000, '2026-12-31');
        $this->pay($p, 3_000_000, '2026-12-01');
        $this->pay($p, 2_000_000, '2027-01-01');

        // 01/01: tháng trước = 12 năm trước, so với tháng 11 (0 → "Mới"); tuần chứa 01/01 vắt qua năm
        Carbon::setTestNow('2027-01-01 08:00:00');
        $this->actingAs($root)->get('/backend/report/revenue?period=last_month')
            ->assertViewHas('from', fn ($d) => $d->toDateString() === '2026-12-01')
            ->assertViewHas('to', fn ($d) => $d->toDateString() === '2026-12-31')
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 4_000_000.0 && $s['revenue_growth'] === null);
        $this->actingAs($root)->get('/backend/report/revenue')
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 2_000_000.0 && $s['revenue_prev'] === 4_000_000.0 && $s['revenue_growth'] === -50.0)
            ->assertViewHas('quick', fn ($q) => $q['Tuần này']['current'] === 3_000_000.0 && $q['Năm nay']['previous'] === 4_000_000.0)
            ->assertViewHas('year', 2027)
            ->assertViewHas('monthly', fn ($m) => $m[1]['current'] === 2_000_000.0 && $m[12]['previous'] === 4_000_000.0)
            // Dự án hạn 20/01 (ngày lưu năm cũ) vào dự kiến tháng 1
            ->assertViewHas('forecast', fn ($f) => $f['months'][1]['expected'] === 500_000.0 && $f['upcoming'] === 500_000.0)
            ->assertSee('-50,0%');

        // 31/12: không còn tháng sau, chỉ phần còn lại của tháng 12
        Carbon::setTestNow('2026-12-31 08:00:00');
        $this->actingAs($root)->get('/backend/report/revenue?period=this_quarter')
            ->assertViewHas('stats', fn ($s) => $s['revenue'] === 4_000_000.0)
            ->assertViewHas('forecast', fn ($f) => $f['upcoming'] === 0.0);

        // 29/02 năm nhuận: tháng trước của 31/03 là tháng 2 trọn vẹn
        Carbon::setTestNow('2028-03-31 08:00:00');
        $this->actingAs($root)->get('/backend/report/revenue?period=last_month')
            ->assertViewHas('to', fn ($d) => $d->toDateString() === '2028-02-29')
            ->assertViewHas('prevFrom', fn ($d) => $d->toDateString() === '2028-01-01');
    }

    public function test_uses_vietnam_timezone(): void
    {
        $this->assertSame('Asia/Ho_Chi_Minh', config('app.timezone'));

        // 23:30 UTC ngày 31/10 = 06:30 sáng 01/11 giờ VN → "Tháng này" phải là tháng 11
        Carbon::setTestNow(Carbon::parse('2026-10-31 23:30:00', 'UTC'));
        $this->actingAs($this->root())->get('/backend/report/revenue')
            ->assertViewHas('from', fn ($d) => $d->toDateString() === '2026-11-01')
            ->assertSee('Tháng này: 01/11/2026 – 30/11/2026');
    }

    public function test_trend_and_comparisons(): void
    {
        $root = $this->root();
        $p = Project::factory()->create(['start_date' => '2026-09-05', 'price' => 7_000_000]);
        $this->pay($p, 1_000_000, '2026-10-02');                 // kỳ này, thu mới
        $this->pay($p, 400_000, '2026-10-03', 'renewal');        // kỳ này, gia hạn
        $this->pay($p, 2_000_000, '2026-09-10');                 // kỳ trước, thu mới
        $this->pay($p, 600_000, '2026-09-11', 'renewal');        // kỳ trước, gia hạn
        $this->pay($p, 5_000_000, '2025-10-20');                 // cùng kỳ năm trước
        $this->pay($p, 3_000_000, '2025-11-01');                 // tháng 11/2025 = kỳ đầu trong 12 tháng

        $this->actingAs($root)->get('/backend/report/revenue')->assertOk()
            ->assertViewHas('stats', fn ($s) => $s['revenue_new_prev'] === 2_000_000.0
                && $s['revenue_renewal_prev'] === 600_000.0
                && $s['revenue_new_growth'] === -50.0
                && $s['revenue_renewal_growth'] === -33.3
                && $s['payment_count_prev'] === 2
                && $s['revenue_yoy'] === 5_000_000.0
                && $s['revenue_yoy_growth'] === -72.0)
            ->assertViewHas('trend', fn ($t) => count($t) === 12
                && $t[0]['label'] === 'T11/25' && $t[0]['revenue'] === 3_000_000.0
                && $t[2]['label'] === 'T1/26' && $t[3]['label'] === 'T2'
                && $t[10]['revenue'] === 2_600_000.0 && $t[10]['new_value'] === 7_000_000.0
                && $t[11]['label'] === 'T10' && $t[11]['revenue'] === 1_400_000.0 && $t[11]['ongoing']
                && ! $t[10]['ongoing'])
            ->assertSee('Cùng kỳ năm trước');

        // Quý: 8 quý, năm: 6 năm, tùy chọn: 8 khoảng cùng độ dài
        $this->actingAs($root)->get('/backend/report/revenue?period=this_quarter')
            ->assertViewHas('trend', fn ($t) => count($t) === 8 && $t[7]['label'] === 'Q4/26' && $t[3]['label'] === 'Q4/25' && $t[3]['revenue'] === 8_000_000.0);
        $this->actingAs($root)->get('/backend/report/revenue?period=last_year')
            ->assertViewHas('trend', fn ($t) => count($t) === 6 && $t[5]['label'] === '2025' && $t[5]['revenue'] === 8_000_000.0 && $t[0]['label'] === '2020');
        $this->actingAs($root)->get('/backend/report/revenue?period=custom&from=2026-10-01&to=2026-10-07')
            ->assertViewHas('trend', fn ($t) => count($t) === 8 && $t[7]['label'] === '01/10–07/10' && $t[7]['revenue'] === 1_400_000.0 && $t[6]['from']->toDateString() === '2026-09-24');
    }

    public function test_edge_cases_in_comparison_display(): void
    {
        $root = $this->root();
        // Không có dữ liệu: biểu đồ nhận toàn số 0 (trục Y 0 – 1tr do revenue.js đặt minMax), trung bình 0
        $data = $this->chartData($this->actingAs($root)->get('/backend/report/revenue')->assertOk()->getContent());
        $this->assertSame([0], array_values(array_unique(array_map('intval', array_slice($data['months']['current'], 0, 10)))));
        $this->assertSame(0, (int) $data['average']);

        // Kỳ này 0, kỳ trước có thu → giảm 100% (mũi tên xuống, chênh lệch âm)
        $this->pay(Project::factory()->create(), 2_000_000, '2026-09-15');
        $this->actingAs($root)->get('/backend/report/revenue')
            ->assertViewHas('stats', fn ($s) => $s['revenue_growth'] === -100.0)
            ->assertSee('-100,0%')->assertSee('(−2tr)', false);

        // Khoảng tùy chọn dài: nhãn có năm, không trùng nhau
        $this->actingAs($root)->get('/backend/report/revenue?period=custom&from=2026-01-01&to=2026-12-31')
            ->assertViewHas('trend', function ($t) {
                $labels = array_column($t, 'label');

                return count(array_unique($labels)) === 8 && end($labels) === '01/01/26';
            });
    }

    public function test_empty_database(): void
    {
        $this->actingAs($this->root())->get('/backend/report/revenue')->assertOk()
            ->assertSee('Không có khoản thu nào trong kỳ.')
            ->assertSee('Không có dự án nào còn nợ.');
    }

    public function test_displayed_figures(): void
    {
        $root = $this->root();
        $referrer = Customer::factory()->create(['name' => 'Người Giới Thiệu']);
        $a = Customer::factory()->create(['name' => 'Khách A', 'company' => 'Công ty A', 'referrer_customer_id' => $referrer->id]);
        $b = Customer::factory()->create(['name' => 'Khách B', 'company' => null]);
        $p1 = Project::factory()->create(['customer_id' => $a->id, 'name' => 'du-an-1.vn', 'price' => 10_000_000, 'start_date' => '2026-10-02', 'deliver_date' => null, 'status' => 'in_progress']);
        $p2 = Project::factory()->create(['customer_id' => $b->id, 'name' => 'du-an-2.vn', 'price' => 2_600_000, 'start_date' => '2026-09-05', 'deliver_date' => '2026-09-20', 'status' => 'delivered']);
        $p3 = Project::factory()->create(['customer_id' => $a->id, 'name' => 'du-an-3.vn', 'price' => 4_000_000, 'start_date' => '2026-10-08', 'deliver_date' => null, 'status' => 'in_progress']);
        $this->pay($p1, 3_000_000, '2026-10-01');
        $this->pay($p1, 1_500_000, '2026-10-03', 'renewal');
        $this->pay($p2, 2_000_000, '2026-09-30');
        $this->pay($p2, 600_000, '2026-09-11', 'renewal');
        $this->pay($p2, 1_500_000, '2026-10-05');

        $html = $this->actingAs($root)->get('/backend/report/revenue')->assertOk()->getContent();

        // Tháng 10: 6tr, tháng 9: 2,6tr → +130,8% (+3,4tr), mũi tên xanh
        $this->assertSame('6.000.000', $this->shown($html, "//*[@data-stat='revenue']"));
        $this->assertSame('+130,8% (+3,4tr) so với kỳ trước', $this->shown($html, "//*[@data-stat='revenue']/following-sibling::div[1]"));
        $this->assertStringContainsString('<span class="text-xs text-[#52c41a]"><span role="img" aria-label="arrow-up"', $html);
        // Cơ cấu: thu mới 4,5tr (75%), gia hạn 1,5tr (25%)
        $this->assertStringStartsWith('Thu mới 75% 4.500.000', $this->shown($html, "//*[@id='mix-donut']/following-sibling::div/div[1]"));
        $this->assertStringStartsWith('Gia hạn 25% 1.500.000', $this->shown($html, "//*[@id='mix-donut']/following-sibling::div/div[2]"));
        // Chỉ số: 2 dự án mới (+1, xanh), bàn giao 0 (-1, đỏ), giá trị TB 7tr so với 2,6tr
        $kpi = fn (string $title) => $this->shown($html, "//div[contains(@class,'ant-statistic-title')][normalize-space()='$title']/..");
        $this->assertSame('Dự án mới 2 dự án +1 Kỳ trước 1', $kpi('Dự án mới'));
        $this->assertSame('Bàn giao 0 dự án -1 Kỳ trước 1', $kpi('Bàn giao'));
        $this->assertSame('Giá trị trung bình / dự án 7tr +169,2% Kỳ trước 2.600.000', $kpi('Giá trị trung bình / dự án'));
        // Công nợ: giá − thu mới đã thu (tiền gia hạn không trừ vào nợ hợp đồng): du-an-1 10tr − 3tr, du-an-3 4tr
        $this->assertSame('Công nợ hiện tại 11.000.000 2 dự án còn công nợ (không tính dự án đã hủy)', $kpi('Công nợ hiện tại'));
        $this->assertStringContainsString('<span class="text-xs font-normal text-[#52c41a]"><span role="img" aria-label="caret-up"', $html);
        $this->assertStringContainsString('<span class="text-xs font-normal text-[#f5222d]"><span role="img" aria-label="caret-down-filled"', $html);
        // Khách trả nhiều nhất: công ty + người giới thiệu, % trên doanh thu kỳ
        $this->assertSame('Khách A Công ty A · Giới thiệu bởi Người Giới Thiệu', $this->shown($html, '(//table)[1]//tbody/tr[1]/td[1]'));
        $this->assertSame('4.500.000 75%', $this->shown($html, '(//table)[1]//tbody/tr[1]/td[3]'));
        $this->assertSame('Khách B', $this->shown($html, '(//table)[1]//tbody/tr[2]/td[1]'));
        $this->assertSame('1.500.000 25%', $this->shown($html, '(//table)[1]//tbody/tr[2]/td[3]'));
        // Dự án còn nợ: % đã thu, giá, còn nợ
        $this->assertSame('du-an-1.vn Khách A · đã thu 30%', $this->shown($html, '(//table)[2]//tbody/tr[1]/td[1]'));
        $this->assertSame('10.000.000 7.000.000', $this->shown($html, '(//table)[2]//tbody/tr[1]/td[2]').' '.$this->shown($html, '(//table)[2]//tbody/tr[1]/td[3]'));
        $this->assertSame('du-an-3.vn Khách A · đã thu 0%', $this->shown($html, '(//table)[2]//tbody/tr[2]/td[1]'));
        // Xếp hạng giới thiệu: người giới thiệu nhận doanh thu của Khách A (4,5tr)
        $this->assertSame('1 Người Giới Thiệu 4.500.000', $this->shown($html, "//*[@data-ranking-panel='downline']//li[1]"));
    }

    public function test_display_without_any_data_and_unchanged_revenue(): void
    {
        $root = $this->root();
        $html = $this->actingAs($root)->get('/backend/report/revenue')->assertOk()->getContent();
        $kpi = fn (string $h, string $title) => $this->shown($h, "//div[contains(@class,'ant-statistic-title')][normalize-space()='$title']/..");

        $this->assertSame('0', $this->shown($html, "//*[@data-stat='revenue']"));
        $this->assertSame('Không đổi so với kỳ trước', $this->shown($html, "//*[@data-stat='revenue']/following-sibling::div[1]"));
        $this->assertStringStartsWith('Thu mới 0% 0', $this->shown($html, "//*[@id='mix-donut']/following-sibling::div/div[1]"));
        $this->assertStringStartsWith('Gia hạn 0% 0', $this->shown($html, "//*[@id='mix-donut']/following-sibling::div/div[2]"));
        $this->assertSame('Dự án mới 0 dự án Kỳ trước 0', $kpi($html, 'Dự án mới'));
        $this->assertSame('Giá trị trung bình / dự án — Kỳ trước —', $kpi($html, 'Giá trị trung bình / dự án'));
        $this->assertSame('Công nợ hiện tại 0 0 dự án còn công nợ (không tính dự án đã hủy)', $kpi($html, 'Công nợ hiện tại'));
        $this->assertSame('Tuần này 0 Kỳ trước: 0', $kpi($html, 'Tuần này'));
        $this->assertStringContainsString('0 đã thu / 0 cả năm', $this->shown($html, "//*[@id='renewal-chart']/.."));
        $this->assertStringContainsString('Còn phải thu: 0', $this->shown($html, "//*[@id='renewal-chart']/.."));
        $this->assertSame('Chưa có dữ liệu.', $this->shown($html, "//*[@data-ranking-panel='total']//li"));
        $this->assertStringContainsString('Không có khoản thu nào trong kỳ.', $html);
        $this->assertStringContainsString('Không có dự án nào còn nợ.', $html);

        // Doanh thu bằng kỳ trước: "Không đổi", tag 0%
        $p = Project::factory()->create(['price' => 0]);
        $this->pay($p, 1_000_000, '2026-09-10');
        $this->pay($p, 1_000_000, '2026-10-02');
        $html = $this->actingAs($root)->get('/backend/report/revenue')->getContent();
        $this->assertSame('Không đổi so với kỳ trước', $this->shown($html, "//*[@data-stat='revenue']/following-sibling::div[1]"));
        $this->assertSame('Tháng này 1.000.000 Kỳ trước: 1.000.000 0%', $kpi($html, 'Tháng này').' '.$this->shown($html, "//div[contains(@class,'ant-statistic-title')][normalize-space()='Tháng này']/../following-sibling::span[1]"));
    }
}
