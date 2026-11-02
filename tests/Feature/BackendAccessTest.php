<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackendAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_backend_access_cannot_login(): void
    {
        $user = User::factory()->create(); // không group, không super admin

        $this->from('/backend/login')
            ->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/backend/login')
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_locked_user_cannot_login(): void
    {
        $user = User::factory()->superAdmin()->create(['status' => false]);

        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_group_member_with_backend_access_can_login(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->post('/backend/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/backend');
        $this->assertAuthenticatedAs($user);
    }

    public function test_logged_in_user_is_kicked_out_when_group_loses_access(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($user)->get('/backend')->assertOk();

        $group->update(['permission_data' => []]);

        $this->actingAs($user->fresh())->get('/backend')->assertRedirect('/backend/login');
        $this->assertGuest();
    }

    public function test_menu_items_are_hidden_without_permission(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($user)->get('/backend')
            ->assertOk()
            ->assertDontSee(route('backend.users.index'))
            ->assertDontSee(route('backend.groups.index'));

        $this->actingAs(User::factory()->superAdmin()->create())->get('/backend')
            ->assertSee(route('backend.users.index'))
            ->assertSee(route('backend.groups.index'));
    }

    public function test_users_parent_menu_shows_only_allowed_children(): void
    {
        $group = Group::factory()->withPermissions('group')->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($user)->get('/backend/groups')
            ->assertOk()
            ->assertSee('SYSTEM')
            ->assertSee(route('backend.groups.index'))
            ->assertDontSee(route('backend.users.index'));

        // Không có quyền con nào → không hiện cả menu cha lẫn nhóm SYSTEM
        $plain = User::factory()->create(['group_id' => Group::factory()->create()->id]);
        $this->actingAs($plain)->get('/backend')->assertOk()->assertDontSee('SYSTEM');
    }

    /** Admin đặt lại mật khẩu (vd tài khoản bị lộ) → phiên cũ của tài khoản đó bị đăng xuất ngay. */
    public function test_password_change_by_admin_logs_out_existing_sessions(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->get('/backend')->assertOk();
        $user->update(['password' => 'new-password-456']);

        $this->get('/backend')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    /** Tự đổi mật khẩu: phiên hiện tại giữ nguyên. */
    public function test_changing_own_password_keeps_current_session(): void
    {
        $user = User::factory()->superAdmin()->create(['password' => 'old-password-123']);

        $this->actingAs($user)->get('/backend/profile')->assertOk();
        $this->put('/backend/profile/password', ['current_password' => 'old-password-123', 'password' => 'new-password-456', 'password_confirmation' => 'new-password-456'])
            ->assertRedirect(route('backend.profile.edit'))->assertSessionHasNoErrors();

        $this->get('/backend')->assertOk();
        $this->assertAuthenticatedAs($user);
    }
}
