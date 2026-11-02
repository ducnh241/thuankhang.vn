<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User không phải Root chỉ được quản lý người/nhóm "không mạnh hơn" mình:
 * không tự nâng quyền, không chiếm tài khoản của người có quyền cao hơn.
 */
class PrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private Group $editorGroup;
    private Group $adminGroup;
    private User $editor;

    protected function setUp(): void
    {
        parent::setUp();

        // Editor: chỉ được sửa user
        $this->editorGroup = Group::factory()->withPermissions('user', 'user/edit', 'user/add', 'user/delete')->create(['name' => 'Editor']);
        // Admin: mạnh hơn, có cả quản lý nhóm
        $this->adminGroup = Group::factory()->withPermissions('user', 'user/edit', 'user/add', 'user/delete', 'group', 'group/edit', 'group/add', 'group/delete')->create(['name' => 'Admin']);
        $this->editor = User::factory()->create(['group_id' => $this->editorGroup->id]);
    }

    private function userPayload(User $user, array $override = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'status' => '1', 'group_id' => $user->group_id], $override);
    }

    public function test_editor_cannot_move_self_into_stronger_group(): void
    {
        $this->actingAs($this->editor)
            ->put("/backend/users/{$this->editor->id}", $this->userPayload($this->editor, ['group_id' => $this->adminGroup->id]))
            ->assertSessionHasErrors('group_id');

        $this->assertSame($this->editorGroup->id, $this->editor->fresh()->group_id);
    }

    public function test_editor_cannot_create_user_in_stronger_group(): void
    {
        $this->actingAs($this->editor)->post('/backend/users', [
            'name' => 'Phe ta', 'email' => 'pheta@example.com',
            'password' => 'matkhau123', 'password_confirmation' => 'matkhau123',
            'group_id' => $this->adminGroup->id, 'status' => '1',
        ])->assertSessionHasErrors('group_id');

        $this->assertDatabaseMissing('users', ['email' => 'pheta@example.com']);
    }

    public function test_editor_cannot_take_over_stronger_user(): void
    {
        $admin = User::factory()->create(['group_id' => $this->adminGroup->id]);

        // Không mở được form, không đổi được mật khẩu, không xóa được
        $this->actingAs($this->editor)->get("/backend/users/{$admin->id}/edit")->assertForbidden();
        $this->actingAs($this->editor)->put("/backend/users/{$admin->id}", $this->userPayload($admin, [
            'password' => 'chiemquyen1', 'password_confirmation' => 'chiemquyen1',
        ]))->assertForbidden();
        $this->actingAs($this->editor)->delete("/backend/users/{$admin->id}")->assertForbidden();

        $this->assertModelExists($admin);
        $this->assertFalse(\Hash::check('chiemquyen1', $admin->fresh()->password));
    }

    public function test_editor_can_still_manage_equal_or_weaker_users(): void
    {
        $peer = User::factory()->create(['group_id' => $this->editorGroup->id]);
        $weakGroup = Group::factory()->create(['name' => 'Weak']); // chỉ backend_access
        $weak = User::factory()->create(['group_id' => $weakGroup->id]);

        $this->actingAs($this->editor)->get("/backend/users/{$peer->id}/edit")->assertOk();
        $this->actingAs($this->editor)->put("/backend/users/{$weak->id}", $this->userPayload($weak, ['name' => 'Đã sửa']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Đã sửa', $weak->fresh()->name);
    }

    public function test_group_manager_cannot_grant_permissions_they_lack(): void
    {
        // Có quyền sửa nhóm, nhưng KHÔNG có quyền xóa user
        $managerGroup = Group::factory()->withPermissions('group', 'group/edit', 'user')->create(['name' => 'GroupManager']);
        $manager = User::factory()->create(['group_id' => $managerGroup->id]);

        $this->actingAs($manager)->put("/backend/groups/{$managerGroup->id}", [
            'name' => 'GroupManager',
            'permission' => [
                'backend_access' => ['_' => '1'],
                'group' => ['_' => '1', 'edit' => '1'],
                'user' => ['_' => '1', 'delete' => '1', 'add' => '1'],
            ],
        ]);

        $perms = $managerGroup->fresh()->permission_data;
        $this->assertNotContains('user/delete', $perms);
        $this->assertNotContains('user/add', $perms);
    }

    public function test_group_manager_keeps_permissions_they_cannot_see(): void
    {
        // Người sửa không có user/delete; nhóm đích đang có user/delete → giữ nguyên, không bị xóa mất
        $managerGroup = Group::factory()->withPermissions('group', 'group/edit')->create(['name' => 'GroupManager']);
        $manager = User::factory()->create(['group_id' => $managerGroup->id]);
        $target = Group::factory()->withPermissions('user', 'user/delete')->create(['name' => 'Target']);

        $this->actingAs($manager)->get("/backend/groups/{$target->id}/edit")->assertForbidden();
    }

    public function test_root_is_not_restricted(): void
    {
        $root = User::factory()->superAdmin()->create();

        $this->actingAs($root)->put("/backend/users/{$this->editor->id}", $this->userPayload($this->editor, ['group_id' => $this->adminGroup->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($this->adminGroup->id, $this->editor->fresh()->group_id);
    }
}
