<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Request bị sửa tay (ô chữ gửi dạng mảng, quyền gửi dạng chuỗi…): phải báo lỗi validate bình thường và trang quay lại
 * hiện được — không lỗi 500.
 */
class MalformedInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_text_fields_sent_as_arrays_redirect_back_without_server_error(): void
    {
        $root = User::factory()->superAdmin()->create();
        $create = route('backend.users.create');

        foreach (['name', 'email', 'phone', 'group_id'] as $field) {
            $this->actingAs($root)->from($create)
                ->post('/backend/users', ['name' => 'A', 'email' => 'a@b.vn', 'password' => 'secret-123', 'password_confirmation' => 'secret-123', $field => ['x']])
                ->assertRedirect($create)->assertSessionHasErrors($field);
            $this->actingAs($root)->get($create)->assertOk();
        }
        $this->assertSame(1, User::count());
    }

    public function test_array_fields_keep_their_old_input_after_errors(): void
    {
        $root = User::factory()->superAdmin()->create();
        $create = route('backend.groups.create');

        // Tên trùng → lỗi; quyền (mảng hợp lệ) vẫn được nạp lại vào form
        Group::factory()->create(['name' => 'Trùng']);
        $this->actingAs($root)->from($create)->post('/backend/groups', ['name' => 'Trùng', 'permission' => ['project' => ['_' => '1', 'add' => '1']]])
            ->assertSessionHasErrors('name');
        $this->assertMatchesRegularExpression('/name="permission\[project\]\[add\]" value="1" checked/', $this->actingAs($root)->get($create)->getContent());

        // Quyền gửi dạng chuỗi → lỗi, trang quay lại vẫn hiện
        $this->actingAs($root)->from($create)->post('/backend/groups', ['name' => 'Mới', 'permission' => 'abc'])
            ->assertSessionHasErrors('permission');
        $this->actingAs($root)->get($create)->assertOk();
        $this->assertSame(1, Group::count());
    }

    public function test_other_forms_survive_array_values(): void
    {
        $root = User::factory()->superAdmin()->create();

        $this->actingAs($root)->from('/backend/profile')
            ->put('/backend/profile/password', ['current_password' => ['x'], 'password' => 'abcdefgh1', 'password_confirmation' => 'abcdefgh1'])
            ->assertSessionHasErrorsIn('password', 'current_password');
        foreach (['name', 'email', 'phone', 'avatar', 'remove_avatar'] as $field) {
            $this->actingAs($root)->from('/backend/profile')->put('/backend/profile', ['name' => 'Tên', 'email' => $root->email, $field => ['x']])->assertStatus(302);
            $this->actingAs($root)->get('/backend/profile')->assertOk();
        }
        foreach (['name', 'slug', 'parent_id', 'status', 'description'] as $field) {
            $this->actingAs($root)->from('/backend/category?type=project&new=1')->post('/backend/category/project', ['name' => 'Tên', 'status' => '1', $field => ['x']])
                ->assertSessionHasErrors($field);
            $this->actingAs($root)->get('/backend/category?type=project&new=1')->assertOk();
        }
        $this->actingAs($root)->from('/backend/menu?position=main&new=1')->post('/backend/menu/main/item', ['label' => ['x'], 'link_type' => 'url', 'is_visible' => '1'])
            ->assertSessionHasErrors('label');
        $this->actingAs($root)->get('/backend/menu?position=main&new=1')->assertOk();
    }
}
