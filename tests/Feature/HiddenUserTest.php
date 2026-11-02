<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tài khoản ẩn (config backend.hidden_users): không hiện ở bất kỳ đâu trong trang quản trị và không ai mở / sửa / xóa
 * được kể cả gõ URL; bản thân nó vẫn đăng nhập, làm việc bình thường.
 */
class HiddenUserTest extends TestCase
{
    use RefreshDatabase;

    private User $hidden;

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hidden = User::factory()->superAdmin()->create(['name' => 'Hỗ Trợ Bí Mật', 'email' => 'hotro@an.vn', 'password' => 'mat-khau-an']);
        $this->root = User::factory()->superAdmin()->create(['name' => 'Chủ Website']);
        config(['backend.hidden_users' => [$this->hidden->id]]);
    }

    public function test_hidden_user_never_appears_in_lists_or_counts(): void
    {
        $group = Group::factory()->withPermissions('backend_access')->create(['name' => 'Biên tập']);
        $this->hidden->update(['group_id' => $group->id]);
        User::factory()->create(['group_id' => $group->id]);

        foreach (['/backend/users', '/backend/users?status=root', '/backend/users?group=none', "/backend/users?group={$group->id}", '/backend/users?q=hotro', '/backend/groups', "/backend/groups/{$group->id}/edit", '/backend'] as $url) {
            $this->actingAs($this->root)->get($url)->assertOk()->assertDontSee('Hỗ Trợ Bí Mật')->assertDontSee('hotro@an.vn');
        }
        // Số đếm: chỉ người đang hiện (2 người: Chủ Website + 1 biên tập), Root admin chỉ 1
        $this->actingAs($this->root)->get('/backend/users')
            ->assertSee('Tất cả <span class="product-view-count">2</span>', false)
            ->assertSee('Root admin <span class="product-view-count">1</span>', false)
            ->assertSee('/ 2 người dùng');
        $this->actingAs($this->root)->get('/backend/groups')->assertSee('1 người dùng')->assertDontSee('2 người dùng');
    }

    public function test_hidden_user_cannot_be_opened_changed_or_deleted_by_url(): void
    {
        $before = $this->hidden->fresh()->only(['name', 'email', 'password', 'is_super_admin', 'status', 'group_id']);

        $this->actingAs($this->root)->get("/backend/users/{$this->hidden->id}/edit")->assertNotFound();
        $this->actingAs($this->root)->put("/backend/users/{$this->hidden->id}", [
            'name' => 'Đổi', 'email' => 'doi@an.vn', 'password' => 'mat-khau-moi', 'password_confirmation' => 'mat-khau-moi', 'is_super_admin' => '0',
        ])->assertNotFound();
        $this->actingAs($this->root)->delete("/backend/users/{$this->hidden->id}")->assertNotFound();

        $this->assertSame($before, $this->hidden->fresh()->only(['name', 'email', 'password', 'is_super_admin', 'status', 'group_id']));
        $this->assertTrue(Hash::check('mat-khau-an', $this->hidden->fresh()->password));
        // Root admin đang hiện là Root cuối cùng (tài khoản ẩn không tính) → giao diện như không có tài khoản ẩn
        $this->assertTrue($this->root->isLastSuperAdmin());
    }

    public function test_hidden_user_still_works_normally(): void
    {
        // Đăng nhập, xem / quản lý người khác, sửa thông tin của mình ở trang Tài khoản
        // (menu tài khoản ở góc trên vẫn hiện tên / email của chính nó — đó là nó tự xem)
        $this->actingAs($this->hidden)->get('/backend/users')->assertOk()->assertSee('Chủ Website')
            ->assertDontSee('data-row-key="'.$this->hidden->id.'"', false)->assertSee('Tất cả <span class="product-view-count">1</span>', false);
        $this->actingAs($this->hidden)->get("/backend/users/{$this->root->id}/edit")->assertOk();
        $this->actingAs($this->hidden)->get("/backend/users/{$this->hidden->id}/edit")->assertNotFound();
        $this->actingAs($this->hidden)->put('/backend/profile', ['name' => 'Hỗ Trợ Bí Mật', 'email' => 'hotro@an.vn', 'phone' => '0900'])->assertRedirect();
        $this->assertSame('0900', $this->hidden->fresh()->phone);
    }

    public function test_group_with_only_hidden_member_can_be_deleted(): void
    {
        $group = Group::factory()->create();
        $this->hidden->update(['group_id' => $group->id]);

        $this->actingAs($this->root)->get("/backend/groups/{$group->id}/edit")
            ->assertSee('Chưa có ai ở nhóm này.')->assertDontSee('người dùng trong nhóm, không xóa được');
        $this->actingAs($this->root)->delete("/backend/groups/{$group->id}")->assertSessionHas('success');
        $this->assertModelMissing($group);
        $this->assertNull($this->hidden->fresh()->group_id);
        $this->assertTrue($this->hidden->fresh()->isSuperAdmin());
    }

    public function test_project_history_shows_system_instead_of_hidden_user(): void
    {
        $project = Project::factory()->create(['name' => 'Dự án A']);
        $this->actingAs($this->hidden);
        $project->update(['name' => 'Dự án A (ẩn sửa)']);
        $this->actingAs($this->root);
        $project->update(['name' => 'Dự án A (chủ sửa)']);

        $this->actingAs($this->root)->get("/backend/project/{$project->id}")->assertOk()
            ->assertDontSee('Hỗ Trợ Bí Mật')->assertSee('Hệ thống')->assertSee('Chủ Website');
    }
}
