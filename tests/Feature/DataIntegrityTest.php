<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Payment;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Kịch bản CRUD xuyên suốt: mọi bước không được làm mất khoản thu, mất khách hàng hay sai trạng thái thanh toán.
 * Bất biến kiểm sau từng bước: doanh thu báo cáo == tổng khoản thu của dự án chưa xóa trong DB.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-10 10:00:00');
        $this->root = User::factory()->superAdmin()->create();
    }

    /** Doanh thu "Năm nay" trên báo cáo phải bằng tổng payments của dự án chưa xóa. */
    private function assertRevenueConsistent(string $step): void
    {
        $expected = (float) DB::table('payments')->join('projects', 'projects.id', '=', 'payments.project_id')
            ->whereNull('projects.deleted_at')->sum('payments.amount');
        $report = $this->actingAs($this->root)->get('/backend/report/revenue?period=this_year')->assertOk()->viewData('stats')['revenue'];
        $this->assertSame($expected, $report, "Doanh thu báo cáo lệch sau bước: {$step}");
        $this->assertSame($expected, (float) $this->actingAs($this->root)->get('/backend/payment')->viewData('summary')['all']['total'], "Tổng trang Thanh toán lệch sau bước: {$step}");
    }

    private function project(array $attrs): array
    {
        $this->actingAs($this->root)->post('/backend/project', ['status' => 'in_progress', 'currency' => 'VND', ...$attrs])->assertSessionHasNoErrors();

        return [Project::latest('id')->first(), null];
    }

    public function test_full_lifecycle_keeps_revenue_and_customers_intact(): void
    {
        // 1. Khách hàng + dự án 10tr
        $this->actingAs($this->root)->post('/backend/customer', ['name' => 'Khách A', 'phone' => '0900000001'])->assertSessionHasNoErrors();
        $customer = Customer::where('name', 'Khách A')->sole();
        [$project] = $this->project(['name' => 'Website A', 'customer_id' => $customer->id, 'price' => '10.000.000', 'start_date' => '2026-10-01']);
        $this->assertSame('unpaid', $project->payment_status);

        // 2. Thu 4tr → một phần; thêm 6tr → đủ
        $this->actingAs($this->root)->post('/backend/payment', ['project_id' => $project->id, 'amount' => '4.000.000', 'type' => 'new', 'paid_at' => '2026-10-02'])->assertSessionHasNoErrors();
        $this->assertSame('partial', $project->fresh()->payment_status);
        $this->actingAs($this->root)->post('/backend/payment', ['project_id' => $project->id, 'amount' => '6.000.000', 'type' => 'new', 'paid_at' => '2026-10-03'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $project->fresh()->payment_status);
        $this->assertRevenueConsistent('thêm 2 khoản thu');

        // 3. Sửa giá dự án lên 12tr → trạng thái tự tính lại thành một phần; khoản thu không đổi
        $this->actingAs($this->root)->put("/backend/project/{$project->id}", ['name' => 'Website A', 'customer_id' => $customer->id, 'status' => 'delivered', 'price' => '12.000.000'])->assertSessionHasNoErrors();
        $this->assertSame('partial', $project->fresh()->payment_status);
        $this->assertSame(2, $project->payments()->count());
        $this->assertRevenueConsistent('sửa giá dự án');

        // 4. Sửa số tiền một khoản (6tr → 8tr) → đủ
        $p2 = $project->payments()->where('amount', 6_000_000)->sole();
        $this->actingAs($this->root)->put("/backend/payment/{$p2->id}", ['project_id' => $project->id, 'amount' => '8.000.000', 'type' => 'new', 'paid_at' => '2026-10-03'])->assertSessionHasNoErrors();
        $this->assertSame('paid', $project->fresh()->payment_status);
        $this->assertRevenueConsistent('sửa số tiền khoản thu');

        // 5. Chuyển khoản 8tr sang dự án B (3tr): A một phần, B đủ (trả dư); lịch sử ghi ở cả 2 dự án
        [$projectB] = $this->project(['name' => 'Website B', 'customer_id' => $customer->id, 'price' => '3.000.000']);
        $this->actingAs($this->root)->put("/backend/payment/{$p2->id}", ['project_id' => $projectB->id, 'amount' => '8.000.000', 'type' => 'new', 'paid_at' => '2026-10-03'])->assertSessionHasNoErrors();
        $this->assertSame('partial', $project->fresh()->payment_status);
        $this->assertSame('paid', $projectB->fresh()->payment_status);
        $moved = fn (int $projectId) => ProjectAuditLog::where('project_id', $projectId)->where('auditable_id', $p2->id)->where('action', 'updated')->get()
            ->filter(fn ($log) => ($log->changes['new']['project_id'] ?? null) === $projectB->id)->count();
        $this->assertSame(1, $moved($project->id), 'dự án cũ phải thấy khoản thu bị chuyển đi');
        $this->assertSame(1, $moved($projectB->id), 'dự án mới phải thấy khoản thu chuyển đến');
        $this->assertRevenueConsistent('chuyển khoản thu sang dự án khác');

        // 6. Không chuyển được sang dự án đã xóa; không xóa được khách còn dự án; không xóa được dự án còn khoản thu
        $gone = Project::factory()->create(['customer_id' => $customer->id]);
        $gone->delete();
        $this->actingAs($this->root)->put("/backend/payment/{$p2->id}", ['project_id' => $gone->id, 'amount' => '8.000.000', 'type' => 'new', 'paid_at' => '2026-10-03'])->assertSessionHasErrorsIn('payment', 'project_id');
        $this->assertSame($projectB->id, $p2->fresh()->project_id);

        $this->actingAs($this->root)->delete("/backend/customer/{$customer->id}")->assertSessionHas('error');
        $this->assertNull($customer->fresh()->deleted_at);

        $this->actingAs($this->root)->from("/backend/project/{$project->id}")->delete("/backend/project/{$project->id}")
            ->assertRedirect("/backend/project/{$project->id}")->assertSessionHas('error');
        $this->assertNull($project->fresh()->deleted_at);
        $this->actingAs($this->root)->delete("/backend/project/{$projectB->id}")->assertSessionHas('error');
        $this->assertSame(2, Project::count() - 0);   // A, B còn nguyên (gone đã xóa từ trước)
        $this->assertRevenueConsistent('các thao tác bị chặn');

        // Nút xóa (chỉ ở trang dự án) bị vô hiệu khi đã có khoản thu; danh sách không có nút xóa
        $this->actingAs($this->root)->get('/backend/project')->assertDontSee('data-delete-url', false);
        $this->actingAs($this->root)->get("/backend/project/{$project->id}")
            ->assertDontSee('data-delete-url="'.route('backend.projects.destroy', $project).'"', false)
            ->assertSee('Đã có 1 khoản thu, không xóa được');

        // 7. Xóa khoản thu của B → B chưa thanh toán → xóa được B → khách vẫn còn A nên vẫn không xóa được khách
        $this->actingAs($this->root)->delete("/backend/payment/{$p2->id}")->assertSessionHasNoErrors();
        $this->assertSame('unpaid', $projectB->fresh()->payment_status);
        $this->assertRevenueConsistent('xóa khoản thu');
        $this->actingAs($this->root)->delete("/backend/project/{$projectB->id}")->assertSessionHas('success');
        $this->assertNotNull($projectB->fresh()->deleted_at);
        $this->actingAs($this->root)->delete("/backend/customer/{$customer->id}")->assertSessionHas('error');

        // 8. Tổng kết: khách còn, A còn 1 khoản 4tr, lịch sử đầy đủ
        $this->assertNull($customer->fresh()->deleted_at);
        $this->assertSame(4_000_000.0, $project->fresh()->paidAmount());
        $this->assertSame(1, Payment::count());
        $this->assertTrue(ProjectAuditLog::where('project_id', $projectB->id)->where('action', 'deleted')->exists());
        $this->assertRevenueConsistent('kết thúc');
    }

    public function test_decimal_amounts_survive_every_round_trip(): void
    {
        $project = Project::factory()->create(['price' => 2_499_999.99]);
        $this->actingAs($this->root)->post('/backend/payment', ['project_id' => $project->id, 'amount' => '2.499.999,99', 'type' => 'new', 'paid_at' => '2026-10-02'])->assertSessionHasNoErrors();
        $payment = Payment::sole();
        $this->assertSame('2499999.99', $payment->amount);
        $this->assertSame('paid', $project->fresh()->payment_status);

        // Mở form sửa: hiện lại đúng số lẻ; lưu không sửa gì → số không đổi, không sinh lịch sử
        $logs = ProjectAuditLog::count();
        $this->actingAs($this->root)->get("/backend/project/{$project->id}")->assertSee('2.499.999,99');
        $this->actingAs($this->root)->put("/backend/payment/{$payment->id}", ['project_id' => $project->id, 'amount' => '2.499.999,99', 'type' => 'new', 'paid_at' => '2026-10-02'])->assertSessionHasNoErrors();
        $this->assertSame('2499999.99', $payment->fresh()->amount);
        $this->assertSame($logs, ProjectAuditLog::count());

        // Nhập kiểu "12,500,000" và "12500000" → cùng một số
        foreach (['12,500,000', '12500000', '12.500.000'] as $input) {
            $this->actingAs($this->root)->put("/backend/payment/{$payment->id}", ['project_id' => $project->id, 'amount' => $input, 'type' => 'new', 'paid_at' => '2026-10-02'])->assertSessionHasNoErrors();
            $this->assertSame('12500000.00', $payment->fresh()->amount, "nhập {$input}");
        }
    }

    public function test_hosting_tag_category_deletion_never_touches_projects_or_payments(): void
    {
        $project = Project::factory()->create(['price' => 1_000_000]);
        Payment::factory()->create(['project_id' => $project->id, 'amount' => 1_000_000, 'paid_at' => '2026-10-01']);
        $tag = \App\Models\Tag::factory()->create();
        $hosting = \App\Models\Hosting::factory()->create();
        $project->tags()->attach($tag);
        $project->hostings()->attach($hosting);
        $category = \App\Models\Category::factory()->create(['type' => 1]);
        $project->update(['cat_id' => $category->id]);

        // Tag / hosting / danh mục đang gắn với dự án đều không xóa được
        $this->actingAs($this->root)->delete("/backend/tag/{$tag->id}")->assertSessionHas('error');
        $this->actingAs($this->root)->delete("/backend/hosting/{$hosting->id}")->assertSessionHas('error');
        $this->actingAs($this->root)->delete("/backend/category/project/{$category->id}")->assertSessionHas('error');

        $project->refresh();
        $this->assertNull($project->deleted_at);
        $this->assertSame($category->id, $project->cat_id);
        $this->assertSame(1, $project->hostings()->count());
        $this->assertSame(1, $project->tags()->count());
        $this->assertSame(1_000_000.0, $project->paidAmount());
        $this->assertSame('paid', $project->payment_status);
    }

    /**
     * Trang có ràng buộc không cho xóa: không còn cột checkbox / xóa hàng loạt, cột đầu sát lề trái.
     * Khoản thu, Tag xóa thoải mái nên giữ chọn nhiều.
     */
    public function test_bulk_select_only_on_lists_without_delete_constraints(): void
    {
        $project = Project::factory()->create();
        Payment::factory()->create(['project_id' => $project->id]);
        $hosting = \App\Models\Hosting::factory()->create();
        $tag = \App\Models\Tag::factory()->create();
        $category = \App\Models\Category::factory()->create();
        $group = \App\Models\Group::factory()->create();
        $user = User::factory()->create(['group_id' => $group->id]);
        $customer = Customer::factory()->create();

        // Danh mục (cây + form, theo categories.html): không có chọn nhiều
        foreach (['/backend/category?type=project', '/backend/category?type=news'] as $url) {
            $this->actingAs($this->root)->get($url)->assertOk()
                ->assertDontSee('data-row-check', false)->assertDontSee('data-table-select', false)->assertDontSee('data-bulk', false)
                ->assertSee('id="category-tree"', false);
        }
        // Nhóm quyền (giao diện Ant Design): danh sách nhóm + form, không có chọn nhiều
        $this->actingAs($this->root)->get('/backend/groups')->assertOk()->assertSee('id="group-list"', false)
            ->assertDontSee('data-row-check', false)->assertDontSee('data-bulk', false)->assertDontSee('name="ids[]"', false);
        // Danh sách dự án, khách hàng, người dùng (giao diện Ant Design): bảng có, không có cột chọn / thanh thao tác hàng loạt
        foreach (['/backend/project', '/backend/customer', '/backend/users', '/backend/hosting'] as $url) {
            $this->actingAs($this->root)->get($url)->assertOk()
                ->assertSee('<thead class="ant-table-thead">', false)
                ->assertDontSee('ant-table-selection-column', false)->assertDontSee('data-bulk', false)->assertDontSee('name="ids[]"', false);
        }
        // Tag (giao diện Ant Design): chọn cả trang + chọn từng dòng, xóa hàng loạt
        $this->actingAs($this->root)->get('/backend/tag')->assertOk()->assertSee('data-bulk-form', false)
            ->assertSee('data-select-page', false)->assertSee('data-row-check', false)->assertSee('data-bulk-delete', false);
        // Thanh toán (giao diện Ant Design): chọn cả trang + chọn từng dòng, xóa hàng loạt
        $this->actingAs($this->root)->get('/backend/payment')->assertOk()->assertSee('data-bulk-form', false)
            ->assertSee('data-select-page', false)->assertSee('data-row-check', false)->assertSee('data-bulk-delete', false);

        // Endpoint xóa hàng loạt đã gỡ: không còn xóa được gì qua đường này
        $targets = ['project' => $project, 'customer' => $customer, 'hosting' => $hosting, 'category/project' => $category, 'groups' => $group, 'users' => $user];
        foreach ($targets as $path => $model) {
            $status = $this->actingAs($this->root)->post("/backend/{$path}/bulk-destroy", ['ids' => [$model->id]])->status();
            $this->assertContains($status, [404, 405], "/backend/{$path}/bulk-destroy");
            $this->assertNotNull($model->fresh(), $path);
            $this->assertFalse(method_exists($model->fresh(), 'trashed') && $model->fresh()->trashed(), $path);
        }
    }
}
