<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PaymentManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('payment')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $payment = Payment::factory()->create();

        $this->actingAs($noAccess)->get('/backend/payment')->assertForbidden();
        $this->actingAs($viewer)->get('/backend/payment')->assertOk()->assertDontSee('Thêm khoản thu');
        $this->actingAs($viewer)->post('/backend/payment', ['project_id' => $payment->project_id, 'amount' => '1', 'type' => 'new', 'paid_at' => '2026-01-01'])->assertForbidden();
        $this->actingAs($viewer)->put("/backend/payment/{$payment->id}", ['amount' => '1'])->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/payment/{$payment->id}")->assertForbidden();
        $this->actingAs($viewer)->post('/backend/payment/bulk-destroy', ['ids' => [$payment->id]])->assertForbidden();
        $this->assertModelExists($payment);

        // Trang dự án: không có quyền thanh toán thì không thấy nút
        $projectViewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $this->actingAs($projectViewer)->get("/backend/project/{$payment->project_id}")->assertOk()->assertDontSee('id="payment-modal"', false);
    }

    public function test_add_edit_delete_updates_project_payment_status(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => 10_000_000]);
        $from = route('backend.projects.show', $project);

        // Thêm 4tr → thanh toán một phần
        $this->actingAs($root)->from($from)->post('/backend/payment', [
            'project_id' => $project->id, 'amount' => '4.000.000', 'type' => 'new', 'paid_at' => '2026-10-01', 'method' => 'bank_transfer', 'note' => 'Đặt cọc',
        ])->assertRedirect($from)->assertSessionHasNoErrors();
        $payment = Payment::firstOrFail();
        $this->assertSame('4000000.00', $payment->amount);
        $this->assertSame('partial', $project->fresh()->payment_status);

        // Sửa thành 10tr → đã thanh toán
        $this->actingAs($root)->from($from)->put("/backend/payment/{$payment->id}", [
            'project_id' => $project->id, 'amount' => '10.000.000', 'type' => 'new', 'paid_at' => '2026-10-01',
        ])->assertRedirect($from);
        $this->assertSame('paid', $project->fresh()->payment_status);

        // Xóa → chưa thanh toán
        $this->actingAs($root)->from($from)->delete("/backend/payment/{$payment->id}")->assertRedirect($from);
        $this->assertModelMissing($payment);
        $this->assertSame('unpaid', $project->fresh()->payment_status);

        // Lịch sử ghi đủ 3 thao tác của khoản thu, gắn đúng dự án
        $this->assertSame(['created', 'updated', 'deleted'], ProjectAuditLog::where('auditable_type', Payment::class)->where('project_id', $project->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_moving_payment_to_another_project_recalculates_both(): void
    {
        $root = $this->root();
        $a = Project::factory()->create(['price' => 1_000_000]);
        $b = Project::factory()->create(['price' => 1_000_000]);
        $payment = Payment::factory()->create(['project_id' => $a->id, 'amount' => 1_000_000]);
        $this->assertSame('paid', $a->fresh()->payment_status);

        $this->actingAs($root)->put("/backend/payment/{$payment->id}", ['project_id' => $b->id, 'amount' => '1.000.000', 'type' => 'new', 'paid_at' => '2026-10-01']);

        $this->assertSame('unpaid', $a->fresh()->payment_status);
        $this->assertSame('paid', $b->fresh()->payment_status);
    }

    public function test_validation_uses_payment_bag_and_reopens_dialog(): void
    {
        $root = $this->root();
        $project = Project::factory()->create();
        $from = route('backend.projects.show', $project);

        $this->actingAs($root)->from($from)->post('/backend/payment', ['project_id' => $project->id, 'amount' => 'abc', 'type' => 'xyz', 'paid_at' => ''])
            ->assertRedirect($from)
            ->assertSessionHasErrorsIn('payment', ['amount', 'type', 'paid_at'])
            ->assertSessionHas('payment_dialog', 'new');

        $this->actingAs($root)->post('/backend/payment', ['project_id' => $project->id, 'amount' => '0', 'type' => 'new', 'paid_at' => '2026-01-01'])
            ->assertSessionHasErrorsIn('payment', ['amount']);

        $deleted = Project::factory()->create();
        $deleted->delete();
        $this->actingAs($root)->post('/backend/payment', ['project_id' => $deleted->id, 'amount' => '1', 'type' => 'new', 'paid_at' => '2026-01-01'])
            ->assertSessionHasErrorsIn('payment', ['project_id']);
    }

    public function test_index_filters_and_totals(): void
    {
        $root = $this->root();
        $alpha = Project::factory()->create(['name' => 'Alpha Shop']);
        $beta = Project::factory()->create(['name' => 'Beta Blog']);
        Payment::factory()->create(['project_id' => $alpha->id, 'amount' => 1_000_000, 'type' => 'new', 'paid_at' => '2026-01-15', 'method' => 'cash']);
        Payment::factory()->create(['project_id' => $alpha->id, 'amount' => 500_000, 'type' => 'renewal', 'paid_at' => '2026-03-10']);
        Payment::factory()->create(['project_id' => $beta->id, 'amount' => 2_000_000, 'type' => 'new', 'paid_at' => '2025-12-31']);
        $trashed = Project::factory()->create(['name' => 'Đã Xóa']);
        Payment::factory()->create(['project_id' => $trashed->id, 'amount' => 9_000_000]);
        $trashed->delete();

        $this->actingAs($root)->get('/backend/payment')->assertOk()
            ->assertSee('3 · 3.500.000')->assertDontSee('Đã Xóa');  // dự án đã xóa: không trong bảng lẫn ô chọn
        $this->actingAs($root)->get('/backend/payment?type=renewal')->assertSee('Alpha Shop</a>', false)->assertDontSee('Beta Blog</a>', false);
        $this->actingAs($root)->get('/backend/payment?q=beta')->assertSee('Beta Blog</a>', false)->assertDontSee('Alpha Shop</a>', false);
        $this->actingAs($root)->get('/backend/payment?method=cash')->assertSee('1 · 1.000.000');
        $this->actingAs($root)->get('/backend/payment?from=2026-01-01&to=2026-01-31')->assertSee('1 · 1.000.000')->assertDontSee('Beta Blog</a>', false);
        $this->actingAs($root)->get('/backend/payment?from=khong-hop-le&type=xyz&method=xyz')->assertOk()->assertSee('3 · 3.500.000');
    }

    public function test_bulk_delete_recalculates(): void
    {
        $root = $this->root();
        $project = Project::factory()->create(['price' => 1_000_000]);
        $payments = Payment::factory()->count(2)->create(['project_id' => $project->id, 'amount' => 500_000]);
        $this->assertSame('paid', $project->fresh()->payment_status);

        $this->actingAs($root)->post('/backend/payment/bulk-destroy', ['ids' => $payments->pluck('id')->all()])->assertSessionHas('success', 'Đã xóa 2 khoản thu.');
        $this->assertSame('unpaid', $project->fresh()->payment_status);
    }

    public function test_dialog_renders_with_decimal_amount_for_edit(): void
    {
        $project = Project::factory()->create();
        $payment = Payment::factory()->create(['project_id' => $project->id, 'amount' => '2499999.99']);

        $this->actingAs($this->root())->get("/backend/project/{$project->id}")
            ->assertOk()->assertSee('id="payment-modal"', false)->assertSee('2.499.999,99')
            ->assertSee('&quot;amount&quot;:&quot;2499999.99&quot;', false);
    }

    public function test_history_shows_dates_in_vietnamese_format(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($this->root())->post('/backend/payment', ['project_id' => $project->id, 'amount' => '1.000.000', 'type' => 'new', 'paid_at' => '2026-10-05']);

        $this->actingAs($this->root())->get("/backend/project/{$project->id}")->assertSee('1.000.000 · Thu mới · 05/10/2026');
    }

    public function test_validation_messages_are_vietnamese(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->root())->post('/backend/payment', ['project_id' => $project->id, 'amount' => 'abc', 'type' => 'new', 'paid_at' => ''])
            ->assertSessionHasErrorsIn('payment', ['amount' => 'Số tiền phải là số.', 'paid_at' => 'Vui lòng nhập ngày thu.']);
    }

    // ---- Trường hợp biên ----

    public function test_payment_only_user_sees_project_names_without_links(): void
    {
        $user = User::factory()->create(['group_id' => Group::factory()->withPermissions('payment', 'payment/edit')->create()->id]);
        $payment = Payment::factory()->create();

        $this->actingAs($user)->get('/backend/payment')->assertOk()
            ->assertSee($payment->project->name)->assertDontSee(route('backend.projects.show', $payment->project_id));
    }

    public function test_failed_edit_reopens_edit_dialog_not_create(): void
    {
        $root = $this->root();
        $payment = Payment::factory()->create();
        $from = route('backend.payments.index');

        $this->actingAs($root)->from($from)->put("/backend/payment/{$payment->id}", ['project_id' => $payment->project_id, 'amount' => '', 'type' => 'new', 'paid_at' => '2026-01-01'])
            ->assertSessionHas('payment_dialog', $payment->id);

        $this->actingAs($root)->get($from)->assertSee('data-reopen="'.$payment->id.'"', false);
        $this->assertSame('1000000.00', $payment->fresh()->amount, 'Sửa lỗi thì không đổi dữ liệu');
    }

    public function test_redirect_back_keeps_payment_filters(): void
    {
        $root = $this->root();
        $payment = Payment::factory()->create();
        $from = route('backend.payments.index', ['type' => 'new', 'from' => '2026-01-01']);

        $this->actingAs($root)->from($from)->delete("/backend/payment/{$payment->id}")->assertRedirect($from);
    }

    public function test_bulk_delete_ignores_garbage_ids(): void
    {
        $payment = Payment::factory()->create();

        $this->actingAs($this->root())->post('/backend/payment/bulk-destroy', ['ids' => ['abc', '1 OR 1=1', -5, 99999]])
            ->assertSessionHas('error', 'Đã xóa 0 khoản thu.');
        $this->assertModelExists($payment);
    }

    public function test_amount_limits_and_decimals(): void
    {
        $root = $this->root();
        $project = Project::factory()->create();
        $post = fn ($amount) => $this->actingAs($root)->post('/backend/payment', ['project_id' => $project->id, 'amount' => $amount, 'type' => 'new', 'paid_at' => '2026-01-01']);

        $post('1.234,5')->assertSessionHasNoErrors();
        $this->assertSame('1234.50', Payment::latest('id')->first()->amount);
        $post('-100')->assertSessionHasErrorsIn('payment', ['amount']);
        $post('99.999.999.999.999')->assertSessionHasErrorsIn('payment', ['amount']);
        $post(['1'])->assertSessionHasErrorsIn('payment', ['amount']);
    }

    public function test_paid_at_too_far_in_future_rejected(): void
    {
        $project = Project::factory()->create();

        $this->actingAs($this->root())->post('/backend/payment', ['project_id' => $project->id, 'amount' => '1', 'type' => 'new', 'paid_at' => now()->addYears(2)->toDateString()])
            ->assertSessionHasErrorsIn('payment', ['paid_at']);
    }

    // ---- Hồi quy từ đợt soát code ----

    public function test_reopened_dialog_shows_formatted_decimal_amount(): void
    {
        $root = $this->root();
        $from = route('backend.payments.index');

        // Nhập đúng số tiền lẻ nhưng quên chọn dự án
        $this->actingAs($root)->from($from)->post('/backend/payment', ['project_id' => '', 'amount' => '2.499.999,99', 'type' => 'new', 'paid_at' => '2026-01-01']);

        $this->actingAs($root)->get($from)->assertSee('value="2.499.999,99"', false);
    }

    public function test_moving_payment_is_logged_on_both_projects(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();
        $payment = Payment::factory()->create(['project_id' => $a->id]);

        $this->actingAs($this->root())->put("/backend/payment/{$payment->id}", ['project_id' => $b->id, 'amount' => '1.000.000', 'type' => 'new', 'paid_at' => '2026-10-01']);

        foreach ([$a, $b] as $project) {
            $this->assertTrue(ProjectAuditLog::where('project_id', $project->id)->where('auditable_type', Payment::class)->where('action', 'updated')->exists());
        }
    }

    public function test_deleting_payment_of_trashed_project_recalculates_status(): void
    {
        $project = Project::factory()->create(['price' => 1_000_000]);
        $payment = Payment::factory()->create(['project_id' => $project->id, 'amount' => 1_000_000]);
        $project->delete();

        $this->actingAs($this->root())->delete("/backend/payment/{$payment->id}");

        $this->assertSame('unpaid', Project::withTrashed()->find($project->id)->payment_status);
    }

    // ---- Giao diện Ant Design ----

    public function test_add_dialog_on_payments_page_lets_choose_project(): void
    {
        // Hồi quy: biến $project của vòng lặp trong bảng từng lọt vào hộp thoại → khóa cứng vào dự án của dòng cuối
        $payment = Payment::factory()->create();
        $html = $this->actingAs($this->root())->get('/backend/payment')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<select data-antd data-show-search id="payment_project" name="project_id"#', $html);
        $this->assertStringNotContainsString('<input type="hidden" name="project_id"', $html);
        // Trang dự án: hộp thoại cố định đúng dự án
        $this->actingAs($this->root())->get("/backend/project/{$payment->project_id}")
            ->assertSee('<input type="hidden" name="project_id" value="'.$payment->project_id.'" />', false)->assertDontSee('id="payment_project"', false);
    }

    public function test_metrics_tabs_and_filters_markup(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');
        $root = $this->root();
        $p = Project::factory()->create(['price' => 10_000_000]);
        Payment::factory()->create(['project_id' => $p->id, 'amount' => 1_000_000, 'paid_at' => '2026-10-02']);
        Payment::factory()->create(['project_id' => $p->id, 'amount' => 500_000, 'paid_at' => '2026-10-05', 'type' => 'renewal', 'method' => 'cash']);
        Payment::factory()->create(['project_id' => $p->id, 'amount' => 2_000_000, 'paid_at' => '2026-09-20']);
        Payment::factory()->create(['project_id' => $p->id, 'amount' => 4_000_000, 'paid_at' => '2025-12-31']);

        $page = $this->actingAs($root)->get('/backend/payment')->assertOk();
        // Chỉ số: tháng 10 (1–10/10), cả tháng 9, năm 2026, còn phải thu (10tr − 7tr thu mới; tiền gia hạn không trừ nợ hợp đồng)
        $page->assertSeeInOrder(['Tháng 10', '1.500.000', '2 khoản · gia hạn 500.000'])
            ->assertSeeInOrder(['Tháng 9', '2.000.000', '1 khoản'])
            ->assertSeeInOrder(['Năm 2026', '3.500.000', '3 khoản · gia hạn 500.000'])
            ->assertSeeInOrder(['Còn phải thu', '3.000.000', '1 dự án còn công nợ']);
        // Bấm chỉ số = lọc khoảng ngày đó; đang lọc đúng khoảng thì sáng lên, bấm lại bỏ lọc
        $page->assertSee('href="'.e(route('backend.payments.index', ['from' => '2026-10-01', 'to' => '2026-10-10'])).'" class="product-metric"', false);
        $this->actingAs($root)->get('/backend/payment?from=2026-09-01&to=2026-09-30')
            ->assertSee('href="'.route('backend.payments.index').'" class="product-metric is-active"', false)
            ->assertSee('Đã thu <span class="product-view-count">1 · 2.000.000</span>', false);
        // Khoảng chọn nhanh dưới lịch
        $page->assertSee('{&quot;label&quot;:&quot;Năm trước&quot;,&quot;from&quot;:&quot;2025-01-01&quot;,&quot;to&quot;:&quot;2025-12-31&quot;}', false);
        // Lọc phương thức: nút pill + danh sách chọn giữ bộ lọc khác
        $this->actingAs($root)->get('/backend/payment?method=cash&type=renewal')->assertSee('Phương thức: Tiền mặt')
            ->assertSee('href="'.route('backend.payments.index', ['type' => 'renewal']).'" class="filter-pill-clear"', false)
            ->assertSee('Gia hạn <span class="product-view-count">1 · 500.000</span>', false);
        Carbon::setTestNow();
    }

    public function test_page_beyond_last_and_bulk_delete_goes_back_to_filtered_list(): void
    {
        $root = $this->root();
        $p = Project::factory()->create();
        Payment::factory()->count(35)->create(['project_id' => $p->id]);
        $this->actingAs($root)->get('/backend/payment?type=new&page=9')->assertRedirect(route('backend.payments.index', ['type' => 'new', 'page' => 2]));

        $ids = Payment::latest('id')->limit(3)->pluck('id')->all();
        $from = route('backend.payments.index', ['type' => 'new', 'page' => 2]);
        $this->actingAs($root)->from($from)->post('/backend/payment/bulk-destroy', ['ids' => $ids])->assertRedirect($from);
        $this->assertSame(32, Payment::count());
    }

    public function test_reversed_or_invalid_date_range_in_url(): void
    {
        $root = $this->root();
        $p = Project::factory()->create();
        Payment::factory()->create(['project_id' => $p->id, 'amount' => 1_000_000, 'paid_at' => '2026-09-10']);
        Payment::factory()->create(['project_id' => $p->id, 'amount' => 3_000_000, 'paid_at' => '2026-08-10']);

        // "từ" sau "đến" (gõ tay URL) → đổi chỗ, không ra danh sách trống
        $this->actingAs($root)->get('/backend/payment?from=2026-09-30&to=2026-09-01')->assertOk()
            ->assertSee('Đã thu <span class="product-view-count">1 · 1.000.000</span>', false)
            ->assertSee('value="2026-09-01"', false);
        // Ngày sai bị bỏ qua, ngày còn lại vẫn lọc
        $this->actingAs($root)->get('/backend/payment?from=2026-02-30&to=2026-08-31')->assertOk()
            ->assertSee('Đã thu <span class="product-view-count">1 · 3.000.000</span>', false);
    }
}
