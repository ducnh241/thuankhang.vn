<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixCustomerCreatedAtTest extends TestCase
{
    use RefreshDatabase;

    private function createdAt(Customer $customer): string
    {
        return (string) DB::table('customers')->where('id', $customer->id)->value('created_at');
    }

    public function test_sets_created_at_to_first_project_start_for_imported_customers_only(): void
    {
        $imported = '2026-05-11 16:20:56';
        $withProjects = Customer::factory()->create(['created_at' => $imported, 'updated_at' => '2026-06-01 10:00:00']);
        Project::factory()->create(['customer_id' => $withProjects->id, 'start_date' => '2019-03-04']);
        Project::factory()->create(['customer_id' => $withProjects->id, 'start_date' => '2017-02-28'])->delete(); // dự án đã xóa vẫn là lịch sử
        Project::factory()->create(['customer_id' => $withProjects->id, 'start_date' => null]);
        $trashedCustomer = Customer::factory()->create(['created_at' => $imported]);
        Project::factory()->create(['customer_id' => $trashedCustomer->id, 'start_date' => '2021-07-01']);
        $trashedCustomer->delete();
        $noProject = Customer::factory()->create(['created_at' => $imported]);
        $noStart = Customer::factory()->create(['created_at' => $imported]);
        Project::factory()->create(['customer_id' => $noStart->id, 'start_date' => null]);
        $realNew = Customer::factory()->create(['created_at' => '2026-05-12 02:06:21']); // tạo thật sau ngày nhập
        Project::factory()->create(['customer_id' => $realNew->id, 'start_date' => '2020-01-01']);
        $auditBefore = ProjectAuditLog::count();

        // Chạy thử: không ghi
        $this->artisan('customers:fix-created-at', ['--dry-run' => true])
            ->expectsOutputToContain('Sẽ đổi ngày tạo 2/4 khách hàng có ngày tạo 11/05/2026')
            ->assertSuccessful();
        $this->assertSame($imported, $this->createdAt($withProjects));

        $this->artisan('customers:fix-created-at')
            ->expectsOutputToContain('Đã đổi ngày tạo 2/4 khách hàng')
            ->expectsOutputToContain('Giữ nguyên 2 khách không có dự án nào có ngày bắt đầu (đang dùng: #'.$noProject->id)
            ->assertSuccessful();

        $this->assertSame('2017-02-28 00:00:00', $this->createdAt($withProjects));
        $this->assertSame('2021-07-01 00:00:00', $this->createdAt($trashedCustomer));
        $this->assertSame($imported, $this->createdAt($noProject));
        $this->assertSame($imported, $this->createdAt($noStart));
        $this->assertSame('2026-05-12 02:06:21', $this->createdAt($realNew));
        // Không đổi updated_at, không ghi lịch sử thay đổi
        $this->assertSame('2026-06-01 10:00:00', (string) DB::table('customers')->where('id', $withProjects->id)->value('updated_at'));
        $this->assertSame($auditBefore, ProjectAuditLog::count());

        // Chạy lại: không còn khách nào mang ngày nhập có dự án → không đổi gì thêm
        $this->artisan('customers:fix-created-at')->expectsOutputToContain('Đã đổi ngày tạo 0/2 khách hàng')->assertSuccessful();
        $this->assertSame('2017-02-28 00:00:00', $this->createdAt($withProjects));

        // Danh sách khách hàng hiện ngày mới
        $this->actingAs(\App\Models\User::factory()->superAdmin()->create())->get('/backend/customer')->assertSee('>28/02/2017</td>', false);
    }

    public function test_rejects_bad_date_option(): void
    {
        $this->artisan('customers:fix-created-at', ['--date' => '11/05/2026'])->expectsOutput('--date phải có dạng Y-m-d.')->assertFailed();
    }
}
