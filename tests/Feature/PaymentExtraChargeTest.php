<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Khoản thu "Phát sinh" (chi phí ngoài hợp đồng, vd làm thêm tính năng sau bàn giao): ghi "chưa thu" thì tính vào công
 * nợ, chưa tính doanh thu; bấm "Đã thu" thì vào doanh thu theo ngày thu và hết nợ. Nợ hợp đồng (giá − thu mới) giữ nguyên.
 */
class PaymentExtraChargeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
    }

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);

        return new DOMXPath($dom);
    }

    private function text(?\DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node?->textContent ?? ''));
    }

    private function pay(Project $project, string $amount, array $extra = [])
    {
        return $this->actingAs($this->root())->post('/backend/payment', [
            'project_id' => $project->id, 'amount' => $amount, 'type' => 'new', 'paid_at' => '2026-10-01', 'method' => 'bank_transfer', ...$extra,
        ])->assertSessionHasNoErrors();
    }

    /** Công nợ, doanh thu tháng ở các trang: [dashboard, trang thanh toán, báo cáo, danh sách dự án]. */
    private function figures(User $root): array
    {
        $dashboard = $this->actingAs($root)->get('/backend')->viewData('debt');
        $payments = $this->actingAs($root)->get('/backend/payment')->viewData('metrics');
        $report = $this->actingAs($root)->get('/backend/report/revenue');
        $projects = $this->actingAs($root)->get('/backend/project')->viewData('metrics')['debt'];

        return [
            'debt' => [$dashboard['total'], $payments['debt']['total'], $report->viewData('debtTotal'), $projects['total']],
            'revenue' => [$this->actingAs($root)->get('/backend')->viewData('revenue')['current'], $payments['month']['total'], $report->viewData('stats')['revenue']],
        ];
    }

    public function test_case_1_contract_debt_cleared_by_new_payment(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => 3_000_000, 'status' => 'delivered']);

        // Hợp đồng 3tr, thu lần 1 2tr → còn nợ 1tr
        $this->pay($project, '2.000.000');
        $this->assertSame(['debt' => [1_000_000.0, 1_000_000.0, 1_000_000.0, 1_000_000.0], 'revenue' => [2_000_000.0, 2_000_000.0, 2_000_000.0]], $this->figures($root));
        $this->assertSame('partial', $project->fresh()->payment_status);
        $this->actingAs($root)->get('/backend/project?debt=1')->assertViewHas('projects', fn ($p) => $p->pluck('id')->all() === [$project->id]);

        // Thu lần 2 thêm 1tr → hết công nợ
        $this->pay($project, '1.000.000', ['paid_at' => '2026-10-05']);
        $this->assertSame(['debt' => [0.0, 0.0, 0.0, 0.0], 'revenue' => [3_000_000.0, 3_000_000.0, 3_000_000.0]], $this->figures($root));
        $this->assertSame('paid', $project->fresh()->payment_status);
        $this->actingAs($root)->get('/backend/project?debt=1')->assertViewHas('projects', fn ($p) => $p->isEmpty());
    }

    public function test_case_2_extra_charge_is_debt_until_received(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['name' => 'shop.vn', 'price' => 3_000_000, 'status' => 'delivered']);
        $this->pay($project, '3.000.000');
        $this->assertSame('paid', $project->fresh()->payment_status);

        // Khách đặt thêm tính năng: ghi nợ phát sinh 2tr (phương thức gửi lên bị bỏ — chưa thu)
        $this->pay($project, '2.000.000', ['type' => 'extra', 'status' => 'unpaid', 'paid_at' => '2026-10-08', 'note' => 'Thêm trang đặt lịch'])
            ->assertSessionHas('success', 'Đã ghi phát sinh 2.000.000 (chưa thu, tính vào công nợ).');
        $charge = Payment::where('type', 'extra')->firstOrFail();
        $this->assertSame(['unpaid', null], [$charge->status, $charge->method]);

        // Công nợ 2tr ở mọi trang, doanh thu chưa đổi; dự án thành "một phần", nằm trong bộ lọc còn công nợ
        $this->assertSame(['debt' => [2_000_000.0, 2_000_000.0, 2_000_000.0, 2_000_000.0], 'revenue' => [3_000_000.0, 3_000_000.0, 3_000_000.0]], $this->figures($root));
        $this->assertSame('partial', $project->fresh()->payment_status);
        $this->assertSame(3_000_000.0, $project->fresh()->paidAmount());
        $this->assertSame(['debt' => [2_000_000.0], 'revenue' => [3_000_000.0]], [
            'debt' => [(float) $this->actingAs($root)->get('/backend/report/revenue')->viewData('debts')->first()['extra']],
            'revenue' => [(float) Customer::revenueMap()[$project->customer_id]],
        ]);

        $xp = $this->xpath($this->actingAs($root)->get('/backend/project?debt=1')->getContent());
        $this->assertSame('3.000.000 Còn nợ 2.000.000 gồm phát sinh 2.000.000', $this->text($xp->query("//tr[@data-row-key='{$project->id}']/td[@data-col='value']")->item(0)));

        // Trang thanh toán: không nằm trong tiền đã thu, có ở tab "Chưa thu" kèm nút "Đã thu"
        $this->actingAs($root)->get('/backend/payment')->assertViewHas('payments', fn ($p) => ! $p->contains('id', $charge->id))
            ->assertViewHas('summary', fn ($s) => $s['unpaid'] === ['count' => 1, 'total' => 2_000_000.0] && $s['all']['total'] === 3_000_000.0);
        $xp = $this->xpath($this->actingAs($root)->get('/backend/payment?status=unpaid')->getContent());
        $this->assertSame('Ngày phát sinh', $this->text($xp->query('//th[@data-col="date"]')->item(0)));
        $row = $xp->query("//tr[@data-row-key='{$charge->id}']")->item(0);
        $this->assertSame('Phát sinh Chưa thu', $this->text($xp->query('.//td[@data-col="type"]', $row)->item(0)));
        $receive = $xp->query('.//button[@data-payment-receive]', $row)->item(0);
        $this->assertSame('unpaid', json_decode($receive->getAttribute('data-payment'), true)['status']);

        // Trang dự án: tách nợ hợp đồng / phát sinh
        $xp = $this->xpath($this->actingAs($root)->get("/backend/project/{$project->id}")->getContent());
        $finance = fn (string $key) => implode(' ', array_map(fn ($n) => $this->text($n), iterator_to_array($xp->query("//*[@data-finance='{$key}']/span"))));
        $this->assertSame(['Còn nợ hợp đồng 0', 'Phát sinh chưa thu 2.000.000', 'Tổng công nợ 2.000.000'], [$finance('remaining'), $finance('unpaid-extra'), $finance('debt')]);
        $this->assertSame(1, $xp->query('//*[@data-card="payments"]//tr[@data-unpaid]//button[@data-payment-receive]')->length);
        $this->assertSame('Chưa thu 2.000.000', $this->text($xp->query('//tr[@data-unpaid-total]')->item(0)));

        // Khách trả: bấm "Đã thu", ngày thu hôm nay → vào doanh thu tháng này, hết nợ
        $this->actingAs($root)->put("/backend/payment/{$charge->id}", [
            'project_id' => $project->id, 'amount' => '2.000.000', 'type' => 'extra', 'status' => 'paid', 'paid_at' => '2026-10-10', 'method' => 'cash', 'note' => 'Thêm trang đặt lịch',
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Đã ghi nhận thu 2.000.000 ngày 10/10/2026.');
        $this->assertSame(['paid', 'cash', '2026-10-10'], [$charge->fresh()->status, $charge->fresh()->method, $charge->fresh()->paid_at->toDateString()]);
        $this->assertSame(['debt' => [0.0, 0.0, 0.0, 0.0], 'revenue' => [5_000_000.0, 5_000_000.0, 5_000_000.0]], $this->figures($root));
        $this->assertSame('paid', $project->fresh()->payment_status);

        // Lịch sử: thấy đổi trạng thái + ngày
        $log = ProjectAuditLog::where('auditable_type', Payment::class)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame(['unpaid', 'paid'], [$log->changes['old']['status'], $log->changes['new']['status']]);
        $this->actingAs($root)->get("/backend/project/{$project->id}")->assertSee('Chưa thu')->assertSeeInOrder(['Trạng thái:', 'Chưa thu', 'Đã thu']);
    }

    public function test_paid_extra_does_not_reduce_contract_debt(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => 5_000_000, 'status' => 'in_progress']);
        $this->pay($project, '1.000.000');
        $this->pay($project, '2.000.000', ['type' => 'extra', 'status' => 'paid']);
        $this->pay($project, '700.000', ['type' => 'extra', 'status' => 'unpaid']);

        // Nợ hợp đồng 4tr + phát sinh chưa thu 0,7tr; doanh thu 3tr (gồm phát sinh đã thu)
        $this->assertSame(['debt' => array_fill(0, 4, 4_700_000.0), 'revenue' => array_fill(0, 3, 3_000_000.0)], $this->figures($root));
        $this->assertSame(3_000_000.0, $this->actingAs($root)->get('/backend/report/revenue')->viewData('stats')['revenue_new']);
        $xp = $this->xpath($this->actingAs($root)->get('/backend/project')->getContent());
        $this->assertSame('5.000.000 Còn nợ 4.700.000 gồm phát sinh 700.000 +2.000.000 phát sinh', $this->text($xp->query("//tr[@data-row-key='{$project->id}']/td[@data-col='value']")->item(0)));
        $this->actingAs($root)->get('/backend/payment?type=extra')
            ->assertViewHas('payments', fn ($p) => $p->pluck('status')->all() === ['paid'])
            ->assertViewHas('summary', fn ($s) => $s['extra'] === ['count' => 1, 'total' => 2_000_000.0]);
    }

    public function test_only_extra_payments_can_be_unpaid(): void
    {
        $project = Project::factory()->create(['price' => 3_000_000]);
        $this->pay($project, '1.000.000', ['type' => 'new', 'status' => 'unpaid']);
        $this->pay($project, '500.000', ['type' => 'renewal', 'status' => 'unpaid']);
        $this->pay($project, '1', ['type' => 'extra', 'status' => 'xyz']);
        $this->assertSame(['paid', 'paid', 'paid'], Payment::orderBy('id')->pluck('status')->all());
        $this->assertSame(['bank_transfer', 'bank_transfer', 'bank_transfer'], Payment::orderBy('id')->pluck('method')->all());

        // Ghi thẳng bằng model cũng vậy
        $payment = Payment::factory()->create(['project_id' => $project->id, 'type' => 'renewal', 'status' => 'unpaid']);
        $this->assertSame('paid', $payment->status);

        // Lỗi validate của khoản chưa thu: nhãn "ngày phát sinh", hộp mở lại đúng trạng thái
        $this->actingAs($this->root())->from("/backend/project/{$project->id}")->post('/backend/payment', [
            'project_id' => $project->id, 'amount' => '1.000', 'type' => 'extra', 'status' => 'unpaid', 'paid_at' => '',
        ])->assertSessionHasErrorsIn('payment', ['paid_at' => 'Vui lòng nhập ngày phát sinh.']);
        $this->actingAs($this->root())->withSession(['_old_input' => ['type' => 'extra', 'status' => 'unpaid', 'amount' => '1.000'], 'payment_dialog' => 'new'])
            ->get("/backend/project/{$project->id}")
            ->assertSee('title="Ngày phát sinh" data-payment-date-label>Ngày phát sinh</label>', false)
            ->assertSee('value="unpaid" checked', false);
    }

    public function test_debt_filter_and_cancelled_projects(): void
    {
        $root = $this->root();
        $contract = Project::factory()->create(['name' => 'Nợ hợp đồng', 'price' => 2_000_000, 'status' => 'delivered']);
        $extraOnly = Project::factory()->create(['name' => 'Chỉ phát sinh', 'price' => 0, 'status' => 'maintenance']);
        $clean = Project::factory()->create(['name' => 'Đã thu đủ', 'price' => 1_000_000, 'status' => 'delivered']);
        $cancelled = Project::factory()->create(['name' => 'Đã hủy', 'price' => 9_000_000, 'status' => 'cancelled']);
        $this->pay($extraOnly, '400.000', ['type' => 'extra', 'status' => 'unpaid']);
        $this->pay($clean, '1.000.000');
        $this->pay($cancelled, '300.000', ['type' => 'extra', 'status' => 'unpaid']);

        $page = $this->actingAs($root)->get('/backend/project?debt=1');
        $this->assertEqualsCanonicalizing([$contract->id, $extraOnly->id], $page->viewData('projects')->pluck('id')->all());
        $this->assertSame(2, $page->viewData('statusCounts')['all']);
        $this->assertSame(['total' => 2_400_000.0, 'count' => 2], Project::debtSummary());

        $xp = $this->xpath($page->getContent());
        $this->assertSame(route('backend.projects.index'), $xp->query('//*[@data-metric="debt"]')->item(0)->getAttribute('href'));
        $this->assertStringContainsString('is-active', $xp->query('//*[@data-metric="debt"]')->item(0)->getAttribute('class'));
        $this->assertSame('Còn công nợ', $this->text($xp->query('//*[@data-filter-debt]')->item(0)));
        $this->assertSame(route('backend.projects.index'), $xp->query('//*[@data-filter-debt]//a')->item(0)->getAttribute('href'));
        // Kết hợp bộ lọc khác + giữ khi đổi tab
        parse_str((string) parse_url($xp->query('//a[@role="tab"][contains(., "Đã bàn giao")]')->item(0)->getAttribute('href'), PHP_URL_QUERY), $query);
        $this->assertEqualsCanonicalizing(['status' => 'delivered', 'debt' => '1'], $query);
        $this->actingAs($root)->get('/backend/project?debt=1&q=Chỉ')->assertViewHas('projects', fn ($p) => $p->pluck('id')->all() === [$extraOnly->id]);
        // Không bật: nút lọc dạng link
        $xp = $this->xpath($this->actingAs($root)->get('/backend/project')->getContent());
        $this->assertSame(route('backend.projects.index', ['debt' => 1]), $xp->query('//a[@data-filter-debt]')->item(0)->getAttribute('href'));
    }
}
