<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Hosting;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HostingManagementTest extends TestCase
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

    private function valid(array $overrides = []): array
    {
        return [
            'name' => 'Contabo VPS 9', 'ownership' => 'mine', 'provider' => 'Contabo', 'plan_name' => 'Cloud VPS 20', 'ip' => '1.2.3.4',
            'cpu' => '6', 'ram_gb' => '12', 'disk_gb' => '200', 'register_date' => '2026-01-01', 'expire_date' => '2027-01-01',
            'alert_days' => '30,15,7,1', 'is_active' => '1', 'note' => '', ...$overrides,
        ];
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('hosting')->create()->id]);
        $adder = User::factory()->create(['group_id' => Group::factory()->withPermissions('hosting', 'hosting/add')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $hosting = Hosting::factory()->create();

        $this->actingAs($noAccess)->get('/backend/hosting')->assertForbidden();
        $this->actingAs($viewer)->get('/backend/hosting')->assertOk()->assertSee($hosting->name)
            ->assertDontSee('Thêm hosting')->assertDontSee(route('backend.hostings.edit', $hosting))->assertDontSee('data-check-all aria-label', false);
        $this->actingAs($viewer)->get('/backend/hosting/create')->assertForbidden();
        $this->actingAs($viewer)->post('/backend/hosting', $this->valid())->assertForbidden();
        $this->actingAs($viewer)->get("/backend/hosting/{$hosting->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->put("/backend/hosting/{$hosting->id}", $this->valid())->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/hosting/{$hosting->id}")->assertForbidden();
        $this->assertModelExists($hosting);

        // Chỉ có quyền thêm: tạo xong về danh sách thay vì 403 ở trang sửa
        $this->actingAs($adder)->post('/backend/hosting', $this->valid())->assertRedirect(route('backend.hostings.index'));
    }

    public function test_create_normalizes_input_and_attaches_projects(): void
    {
        $root = $this->root();
        [$a, $b] = Project::factory()->count(2)->create();

        $this->actingAs($root)->post('/backend/hosting', $this->valid([
            'ram_gb' => '1,5', 'alert_days' => '7, 30,7', 'project_ids' => [$a->id, $b->id],
        ]))->assertSessionHasNoErrors();

        $hosting = Hosting::sole();
        $this->assertSame(['cpu' => 6, 'ram_gb' => 1.5, 'disk_gb' => 200], $hosting->specs);
        $this->assertSame('30,7', $hosting->alert_days);
        $this->assertTrue($hosting->is_active);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $hosting->projects->pluck('id')->all());
        $this->assertSame('6 CPU · 1,5 GB RAM · 200 GB', $hosting->specLabel());
        $this->assertTrue(ProjectAuditLog::where('auditable_type', Hosting::class)->where('action', 'created')->exists());

        // Mở từ trang dự án: chọn sẵn dự án
        $this->actingAs($root)->get("/backend/hosting/create?project_id={$a->id}")->assertOk()
            ->assertSee('name="project_ids[]" value="'.$a->id.'" checked', false)->assertDontSee('name="project_ids[]" value="'.$b->id.'" checked', false);
    }

    public function test_number_and_date_input_formats(): void
    {
        $root = $this->root();
        $cases = ['1.000' => 1000.0, '2.048,5' => 2048.5, '1.5' => 1.5, '1,5' => 1.5, '' => 0.0];
        foreach ($cases as $input => $expected) {
            $this->actingAs($root)->post('/backend/hosting', $this->valid(['name' => "Disk [{$input}]", 'disk_gb' => $input]))->assertSessionHasNoErrors();
            $this->assertSame($expected, (float) Hosting::where('name', "Disk [{$input}]")->sole()->specs['disk_gb'], "disk_gb = {$input}");
        }

        // Bỏ trống ngày đăng ký vẫn nhập được ngày hết hạn; bỏ trống mốc nhắc → không nhắc
        $this->actingAs($root)->post('/backend/hosting', $this->valid(['name' => 'Không ngày ĐK', 'register_date' => '', 'alert_days' => '']))->assertSessionHasNoErrors();
        $hosting = Hosting::where('name', 'Không ngày ĐK')->sole();
        $this->assertNull($hosting->register_date);
        $this->assertSame('2027-01-01', $hosting->expire_date->toDateString());
        $this->assertSame('', $hosting->alert_days);

        // Form sửa hiện lại số dễ đọc: 1000 / 1.5 (không còn ".0")
        $this->actingAs($root)->get('/backend/hosting/'.Hosting::where('name', 'Disk [1.000]')->value('id').'/edit')->assertSee('value="1000"', false)->assertSee('value="12"', false);
    }

    public function test_validation(): void
    {
        $root = $this->root();
        $deleted = Project::factory()->create();
        $deleted->delete();

        $this->actingAs($root)->post('/backend/hosting', $this->valid([
            'name' => '', 'ownership' => 'hacker', 'cpu' => '-1', 'ram_gb' => 'abc', 'expire_date' => '2025-01-01',
            'alert_days' => '30;15', 'is_active' => 'maybe', 'project_ids' => [999, $deleted->id],
        ]))->assertSessionHasErrors(['name', 'ownership', 'cpu', 'ram_gb', 'expire_date', 'alert_days', 'is_active', 'project_ids.0', 'project_ids.1']);
        $this->assertSame(0, Hosting::count());

        // Lỗi validate: bỏ chọn hết dự án vẫn giữ là "không chọn" khi form hiện lại
        $hosting = Hosting::factory()->create();
        $project = Project::factory()->create();
        $hosting->projects()->attach($project);
        $this->actingAs($root)->from("/backend/hosting/{$hosting->id}/edit")->put("/backend/hosting/{$hosting->id}", $this->valid(['name' => '']));
        $this->actingAs($root)->get("/backend/hosting/{$hosting->id}/edit")
            ->assertDontSee('value="'.$project->id.'" class="size-4 shrink-0 rounded-[4px] accent-primary" checked', false);
        $this->assertSame(1, $hosting->projects()->count());
    }

    public function test_update_syncs_projects_and_keeps_trashed_links(): void
    {
        $root = $this->root();
        $hosting = Hosting::factory()->create(['specs' => ['cpu' => 8, 'ram_gb' => 10, 'disk_gb' => 80]]);
        [$keep, $drop, $add] = Project::factory()->count(3)->create();
        $trashed = Project::factory()->create();
        $hosting->projects()->attach([$keep->id, $drop->id, $trashed->id]);
        $trashed->delete();

        $this->actingAs($root)->put("/backend/hosting/{$hosting->id}", $this->valid(['name' => 'Đổi tên', 'is_active' => '0', 'project_ids' => [$keep->id, $add->id]]))
            ->assertRedirect(route('backend.hostings.edit', $hosting))->assertSessionHasNoErrors();

        $hosting->refresh();
        $this->assertSame('Đổi tên', $hosting->name);
        $this->assertFalse($hosting->is_active);
        // Dự án trong thùng rác không hiện ở form nhưng không bị mất liên kết
        $this->assertEqualsCanonicalizing([$keep->id, $add->id, $trashed->id], $hosting->projects()->withTrashed()->pluck('projects.id')->all());

        // Không gửi project_ids (bỏ chọn hết) → bỏ gắn mọi dự án còn hoạt động
        $this->actingAs($root)->put("/backend/hosting/{$hosting->id}", $this->valid())->assertSessionHasNoErrors();
        $this->assertSame([$trashed->id], $hosting->projects()->withTrashed()->pluck('projects.id')->all());
    }

    public function test_saving_unchanged_form_writes_no_history(): void
    {
        $root = $this->root();
        $hosting = Hosting::factory()->create(['specs' => ['cpu' => 6, 'ram_gb' => 12, 'disk_gb' => 200], 'register_date' => '2026-01-01', 'expire_date' => '2027-01-01', 'plan_name' => 'Cloud VPS 20', 'provider' => 'Contabo', 'ip' => '1.2.3.4', 'name' => 'Contabo VPS 9', 'note' => null]);
        $before = ProjectAuditLog::count();

        $this->actingAs($root)->put("/backend/hosting/{$hosting->id}", $this->valid())->assertSessionHasNoErrors();

        $this->assertSame($before, ProjectAuditLog::count());
    }

    public function test_cannot_delete_hosting_with_projects(): void
    {
        $root = $this->root();
        $used = Hosting::factory()->create();
        $used->projects()->attach(Project::factory()->create());
        $free = Hosting::factory()->create();
        $onlyTrashed = Hosting::factory()->create();
        $gone = Project::factory()->create();
        $onlyTrashed->projects()->attach($gone);
        $gone->delete();

        $this->actingAs($root)->delete("/backend/hosting/{$used->id}")->assertSessionHas('error');
        $this->assertModelExists($used);
        // Danh sách: hosting còn dự án có nút xóa mờ kèm lý do; hosting trống xóa được
        $this->actingAs($root)->get('/backend/hosting')->assertDontSee('data-delete-url="'.route('backend.hostings.destroy', $used).'"', false)
            ->assertSee('data-delete-url="'.route('backend.hostings.destroy', $free).'"', false)
            ->assertSee('data-tooltip="Đang gắn 1 dự án, không xóa được (bỏ gắn dự án trước)"', false);
        $this->actingAs($root)->get("/backend/hosting/{$used->id}/edit")->assertSee('data-tooltip="Đang gắn 1 dự án, không xóa được (bỏ gắn dự án trước)"', false);

        // Hosting chỉ gắn dự án đã xóa thì xóa được
        $this->actingAs($root)->delete("/backend/hosting/{$free->id}")->assertSessionHas('success');
        $this->actingAs($root)->delete("/backend/hosting/{$onlyTrashed->id}")->assertSessionHas('success');
        $this->assertModelExists($used);
        $this->assertModelMissing($free);
        $this->assertModelMissing($onlyTrashed);
        $this->assertTrue(ProjectAuditLog::where('auditable_type', Hosting::class)->where('action', 'deleted')->exists());
    }

    public function test_list_has_no_filters_sorted_by_name_with_expiry_bar(): void
    {
        $root = $this->root();
        // Hôm nay 10/10/2026
        $soon = Hosting::factory()->create(['name' => 'C VPS sắp hết', 'expire_date' => '2026-10-15', 'ownership' => 'mine']);            // còn 5 ngày
        $later = Hosting::factory()->create(['name' => 'A VPS còn lâu', 'expire_date' => '2027-06-01', 'ownership' => 'mine']);           // còn 234 ngày
        $long = Hosting::factory()->create(['name' => 'E VPS 2 năm', 'expire_date' => '2028-10-10', 'ownership' => 'mine']);              // > 365 ngày
        $expired = Hosting::factory()->create(['name' => 'B VPS quá hạn', 'expire_date' => '2026-09-01', 'ownership' => 'mine', 'is_active' => false]);
        $today = Hosting::factory()->create(['name' => 'F VPS hôm nay', 'expire_date' => '2026-10-10', 'ownership' => 'mine']);
        $customer = Hosting::factory()->create(['name' => 'D Host khách', 'expire_date' => null, 'ownership' => 'customer']);

        $page = $this->actingAs($root)->get('/backend/hosting')->assertOk();
        // Sắp theo tên A–Z
        $page->assertSeeInOrder([$later->name, $expired->name, $soon->name, $customer->name, $long->name, $today->name]);
        // Không còn bộ lọc; tham số cũ trên URL bị bỏ qua
        $page->assertDontSee('id="hosting-filter"', false)->assertDontSee('name="q"', false)->assertDontSee('Mọi hạn')->assertDontSee('Mọi trạng thái')->assertDontSee('aria-label="Loại hosting"', false);
        $this->assertSame(6, $this->actingAs($root)->get('/backend/hosting?q=khách&ownership=customer&expiry=7&active=0&q[]=1')->assertOk()->viewData('hostings')->total());

        // Thanh thời hạn: 365 ngày = 100%, > 365 vẫn 100%, giảm dần, hết hạn = 0%
        $html = $page->getContent();
        $bar = fn (Hosting $h) => preg_match('#>'.preg_quote($h->name, '#').'<(?:(?!</tr>).)*?data-expiry="([0-9.]+)"(?:(?!</tr>).)*?style="color: ([^"]+)">([^<]+)<#s', $html, $m) ? [(float) $m[1], $m[2], $m[3]] : null;
        $this->assertSame([1.4, '#faad14', 'Còn 5 ngày'], $bar($soon));
        $this->assertSame([64.1, '#52c41a', 'Còn 234 ngày'], $bar($later));
        $this->assertSame([100.0, '#52c41a', 'Còn 731 ngày'], $bar($long));
        $this->assertSame([0.0, '#ff4d4f', 'Quá hạn 39 ngày'], $bar($expired));
        $this->assertSame([0.0, '#ff4d4f', 'Hết hạn hôm nay'], $bar($today));
        $this->assertStringContainsString('style="width: 64.1%; height: 6px; background-color: #52c41a"', $html);
        // Chưa có ngày hết hạn: không có thanh
        $this->assertNull($bar($customer));
        $this->assertSame(5, substr_count($html, 'role="progressbar"'));

        // Loại: của mình = tím, của khách = xanh dương (tách khỏi màu thời hạn); mỗi dòng ghi 2 lần (cột Loại + dưới tên
        // cho điện thoại)
        $this->assertSame(10, substr_count($html, '<span class="ant-tag m-0 ant-tag-purple">Của mình</span>'));
        $this->assertSame(2, substr_count($html, '<span class="ant-tag m-0 ant-tag-blue">Của khách</span>'));
        $page->assertSee('Ngừng dùng');
    }

    public function test_project_pages_link_to_hosting(): void
    {
        $root = $this->root();
        $hosting = Hosting::factory()->create();
        $on = Project::factory()->create();
        $off = Project::factory()->create();
        $hosting->projects()->attach($on);

        $this->actingAs($root)->get("/backend/project/{$on->id}")->assertSee(route('backend.hostings.edit', $hosting));
        $this->actingAs($root)->get("/backend/project?hosting_id={$hosting->id}")->assertSee($on->name)->assertDontSee($off->name);
        $this->actingAs($root)->get('/backend/hosting')->assertSee(route('backend.projects.index', ['hosting_id' => $hosting->id]));
        $this->actingAs($root)->get('/backend/project?status[]=1&hosting_id[]=1')->assertOk();
    }

    /** Tham số dạng mảng (?q[]=x) từng làm các trang danh sách lỗi 500. */
    public function test_list_pages_ignore_array_query_params(): void
    {
        $root = $this->root();
        foreach (['/backend/project', '/backend/customer', '/backend/payment', '/backend/users', '/backend/hosting', '/backend/renewal', '/backend/report/revenue'] as $url) {
            $this->actingAs($root)->get($url.'?q[]=1&sort[]=1&status[]=1&type[]=1&method[]=1&from[]=1&to[]=1&tab[]=1&period[]=1')->assertOk();
        }
        // Ngày sai không bị hiểu thành ngày khác
        $this->actingAs($root)->get('/backend/payment?from=2026-13-45')->assertOk()->assertDontSee('value="2027-02-14"', false);
    }

    public function test_deleting_from_own_edit_page_returns_to_list(): void
    {
        $root = $this->root();
        $hosting = Hosting::factory()->create(['name' => 'Trống']);

        $this->actingAs($root)->from(route('backend.hostings.edit', $hosting))->delete("/backend/hosting/{$hosting->id}")
            ->assertRedirect(route('backend.hostings.index'))->assertSessionHas('success', 'Đã xóa hosting Trống.');
        $this->actingAs($root)->get('/backend/hosting?page=3')->assertRedirect(route('backend.hostings.index', ['page' => 1]));
    }

    public function test_active_switch_and_project_picks_survive_validation_errors(): void
    {
        $root = $this->root();
        [$a, $b] = Project::factory()->count(2)->create();
        $create = route('backend.hostings.create');

        // Công tắc "Đang dùng": ô ẩn 0 + checkbox 1 (tắt thì chỉ còn 0)
        $html = $this->actingAs($root)->get($create)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="is_active" value="0" \/>\s*<input type="checkbox" name="is_active" value="1" class="switch-input" hidden\s+checked/', $html);

        // Tắt + chọn 1 dự án + tên trống → lỗi; quay lại: công tắc vẫn tắt, dự án vẫn chọn, lỗi dưới ô tên
        $this->actingAs($root)->from($create)->post('/backend/hosting', $this->valid(['name' => '', 'is_active' => '0', 'project_ids' => [$b->id]]))->assertRedirect($create);
        $html = $this->actingAs($root)->get($create)->getContent();
        $this->assertStringContainsString('aria-checked="false" class="ant-switch mt-0.5 flex-none"', $html);
        $this->assertStringContainsString('name="project_ids[]" value="'.$b->id.'" checked', $html);
        $this->assertStringNotContainsString('name="project_ids[]" value="'.$a->id.'" checked', $html);
        $this->assertStringContainsString('data-dirty', $html);
        $this->assertSame(0, Hosting::count());
    }
}
