<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_own_profile_and_avatar(): void
    {
        Storage::fake('public');
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->put('/backend/profile', [
            'name' => 'Tên Mới',
            'email' => 'moi@example.com',
            'phone' => '0911',
            'avatar' => UploadedFile::fake()->image('me.jpg'),
        ])->assertRedirect('/backend/profile')->assertSessionHas('success', 'Đã cập nhật tài khoản.');

        $user->refresh();
        $this->assertSame('Tên Mới', $user->name);
        $this->assertSame('moi@example.com', $user->email);
        Storage::disk('public')->assertExists($user->avatar);
        $old = $user->avatar;

        // Trang hiện ảnh đang lưu (thẻ Ant Upload, có nút bỏ ảnh)
        $this->actingAs($user)->get('/backend/profile')->assertOk()
            ->assertSee('id="user-avatar" data-current="'.$user->avatarUrl().'"', false)->assertSee('class="ant-btn ant-btn-text ant-btn-sm ant-btn-icon-only remove-file"', false);

        // Xoá avatar
        $this->actingAs($user)->put('/backend/profile', [
            'name' => 'Tên Mới', 'email' => 'moi@example.com', 'remove_avatar' => '1',
        ]);
        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($old);

        // SVG (có thể chứa mã) không nhận
        $svg = UploadedFile::fake()->createWithContent('a.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($user)->put('/backend/profile', ['name' => 'Tên Mới', 'email' => 'moi@example.com', 'avatar' => $svg])->assertSessionHasErrors('avatar');
    }

    public function test_profile_cannot_change_group_root_or_status(): void
    {
        $group = Group::factory()->withPermissions('page')->create(['name' => 'Kinh doanh']);
        $other = Group::factory()->withPermissions('page', 'user', 'group')->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($user)->put('/backend/profile', [
            'name' => 'A', 'email' => $user->email, 'group_id' => $other->id, 'is_super_admin' => '1', 'status' => '0', 'password' => 'hack12345',
        ])->assertSessionHasNoErrors();
        $user->refresh();
        $this->assertSame([$group->id, false, true], [$user->group_id, $user->isSuperAdmin(), $user->isActive()]);
        $this->assertTrue(Hash::check('password', $user->password));

        // Trang: tên nhóm ở thanh trên cùng + cột phụ, không có ô đổi nhóm / Root / trạng thái
        $this->actingAs($user)->get('/backend/profile')->assertOk()
            ->assertSee('title="Kinh doanh">Kinh doanh</span>', false)->assertSee('<dd>', false)
            ->assertDontSee('name="group_id"', false)->assertDontSee('name="is_super_admin"', false)->assertDontSee('name="status"', false);
    }

    public function test_change_password_requires_current_password(): void
    {
        $user = User::factory()->superAdmin()->create();

        // Sai mật khẩu hiện tại: báo lỗi ở form đổi mật khẩu (túi lỗi riêng) — form thông tin không bị coi là "chưa lưu"
        $html = $this->actingAs($user)->from('/backend/profile')->followingRedirects()->put('/backend/profile/password', [
            'current_password' => 'sai-roi',
            'password' => 'matkhaumoi1',
            'password_confirmation' => 'matkhaumoi1',
        ])->assertOk()->getContent();
        $this->assertStringContainsString('Mật khẩu hiện tại không đúng.', $html);
        $this->assertStringNotContainsString('data-dirty', $html);
        $this->assertStringNotContainsString('value="matkhaumoi1"', $html); // không điền lại mật khẩu
        $this->assertStringContainsString('id="name" name="name" value="'.$user->name.'"', $html);
        $this->actingAs($user)->from('/backend/profile')->put('/backend/profile/password', [
            'current_password' => 'password', 'password' => 'ngan', 'password_confirmation' => 'ngan',
        ])->assertSessionHasErrorsIn('password', 'password');
        $this->actingAs($user)->from('/backend/profile')->put('/backend/profile/password', [
            'current_password' => 'password', 'password' => 'matkhaumoi1', 'password_confirmation' => 'khac12345',
        ])->assertSessionHasErrorsIn('password', 'password');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));

        $this->actingAs($user)->put('/backend/profile/password', [
            'current_password' => 'password',
            'password' => 'matkhaumoi1',
            'password_confirmation' => 'matkhaumoi1',
        ])->assertRedirect('/backend/profile')->assertSessionHas('success');

        $this->assertTrue(Hash::check('matkhaumoi1', $user->fresh()->password));
    }

    public function test_profile_errors_redisplay_submitted_values(): void
    {
        $user = User::factory()->superAdmin()->create(['name' => 'Tên Cũ', 'phone' => '0900']);
        User::factory()->create(['email' => 'trung@example.com']);

        $html = $this->actingAs($user)->from('/backend/profile')->followingRedirects()
            ->put('/backend/profile', ['name' => '', 'email' => 'trung@example.com', 'phone' => ''])->assertOk()->getContent();
        $this->assertStringContainsString('id="name" name="name" value=""', $html); // ô xóa trắng vẫn trống
        $this->assertStringContainsString('id="phone" name="phone" value=""', $html);
        $this->assertStringContainsString('value="trung@example.com"', $html);
        $this->assertStringContainsString('data-dirty', $html);
        $this->assertSame('Tên Cũ', $user->fresh()->name);
    }

    public function test_profile_page_renders(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user)->get('/backend/profile')->assertOk()->assertSee($user->email)
            ->assertSee('<h1 class="page-topbar-title">'.e($user->name).'</h1>', false)->assertSee('<span class="ant-tag ant-tag-gold">Root admin</span>', false)
            ->assertSee('id="profile-form"', false)->assertSee('id="password-form"', false)->assertSee('autocomplete="current-password"', false);
    }
}
