<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GroupManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_group_normalizes_permission_tree(): void
    {
        $root = User::factory()->superAdmin()->create();

        $this->actingAs($root)->post('/backend/groups', [
            'name' => 'Content',
            'permission' => [
                'backend_access' => ['_' => '1'],
                'user' => ['_' => '1', 'add' => '1', 'edit' => '1'],
                'group' => ['_' => '1'],
                'khong_ton_tai' => ['_' => '1'],
            ],
        ])->assertRedirect();

        $group = Group::where('name', 'Content')->firstOrFail();
        $this->assertSame(['backend_access', 'user', 'user/add', 'user/edit', 'group'], $group->permission_data);
    }

    public function test_group_permissions_drive_gates(): void
    {
        $group = Group::factory()->withPermissions('user', 'user/edit')->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->assertTrue($user->can('user'));
        $this->assertTrue($user->can('user/edit'));
        $this->assertFalse($user->can('user/delete'));
        $this->assertFalse($user->can('group'));
    }

    public function test_edit_form_shows_checked_permissions(): void
    {
        $root = User::factory()->superAdmin()->create();
        $group = Group::factory()->withPermissions('user', 'user/add')->create();

        $html = $this->actingAs($root)->get("/backend/groups/{$group->id}/edit")->assertOk()->getContent();

        // user/add đã cấp → checked; user/delete chưa cấp → không checked
        $this->assertMatchesRegularExpression('/<input[^>]*name="permission\[user\]\[add\]"[^>]*\bchecked\b/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="permission\[user\]\[delete\]"[^>]*\bchecked\b/', $html);
    }

    public function test_group_with_users_cannot_be_deleted(): void
    {
        $root = User::factory()->superAdmin()->create();
        $group = Group::factory()->create();
        User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($root)->delete("/backend/groups/{$group->id}")->assertSessionHas('error');
        $this->assertModelExists($group);

        $empty = Group::factory()->create();
        $this->actingAs($root)->delete("/backend/groups/{$empty->id}")->assertRedirect('/backend/groups');
        $this->assertModelMissing($empty);
    }

    public function test_group_name_must_be_unique(): void
    {
        $root = User::factory()->superAdmin()->create();
        Group::factory()->create(['name' => 'Content']);

        $this->actingAs($root)->from('/backend/groups/create')
            ->post('/backend/groups', ['name' => 'Content'])
            ->assertSessionHasErrors('name');
    }

    public function test_delete_blocks_groups_with_users(): void
    {
        $root = User::factory()->superAdmin()->create();
        $used = Group::factory()->create();
        User::factory()->create(['group_id' => $used->id]);
        $empty = Group::factory()->create();

        $this->actingAs($root)->delete("/backend/groups/{$used->id}")->assertSessionHas('error');
        $this->actingAs($root)->delete("/backend/groups/{$empty->id}")->assertRedirect('/backend/groups');

        $this->assertModelExists($used);
        $this->assertModelMissing($empty);
    }

    public function test_group_endpoints_require_permission(): void
    {
        $group = Group::factory()->withPermissions('group')->create(); // chỉ xem
        $viewer = User::factory()->create(['group_id' => $group->id]);
        $target = Group::factory()->create();

        $this->actingAs($viewer)->get('/backend/groups')->assertOk();
        $this->actingAs($viewer)->get('/backend/groups/create')->assertForbidden();
        $this->actingAs($viewer)->get("/backend/groups/{$target->id}/edit")->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/groups/{$target->id}")->assertForbidden();
        $this->assertModelExists($target);
    }

    public function test_groups_page_selects_first_group_and_lists_members(): void
    {
        $root = User::factory()->superAdmin()->create();
        $b = Group::factory()->withPermissions('page')->create(['name' => 'B nhóm']);
        $a = Group::factory()->create(['name' => 'A nhóm']);
        User::factory()->count(2)->create(['group_id' => $b->id]);

        // Mở trang: chọn sẵn nhóm đầu theo tên, form sửa nhóm đó
        $this->actingAs($root)->get('/backend/groups')->assertOk()
            ->assertSee('<h1 class="page-topbar-title">A nhóm</h1>', false)
            ->assertSee('action="'.route('backend.groups.update', $a).'"', false)
            ->assertSeeInOrder(['A nhóm', '0 người dùng', 'B nhóm', '2 người dùng']);
        // Nhóm có người: nút xóa mờ, link sang danh sách người dùng của nhóm
        $html = $this->actingAs($root)->get("/backend/groups/{$b->id}/edit")->assertOk()
            ->assertSee('data-tooltip="Còn 2 người dùng trong nhóm, không xóa được', false)
            ->assertSee('href="'.e(route('backend.users.index', ['group' => $b->id])).'"', false)
            ->getContent();
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(route('backend.groups.edit', $b), '#').'" class="group-item is-selected"\s+aria-current="page"\s*>#', $html);
        // Tạo mới: không nhóm nào được chọn, form gửi về store
        $this->actingAs($root)->get('/backend/groups/create')->assertOk()
            ->assertDontSee('is-selected', false)->assertSee('action="'.route('backend.groups.store').'"', false);
    }

    public function test_view_only_user_sees_disabled_permission_tree(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'group')->create()->id]);
        $other = Group::factory()->withPermissions('page')->create(['name' => 'Một nhóm']);

        $html = $this->actingAs($viewer)->get("/backend/groups?id={$other->id}")->assertOk()
            ->assertSee('<span class="ant-tag">Chỉ xem</span>', false)
            ->assertDontSee('id="save-bar"', false)->assertDontSee('data-edit-form', false)
            ->assertSee('href="'.e(route('backend.groups.index', ['id' => $other->id])).'"', false)
            ->getContent();
        // Mọi ô quyền (và công tắc đăng nhập) đều khóa, không có ô chọn cả cột
        $this->assertSame(0, preg_match_all('/<input type="checkbox" class="ant-checkbox-input perm-(?:module|action)"(?![^>]*\bdisabled\b)[^>]*>/', $html));
        $this->assertGreaterThan(10, preg_match_all('/<input type="checkbox" class="ant-checkbox-input perm-(?:module|action)"[^>]*\bdisabled\b/', $html));
        $this->assertMatchesRegularExpression('/name="permission\[backend_access\]\[_\]"[^>]*disabled/', $html);
        $this->assertStringNotContainsString('data-perm-col', $html);
    }

    public function test_permission_table_groups_modules_by_section(): void
    {
        $root = User::factory()->superAdmin()->create();
        $group = Group::factory()->withPermissions('backend_access', 'audit_log', 'kyc', 'kyc/review')->create();

        $html = $this->actingAs($root)->get("/backend/groups/{$group->id}/edit")->assertOk()
            // Đăng nhập là công tắc riêng, đang bật
            ->assertSee('<input type="checkbox" name="permission[backend_access][_]" value="1" class="switch-input" hidden', false)
            ->assertSee('aria-checked="true" class="ant-switch mt-0.5 flex-none ant-switch-checked"', false)
            // Các nhóm dòng theo config, mỗi mục một dòng
            ->assertSeeInOrder(['Danh mục', 'Danh mục tin tức', 'Website', 'Trang', 'Menu website', 'Affiliate – Cấu hình', 'Cấu hình cấp bậc', 'Bán hàng', 'Sản phẩm &amp; danh mục', 'Thành viên', 'KYC', 'Tài chính', 'Rút tiền', 'Hệ thống', 'Người dùng', 'Nhóm quyền', 'Nhật ký hệ thống'], false)
            ->assertSee('data-perm-col="_"', false)->assertSee('data-perm-col="delete"', false)
            ->getContent();
        // Nhật ký: chỉ có Xem (đang bật, dòng sáng), Thêm / Sửa / Xóa là "–"
        $this->assertMatchesRegularExpression('#<tr class="ant-table-row perm-row is-on" data-module="audit_log">.*?name="permission\[audit_log\]\[_\]" value="1" checked.*?(<span class="perm-none"[^>]*>–</span>.*?){3}</tr>#s', $html);
        // KYC: việc riêng ghi dưới tên, có nhãn
        $this->assertMatchesRegularExpression('#data-module="kyc">.*?<div class="perm-extra">.*?name="permission\[kyc\]\[review\]" value="1" checked data-action="review" />.*?<span>Duyệt / từ chối</span>#s', $html);
        $this->assertMatchesRegularExpression('#<tr class="ant-table-row perm-row" data-module="page">#', $html);
    }

    public function test_deleting_group_returns_to_groups_page(): void
    {
        $root = User::factory()->superAdmin()->create();
        $group = Group::factory()->create(['name' => 'Tạm']);

        $this->actingAs($root)->from(route('backend.groups.edit', $group))->delete("/backend/groups/{$group->id}")
            ->assertRedirect(route('backend.groups.index'))->assertSessionHas('success', 'Đã xóa nhóm quyền Tạm.');
    }

    public function test_permissions_are_locked_while_login_is_off(): void
    {
        $root = User::factory()->superAdmin()->create();
        $noLogin = Group::factory()->create(['permission_data' => ['page', 'page/add']]);
        $withLogin = Group::factory()->withPermissions('backend_access', 'page')->create();

        // Không có quyền đăng nhập: phần quyền mờ + khóa (inert), các ô vẫn giữ giá trị để bật lại là dùng được
        $this->actingAs($root)->get("/backend/groups/{$noLogin->id}/edit")->assertOk()
            ->assertSee('<div class="perm-body is-off" id="perm-body"  inert >', false)
            ->assertSee('name="permission[page][add]" value="1" checked', false)
            ->assertSee('Đang tắt: người trong nhóm không đăng nhập được, mọi quyền bên dưới đều không có tác dụng.');
        $this->actingAs($root)->get("/backend/groups/{$withLogin->id}/edit")->assertOk()
            ->assertSee('<div class="perm-body" id="perm-body" >', false);
        // Nhóm mới: bật sẵn đăng nhập
        $html = $this->actingAs($root)->get('/backend/groups/create')->assertOk()
            ->assertSee('<div class="perm-body" id="perm-body" >', false)->getContent();
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="permission\[backend_access\]\[_\]" value="1" class="switch-input" hidden\s+checked/', $html);
    }
}
