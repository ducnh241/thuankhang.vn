<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Nhật ký hệ thống (Affiliate Phase 1 – Step 0): mọi thao tác quản trị + đăng nhập được ghi vào audit_logs,
 * bảng chỉ ghi thêm, màn hình xem chỉ mở cho quyền audit_log.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_changes_are_logged_with_before_and_after(): void
    {
        $root = User::factory()->superAdmin()->create();
        $this->actingAs($root);

        $group = Group::factory()->withPermissions('backend_access')->create(['name' => 'Kế toán']);
        $group->update(['permission_data' => ['backend_access', 'payment']]);
        $group->delete();

        $logs = AuditLog::where('auditable_type', Group::class)->where('auditable_id', $group->id)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('action')->all());

        $updated = $logs[1];
        $this->assertSame($root->id, $updated->user_id);
        $this->assertSame($root->name, $updated->user_name);
        $this->assertSame(['permission_data'], array_keys($updated->changes['new']));
        $this->assertStringNotContainsString('payment', (string) json_encode($updated->changes['old']));
        $this->assertStringContainsString('payment', (string) json_encode($updated->changes['new']));
    }

    public function test_password_is_never_written_to_log(): void
    {
        $root = User::factory()->superAdmin()->create();
        $this->actingAs($root);

        $user = User::factory()->create(['password' => 'mat-khau-bi-mat-1']);
        $user->update(['password' => 'mat-khau-bi-mat-2']);

        $json = AuditLog::where('auditable_type', User::class)->where('auditable_id', $user->id)->pluck('changes')->toJson();
        $this->assertStringNotContainsString('mat-khau-bi-mat', $json);
        $this->assertStringNotContainsString($user->password, $json);
        $this->assertSame('••••••', AuditLog::where('auditable_id', $user->id)->where('action', 'updated')->value('changes')['new']['password']);
    }

    public function test_audit_log_is_append_only(): void
    {
        $log = AuditLog::record('created', null, null, 'test');

        $this->assertThrows(fn () => $log->update(['action' => 'deleted']), LogicException::class);
        $this->assertThrows(fn () => $log->delete(), LogicException::class);
        $this->assertSame('created', $log->fresh()->action);
    }

    public function test_login_logout_and_failed_login_are_logged(): void
    {
        $user = User::factory()->superAdmin()->create(['email' => 'admin@example.com']);

        $this->post('/backend/login', ['email' => 'admin@example.com', 'password' => 'sai-mat-khau'])->assertSessionHasErrors('email');
        $failed = AuditLog::where('action', 'login_failed')->sole();
        $this->assertNull($failed->user_id);
        $this->assertSame($user->id, $failed->auditable_id);
        $this->assertSame('Email: admin@example.com', $failed->description);
        $this->assertStringNotContainsString('sai-mat-khau', (string) json_encode($failed->getAttributes()));
        $this->assertSame('Khách', $failed->actorName());

        $this->post('/backend/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect();
        $this->assertSame($user->id, AuditLog::where('action', 'login')->sole()->user_id);

        $this->post('/backend/logout')->assertRedirect('/backend/login');
        $this->assertSame($user->id, AuditLog::where('action', 'logout')->sole()->user_id);
    }

    public function test_bulk_delete_by_query_is_logged(): void
    {
        $root = User::factory()->superAdmin()->create();
        $menu = Menu::create(['handle' => 'main', 'name' => 'Menu chính']);
        $parent = $menu->items()->create(['label' => 'Cha', 'url' => '/cha', 'position' => 0]);
        $child = $menu->items()->create(['label' => 'Con', 'url' => '/con', 'position' => 0, 'parent_id' => $parent->id]);

        $this->actingAs($root)->delete("/backend/menu/item/{$parent->id}")->assertRedirect();

        $log = AuditLog::where('action', 'bulk_deleted')->sole();
        $this->assertSame(MenuItem::class, $log->auditable_type);
        $this->assertEqualsCanonicalizing([$parent->id, $child->id], $log->changes['old']['ids']);
    }

    public function test_audit_log_page_requires_permission_and_menu_follows_it(): void
    {
        $group = Group::factory()->withPermissions('backend_access', 'user')->create();
        $staff = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($staff)->get('/backend/audit-log')->assertForbidden();
        $this->actingAs($staff)->get('/backend')->assertOk()->assertDontSee(route('backend.audit-logs.index'), false);

        $group->update(['permission_data' => ['backend_access', 'audit_log']]);
        $this->actingAs($staff->fresh())->get('/backend')->assertOk()->assertSee(route('backend.audit-logs.index'), false);
        $this->actingAs($staff->fresh())->get('/backend/audit-log')->assertOk()->assertSee('Nhật ký hệ thống');
    }

    public function test_audit_log_page_lists_and_filters(): void
    {
        $root = User::factory()->superAdmin()->create(['name' => 'Quản Trị Viên']);
        $this->actingAs($root);
        Group::factory()->create(['name' => 'Nhóm Mới']);
        AuditLog::record('login_failed', null, null, 'Email: hacker@example.com');

        $this->get('/backend/audit-log')->assertOk()
            ->assertSee('Nhóm quyền')
            ->assertSee('Quản Trị Viên')
            ->assertSee('hacker@example.com');

        $this->get('/backend/audit-log?action=login_failed')->assertOk()
            ->assertSee('hacker@example.com')
            ->assertDontSee('Nhóm Mới');

        $this->get('/backend/audit-log?type='.urlencode(Group::class))->assertOk()
            ->assertSee('Nhóm Mới')
            ->assertDontSee('hacker@example.com');

        $this->get('/backend/audit-log?q=hacker')->assertOk()->assertSee('hacker@example.com')->assertDontSee('Nhóm Mới');

        // Tham số rác không làm lỗi trang
        $this->get('/backend/audit-log?action[]=x&type=Khong\\Ton\\Tai&from=abc&to=2026-13-45')->assertOk();
    }

    public function test_hidden_account_is_shown_as_system(): void
    {
        // Cả log tạo chính tài khoản ẩn (chứa tên trong chi tiết) cũng không hiện
        $hidden = User::factory()->superAdmin()->create(['name' => 'Tài Khoản Ẩn']);
        config(['backend.hidden_users' => [$hidden->id]]);
        $this->actingAs($hidden);
        Group::factory()->create();

        AuditLog::record('login_failed', null, null, 'Email: khong-co@example.com');

        $viewer = User::factory()->superAdmin()->create();
        $this->actingAs($viewer)->get('/backend/audit-log')->assertOk()->assertDontSee('Tài Khoản Ẩn')
            ->assertSee('khong-co@example.com'); // dòng không có đối tượng vẫn hiện khi có tài khoản ẩn
        $this->actingAs($viewer)->get('/backend/audit-log?q=Tài Khoản Ẩn')->assertOk()->assertSee('Không có dòng nào khớp bộ lọc');
    }
}
