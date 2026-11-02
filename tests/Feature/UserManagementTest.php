<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function editor(): User
    {
        $group = Group::factory()->withPermissions('user', 'user/add', 'user/edit', 'user/delete')->create();

        return User::factory()->create(['group_id' => $group->id]);
    }

    public function test_user_without_permission_gets_403(): void
    {
        $group = Group::factory()->create();
        $user = User::factory()->create(['group_id' => $group->id]);

        $this->actingAs($user)->get('/backend/users')->assertForbidden();
        $this->actingAs($user)->get('/backend/users/create')->assertForbidden();
    }

    public function test_index_lists_and_searches_users(): void
    {
        $root = $this->root();
        User::factory()->create(['name' => 'Trần Văn Tìm', 'email' => 'tim@example.com']);
        User::factory()->create(['name' => 'Người Khác', 'email' => 'khac@example.com']);

        $this->actingAs($root)->get('/backend/users?q=tim')
            ->assertOk()
            ->assertSee('Trần Văn Tìm')
            ->assertDontSee('Người Khác');
    }

    public function test_root_can_create_user_with_avatar_and_group(): void
    {
        Storage::fake('public');
        $root = $this->root();
        $group = Group::factory()->create();

        $response = $this->actingAs($root)->post('/backend/users', [
            'name' => 'Nhân viên',
            'email' => 'nv@example.com',
            'phone' => '0900000000',
            'password' => 'matkhau123',
            'password_confirmation' => 'matkhau123',
            'group_id' => $group->id,
            'status' => '1',
            'is_super_admin' => '0',
            'avatar' => UploadedFile::fake()->image('a.png', 100, 100),
        ]);

        $user = User::where('email', 'nv@example.com')->firstOrFail();
        $response->assertRedirect(route('backend.users.edit', $user));
        $this->assertTrue(Hash::check('matkhau123', $user->password));
        $this->assertSame($group->id, $user->group_id);
        $this->assertFalse($user->is_super_admin);
        $this->assertTrue($user->status);
        Storage::disk('public')->assertExists($user->avatar);
    }

    public function test_create_validates_input(): void
    {
        $this->actingAs($this->root())
            ->from('/backend/users/create')
            ->post('/backend/users', ['name' => '', 'email' => 'sai', 'password' => 'short', 'password_confirmation' => 'khac'])
            ->assertRedirect('/backend/users/create')
            ->assertSessionHasErrors(['name', 'email', 'password']);
    }

    public function test_update_keeps_password_when_blank(): void
    {
        $root = $this->root();
        $user = User::factory()->create();
        $oldHash = $user->password;

        $this->actingAs($root)->put("/backend/users/{$user->id}", [
            'name' => 'Tên mới',
            'email' => $user->email,
            'password' => '',
            'password_confirmation' => '',
            'status' => '1',
        ])->assertRedirect(route('backend.users.edit', $user));

        $user->refresh();
        $this->assertSame('Tên mới', $user->name);
        $this->assertSame($oldHash, $user->password);
    }

    public function test_only_root_can_grant_root_admin(): void
    {
        $editor = $this->editor();
        $target = User::factory()->create();

        $this->actingAs($editor)->put("/backend/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'status' => '1', 'is_super_admin' => '1',
        ])->assertRedirect();
        $this->assertFalse($target->fresh()->is_super_admin);

        $this->actingAs($this->root())->put("/backend/users/{$target->id}", [
            'name' => $target->name, 'email' => $target->email, 'status' => '1', 'is_super_admin' => '1',
        ])->assertRedirect();
        $this->assertTrue($target->fresh()->is_super_admin);
    }

    public function test_non_root_cannot_edit_or_delete_root_admin(): void
    {
        $editor = $this->editor();
        $root = $this->root();

        $this->actingAs($editor)->get("/backend/users/{$root->id}/edit")->assertForbidden();
        $this->actingAs($editor)->delete("/backend/users/{$root->id}")->assertForbidden();
        $this->assertModelExists($root);
    }

    public function test_root_cannot_demote_or_lock_self(): void
    {
        $root = $this->root();

        $this->actingAs($root)->put("/backend/users/{$root->id}", [
            'name' => $root->name, 'email' => $root->email, 'status' => '0', 'is_super_admin' => '0',
        ])->assertRedirect();

        $root->refresh();
        $this->assertTrue($root->is_super_admin);
        $this->assertTrue($root->status);
    }

    public function test_cannot_delete_self_but_can_delete_others(): void
    {
        $root = $this->root();
        $other = User::factory()->create();

        $this->actingAs($root)->delete("/backend/users/{$root->id}")->assertSessionHas('error');
        $this->assertModelExists($root);

        $this->actingAs($root)->delete("/backend/users/{$other->id}")->assertRedirect('/backend/users');
        $this->assertModelMissing($other);
    }

    public function test_last_root_admin_cannot_be_demoted_or_deleted(): void
    {
        $root = $this->root();          // root duy nhất
        $editorRoot = User::factory()->superAdmin()->create(); // root thứ 2, dùng để thao tác

        // Còn 2 root → hạ root 1 được
        $this->actingAs($editorRoot)->put("/backend/users/{$root->id}", [
            'name' => $root->name, 'email' => $root->email, 'status' => '1', 'is_super_admin' => '0',
        ]);
        $this->assertFalse($root->fresh()->is_super_admin);

        // Giờ editorRoot là root cuối cùng: root (đã hạ) không sửa được editorRoot nữa, và chính editorRoot không tự hạ được
        $this->actingAs($editorRoot)->put("/backend/users/{$editorRoot->id}", [
            'name' => $editorRoot->name, 'email' => $editorRoot->email, 'status' => '1', 'is_super_admin' => '0',
        ]);
        $this->assertTrue($editorRoot->fresh()->is_super_admin);
    }

    public function test_delete_blocks_self_but_allows_other_accounts(): void
    {
        $root = $this->root();
        $other = User::factory()->create();
        $anotherRoot = User::factory()->superAdmin()->create();

        $this->actingAs($root)->delete("/backend/users/{$root->id}")->assertSessionHas('error');
        $this->actingAs($root)->delete("/backend/users/{$other->id}")->assertRedirect('/backend/users')->assertSessionHas('success');
        $this->actingAs($root)->delete("/backend/users/{$anotherRoot->id}")->assertSessionHas('success');

        $this->assertModelExists($root);          // không tự xóa mình
        $this->assertModelMissing($other);
        $this->assertModelMissing($anotherRoot);   // root được xóa root khác (root hiện tại vẫn còn)
    }

    public function test_non_root_cannot_delete_root_admins(): void
    {
        $editor = $this->editor();
        $root = $this->root();
        $other = User::factory()->create();

        $this->actingAs($editor)->delete("/backend/users/{$root->id}")->assertForbidden();
        $this->actingAs($editor)->delete("/backend/users/{$other->id}")->assertSessionHas('success');

        $this->assertModelExists($root);
        $this->assertModelMissing($other);
    }

    public function test_index_filters_by_status_tab(): void
    {
        $root = $this->root();
        User::factory()->create(['name' => 'Bị Khóa', 'status' => false]);
        User::factory()->create(['name' => 'Đang Hoạt Động']);

        $this->actingAs($root)->get('/backend/users?status=locked')
            ->assertOk()->assertSee('Bị Khóa')->assertDontSee('Đang Hoạt Động');
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        $root = $this->root();
        User::factory()->create(['name' => 'Có_gạch']);
        User::factory()->create(['name' => 'Không gạch']);

        $this->actingAs($root)->get('/backend/users?q=_')
            ->assertOk()->assertSee('Có_gạch')->assertDontSee('Không gạch');
        $this->actingAs($root)->get('/backend/users?q=%25')
            ->assertOk()->assertDontSee('Không gạch');
    }

    public function test_search_matches_email_and_phone(): void
    {
        $root = $this->root();
        User::factory()->create(['name' => 'Theo Email', 'email' => 'tim-email@example.com']);
        User::factory()->create(['name' => 'Theo Phone', 'phone' => '0987654321']);

        $this->actingAs($root)->get('/backend/users?q=tim-email')->assertSee('Theo Email')->assertDontSee('Theo Phone');
        $this->actingAs($root)->get('/backend/users?q=87654')->assertSee('Theo Phone')->assertDontSee('Theo Email');
    }

    public function test_status_tabs_keep_search_and_sort(): void
    {
        $root = $this->root();

        $group = Group::factory()->create();
        $html = $this->actingAs($root)->get("/backend/users?q=abc&sort=name:asc&group={$group->id}")->getContent();

        // Link tab "Đã khóa" giữ nguyên từ khóa, nhóm đang lọc và kiểu sắp xếp
        $this->assertStringContainsString('href="'.e(route('backend.users.index', ['q' => 'abc', 'group' => $group->id, 'sort' => 'name:asc', 'status' => 'locked'])).'" role="tab"', $html);
        // Tab không phải nút submit (tránh Enter trong ô tìm kiếm nhảy về tab đầu)
        $this->assertStringNotContainsString('name="status" value="all"', $html);
    }

    public function test_invalid_sort_and_status_fall_back_safely(): void
    {
        $this->actingAs($this->root())->get('/backend/users?sort=password:asc&status=xyz')->assertOk();
    }

    public function test_delete_returns_to_filtered_list(): void
    {
        $root = $this->root();
        $other = User::factory()->create();
        $from = route('backend.users.index', ['q' => 'abc', 'status' => 'active']);

        $this->actingAs($root)->from($from)->delete("/backend/users/{$other->id}")->assertRedirect($from);
    }

    public function test_delete_requires_permission(): void
    {
        $group = Group::factory()->withPermissions('user', 'user/edit')->create();
        $noDelete = User::factory()->create(['group_id' => $group->id]);
        $victim = User::factory()->create();

        $this->actingAs($noDelete)->delete("/backend/users/{$victim->id}")->assertForbidden();
        $this->assertModelExists($victim);
    }

    public function test_index_filters_by_group_and_counts_tabs(): void
    {
        $root = $this->root();
        $sales = Group::factory()->create(['name' => 'Kinh doanh']);
        User::factory()->create(['name' => 'Bán Hàng Một', 'group_id' => $sales->id]);
        User::factory()->create(['name' => 'Bán Hàng Khóa', 'group_id' => $sales->id, 'status' => false]);
        User::factory()->create(['name' => 'Không Nhóm']);

        $this->actingAs($root)->get("/backend/users?group={$sales->id}")->assertOk()
            ->assertSee('Bán Hàng Một')->assertSee('Bán Hàng Khóa')->assertDontSee('Không Nhóm')
            ->assertSee('Nhóm: Kinh doanh')
            ->assertSee('Tất cả <span class="product-view-count">2</span>', false)
            ->assertSee('Đã khóa <span class="product-view-count">1</span>', false);
        $this->actingAs($root)->get('/backend/users?group=none')->assertOk()->assertSee('Không Nhóm')->assertDontSee('Bán Hàng Một');
        // Nhóm không có / sai kiểu → bỏ qua, không lỗi
        $this->actingAs($root)->get('/backend/users?group=999')->assertOk()->assertSee('Bán Hàng Một')->assertSee('Không Nhóm');
        $this->actingAs($root)->get('/backend/users?group[]=1')->assertOk()->assertSee('Không Nhóm');
    }

    public function test_index_page_beyond_last_redirects_and_marks_self(): void
    {
        $root = $this->root();
        User::factory()->count(21)->create();

        $this->actingAs($root)->get('/backend/users?status=active&page=5')
            ->assertRedirect(route('backend.users.index', ['status' => 'active', 'page' => 2]));
        // Dòng của chính mình: nhãn "Bạn", nút xóa mờ kèm lý do
        $this->actingAs($root)->get('/backend/users?q='.urlencode($root->email))->assertOk()
            ->assertSee('<span class="ant-tag ant-tag-blue ml-1.5 align-middle">Bạn</span>', false)
            ->assertSee('<span data-tooltip="Không thể xóa tài khoản đang đăng nhập"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled', false);
    }

    public function test_deleting_from_own_edit_page_returns_to_list(): void
    {
        $root = $this->root();
        $other = User::factory()->create(['name' => 'Sẽ Bị Xóa']);

        $this->actingAs($root)->from(route('backend.users.edit', $other))->delete("/backend/users/{$other->id}")
            ->assertRedirect(route('backend.users.index'))->assertSessionHas('success', 'Đã xóa người dùng Sẽ Bị Xóa.');
    }

    public function test_switches_post_checkboxes_and_survive_validation_errors(): void
    {
        $root = $this->root();
        $user = User::factory()->create(['status' => true]);
        $edit = route('backend.users.edit', $user);

        $html = $this->actingAs($root)->get($edit)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="status" value="1" class="switch-input" hidden\s+checked/', $html);
        $this->assertMatchesRegularExpression('/<input type="checkbox" name="is_super_admin" value="1" class="switch-input" hidden\s+\/>/', $html);

        // Tắt Kích hoạt (checkbox không gửi) + email sai → quay lại form: công tắc vẫn tắt, không lấy giá trị đã lưu
        $this->actingAs($root)->from($edit)->put("/backend/users/{$user->id}", ['name' => $user->name, 'email' => 'sai'])->assertRedirect($edit);
        $html = $this->actingAs($root)->get($edit)->getContent();
        $this->assertStringContainsString('<button type="button" role="switch" aria-checked="false" id="user_active"', $html);
        $this->assertMatchesRegularExpression('/name="status" value="1" class="switch-input" hidden\s+\/>/', $html);

        // Gửi không có status → khóa
        $this->actingAs($root)->put("/backend/users/{$user->id}", ['name' => $user->name, 'email' => $user->email]);
        $this->assertFalse($user->fresh()->status);
    }

    public function test_non_root_form_hides_root_switch_and_self_switches_are_locked(): void
    {
        $root = $this->root();
        $editor = $this->editor();
        $weak = User::factory()->create();

        $this->actingAs($editor)->get("/backend/users/{$weak->id}/edit")->assertOk()
            ->assertDontSee('name="is_super_admin"', false)->assertSee('name="status"', false);
        $this->actingAs($root)->get("/backend/users/{$root->id}/edit")->assertOk()
            ->assertSee('data-tooltip="Không thể tự khóa tài khoản đang đăng nhập."', false)
            ->assertSee('data-tooltip="Không thể tự bỏ quyền Root admin của mình."', false);
    }

    public function test_remove_avatar_checkbox_deletes_file(): void
    {
        Storage::fake('public');
        $root = $this->root();
        $path = UploadedFile::fake()->image('a.png')->store('avatars', 'public');
        $user = User::factory()->create(['avatar' => $path]);

        $this->actingAs($root)->get("/backend/users/{$user->id}/edit")->assertOk()
            ->assertSee('data-current="'.Storage::disk('public')->url($path).'"', false)
            ->assertSee('<input type="checkbox" name="remove_avatar" value="1" id="remove-avatar" class="hidden" />', false);
        $this->actingAs($root)->put("/backend/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'status' => '1', 'remove_avatar' => '1']);

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_page_title_is_escaped_once(): void
    {
        $root = $this->root();
        $user = User::factory()->create(['name' => 'An & Bình <b>']);

        $this->actingAs($root)->get("/backend/users/{$user->id}/edit")->assertOk()
            ->assertSee('<title>An &amp; Bình &lt;b&gt; - '.config('app.name').'</title>', false);
    }

    public function test_avatar_upload_accepts_only_real_images_and_cleans_up(): void
    {
        Storage::fake('public');
        $root = $this->root();
        $user = User::factory()->create();
        $put = fn (array $extra) => $this->actingAs($root)->put("/backend/users/{$user->id}", ['name' => $user->name, 'email' => $user->email, 'status' => '1', ...$extra]);

        // Tệp thật (UploadedFile::fake đoán loại theo tên tệp, không theo nội dung như khi chạy thật)
        $real = function (string $name, string $content) {
            $path = tempnam(sys_get_temp_dir(), 'av');
            file_put_contents($path, $content);

            return new UploadedFile($path, $name, 'image/png', null, true);
        };
        // Mã PHP đổi đuôi .png, SVG (chứa được script), PDF, ảnh quá 2 MB: từ chối, không lưu tệp
        foreach ([
            $real('shell.png', "<?php echo 'x'; ?>"),
            $real('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->image('big.png')->size(2049),
        ] as $file) {
            try {
                $put(['avatar' => $file])->assertSessionHasErrors('avatar');
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $this->fail('Không báo lỗi với tệp '.$file->getClientOriginalName());
            }
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($user->fresh()->avatar);

        // Ảnh thật: tên ngẫu nhiên, đuôi theo nội dung (không theo tên gửi lên); thay ảnh thì xóa ảnh cũ
        $put(['avatar' => UploadedFile::fake()->image('../../hack.php.png', 50, 50)])->assertSessionHasNoErrors();
        $first = $user->fresh()->avatar;
        $this->assertMatchesRegularExpression('#^avatars/[A-Za-z0-9]{40}\.png$#', $first);
        $put(['avatar' => UploadedFile::fake()->image('b.jpg')])->assertSessionHasNoErrors();
        $second = $user->fresh()->avatar;
        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists($second);

        // Xóa người dùng → xóa luôn ảnh
        $this->actingAs($root)->delete("/backend/users/{$user->id}");
        Storage::disk('public')->assertMissing($second);
    }
}
