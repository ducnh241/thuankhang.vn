<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Project;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TagManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    public function test_permissions(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('tag')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $tag = Tag::factory()->create(['name' => 'Ngọc Quang']);

        $this->actingAs($noAccess)->get('/backend/tag')->assertForbidden();
        $this->actingAs($viewer)->get('/backend/tag')->assertOk()->assertSee('Ngọc Quang')
            // Chỉ xem: không hộp thêm / sửa, không chọn nhiều, không nút xóa, dòng không bấm được
            ->assertDontSee('id="tag-modal"', false)->assertDontSee('data-select-page', false)->assertDontSee('data-delete-url', false)
            ->assertDontSee('data-tag-open', false)->assertDontSee('data-row-click', false);
        $this->actingAs($viewer)->post('/backend/tag', ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->put("/backend/tag/{$tag->id}", ['name' => 'X'])->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/tag/{$tag->id}")->assertForbidden();
        $this->actingAs($viewer)->post('/backend/tag/bulk-destroy', ['ids' => [$tag->id]])->assertForbidden();
        $this->assertSame('Ngọc Quang', $tag->fresh()->name);

        // Sidebar: menu Tags nằm trong Projects, chỉ hiện khi có quyền
        $this->actingAs($viewer)->get('/backend')->assertSee(route('backend.tags.index'));
        $this->actingAs($noAccess)->get('/backend')->assertDontSee(route('backend.tags.index'));
    }

    public function test_create_generates_slug_and_default_color(): void
    {
        $root = $this->root();

        $this->actingAs($root)->from('/backend/tag')->post('/backend/tag', ['name' => 'Khách Ngọc Quang', 'slug' => '', 'color' => ''])
            ->assertRedirect('/backend/tag')->assertSessionHasNoErrors();
        $tag = Tag::sole();
        $this->assertSame('khach-ngoc-quang', $tag->slug);
        $this->assertSame('#6b7280', $tag->color);

        // Slug tự nhập cũng được chuẩn hóa; màu viết hoa đổi về chữ thường
        $this->actingAs($root)->post('/backend/tag', ['name' => 'Cloud', 'slug' => 'Cloud Flare!', 'color' => '#F68220'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tags', ['name' => 'Cloud', 'slug' => 'cloud-flare', 'color' => '#f68220']);
    }

    public function test_validation_reopens_dialog(): void
    {
        $root = $this->root();
        $existing = Tag::factory()->create(['name' => 'Ngọc Quang', 'slug' => 'ngoc-quang']);

        // Tên trùng → slug tự tạo trùng → báo ở ô tên (người dùng không gõ slug)
        $this->actingAs($root)->from('/backend/tag')->post('/backend/tag', ['name' => 'Ngọc  Quang'])
            ->assertRedirect('/backend/tag')->assertSessionHasErrorsIn('tag', 'name')->assertSessionDoesntHaveErrors('slug', null, 'tag');
        // (assertSessionHasErrorsIn làm rỗng túi lỗi cho request sau → gửi lại một lần để xem trang)
        $this->actingAs($root)->from('/backend/tag')->post('/backend/tag', ['name' => 'Ngọc  Quang']);
        $this->actingAs($root)->get('/backend/tag')->assertSee('data-reopen="new"', false)->assertSee('Đã có tag tên này (hoặc gần giống, cùng slug &quot;ngoc-quang&quot;).', false)
            // Lỗi chỉ hiện trong hộp thoại, không lặp ở thông báo đầu trang
            ->assertDontSee('Dữ liệu chưa hợp lệ');

        $this->actingAs($root)->post('/backend/tag', ['name' => '', 'color' => 'red'])->assertSessionHasErrorsIn('tag', ['name', 'color']);
        $this->actingAs($root)->post('/backend/tag', ['name' => '!!!'])->assertSessionHasErrorsIn('tag', 'name');
        // Tự gõ slug trùng → báo ở ô slug
        $this->actingAs($root)->post('/backend/tag', ['name' => 'Khác', 'slug' => 'ngoc-quang'])->assertSessionHasErrorsIn('tag', 'slug');
        $this->actingAs($root)->post('/backend/tag', ['name' => str_repeat('a', 101)])->assertSessionHasErrorsIn('tag', 'name');
        $this->actingAs($root)->post('/backend/tag', ['name' => ['x'], 'slug' => ['y'], 'color' => ['z']])->assertSessionHasErrorsIn('tag', ['name', 'slug']);
        $this->assertSame(1, Tag::count());

        // Sửa lỗi: mở lại đúng hộp thoại của tag đang sửa
        $other = Tag::factory()->create(['name' => 'MCP', 'slug' => 'mcp']);
        $this->actingAs($root)->from('/backend/tag')->put("/backend/tag/{$other->id}", ['name' => 'MCP', 'slug' => 'ngoc-quang']);
        $this->actingAs($root)->get('/backend/tag')->assertSee('data-reopen="'.$other->id.'"', false);
        $this->assertSame('mcp', $other->fresh()->slug);
        $this->assertSame('ngoc-quang', $existing->fresh()->slug);
    }

    public function test_concurrent_duplicate_slug_shows_error_instead_of_500(): void
    {
        $root = $this->root();
        // Giả lập người khác vừa lưu cùng slug sau khi request này đã qua bước kiểm tra trùng
        Tag::creating(function (Tag $tag) {
            if (! DB::table('tags')->where('slug', $tag->slug)->exists()) {
                DB::table('tags')->insert(['name' => 'Người khác', 'slug' => $tag->slug, 'color' => '#000000']);
            }
        });

        $this->actingAs($root)->from('/backend/tag')->post('/backend/tag', ['name' => 'Cloudflare'])
            ->assertRedirect('/backend/tag')->assertSessionHas('tag_dialog', 'new')->assertSessionHasErrorsIn('tag', 'slug');
        $this->assertSame(1, Tag::where('slug', 'cloudflare')->count());
    }

    public function test_html_in_names_is_escaped(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create(['name' => '<img src=x onerror=alert(1)>', 'slug' => 'xss']);
        Project::factory()->create()->tags()->attach($tag);

        foreach (['/backend/tag', '/backend/project'] as $url) {
            $this->actingAs($root)->get($url)->assertOk()
                ->assertDontSee('<img src=x onerror=alert(1)>', false)
                ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false);
        }
        $this->actingAs($root)->post('/backend/tag', ['name' => 'Ký hiệu', 'slug' => '!!!'])
            ->assertSessionHasErrorsIn('tag', ['slug' => 'Không tạo được slug, hãy nhập slug gồm chữ không dấu, số hoặc dấu gạch ngang.']);
    }

    public function test_update_keeps_own_slug(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create(['name' => 'MCP', 'slug' => 'mcp', 'color' => '#ff7300']);

        $this->actingAs($root)->put("/backend/tag/{$tag->id}", ['name' => 'MCP Server', 'slug' => 'mcp', 'color' => '#2563eb'])->assertSessionHasNoErrors();
        $this->assertSame(['MCP Server', 'mcp', '#2563eb'], [$tag->fresh()->name, $tag->fresh()->slug, $tag->fresh()->color]);
    }

    public function test_tag_used_by_projects_cannot_be_deleted(): void
    {
        $root = $this->root();
        [$used, $free, $free2] = Tag::factory()->count(3)->create();
        $project = Project::factory()->create();
        $project->tags()->attach($used->id);

        // Giao diện: tag đang dùng — nút xóa mờ kèm lý do, không chọn được để xóa hàng loạt; tag chưa dùng xóa bình thường
        $html = $this->actingAs($root)->get('/backend/tag')->assertOk()->getContent();
        $this->assertStringContainsString('<span data-tooltip="Đang gắn với 1 dự án, không xóa được (gỡ tag khỏi các dự án trước)"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled', $html);
        $this->assertStringNotContainsString(route('backend.tags.destroy', $used), $html);
        $this->assertStringContainsString(route('backend.tags.destroy', $free), $html);
        $this->assertMatchesRegularExpression('/value="'.$used->id.'" data-row-check disabled/', $html);
        $this->assertStringNotContainsString('name="ids[]" value="'.$used->id.'"', $html);

        // Server: xóa lẻ bị chặn, dự án vẫn giữ tag
        $this->actingAs($root)->from('/backend/tag')->delete("/backend/tag/{$used->id}")->assertRedirect('/backend/tag')
            ->assertSessionHas('error', "Không xóa được tag {$used->name}: đang gắn với 1 dự án. Gỡ tag khỏi các dự án trước.");
        $this->assertModelExists($used);
        $this->assertSame([$used->id], $project->tags()->pluck('tags.id')->all());

        // Xóa hàng loạt (gửi lén cả tag đang dùng): chỉ xóa tag chưa dùng, báo bỏ qua
        $this->actingAs($root)->post('/backend/tag/bulk-destroy', ['ids' => [$used->id, $free->id, 'abc']])
            ->assertSessionHas('success', 'Đã xóa 1 tag. Bỏ qua 1 tag đang gắn với dự án.');
        $this->assertModelExists($used);
        $this->assertModelMissing($free);
        $this->actingAs($root)->post('/backend/tag/bulk-destroy', ['ids' => [$used->id]])
            ->assertSessionHas('error', 'Không xóa được: 1 tag đang gắn với dự án. Gỡ tag khỏi các dự án trước.');
        $this->actingAs($root)->post('/backend/tag/bulk-destroy', ['ids' => []])->assertSessionHas('error', 'Chưa chọn tag nào.');

        // Gỡ tag khỏi dự án rồi thì xóa được
        $project->tags()->detach();
        $this->actingAs($root)->delete("/backend/tag/{$used->id}")->assertSessionHas('success');
        $this->assertModelMissing($used);
        $this->assertModelExists($project);
        $this->assertModelExists($free2);
    }

    public function test_select_page_is_disabled_when_every_tag_is_used(): void
    {
        $root = $this->root();
        $tag = Tag::factory()->create();
        $tag->projects()->attach(Project::factory()->create());

        $this->assertMatchesRegularExpression('/data-select-page aria-label="Chọn cả trang"\s+disabled/', $this->actingAs($root)->get('/backend/tag')->getContent());
    }

    public function test_dialog_reopens_with_entered_values_and_color(): void
    {
        $root = User::factory()->superAdmin()->create();
        Tag::factory()->create(['name' => 'Trùng', 'slug' => 'trung']);

        $this->actingAs($root)->from('/backend/tag')->post('/backend/tag', ['name' => 'Trùng', 'color' => '#13C2C2'])->assertRedirect('/backend/tag');
        $this->actingAs($root)->get('/backend/tag')->assertOk()
            ->assertSee('data-reopen="new"', false)
            ->assertSee('value="Trùng"', false)
            // Màu có sẵn trong bảng màu được đánh dấu, "Màu khác" giữ đúng mã màu
            ->assertSee('data-swatch="#13c2c2" role="radio" aria-checked="true"', false)
            ->assertSee('name="color" value="#13c2c2"', false);
    }

    public function test_list_is_sorted_by_name_without_search_or_filters(): void
    {
        $root = $this->root();
        $many = Tag::factory()->create(['name' => 'Zeta']);
        Tag::factory()->create(['name' => 'Alpha']);
        $many->projects()->attach(\App\Models\Project::factory()->count(2)->create());

        $this->actingAs($root)->get('/backend/tag')->assertOk()
            ->assertSeeInOrder(['Alpha', 'Zeta'])
            // Không còn ô tìm, tab lọc, nút sắp xếp
            ->assertDontSee('id="tag-search"', false)->assertDontSee('role="tablist"', false)->assertDontSee('data-filter="sort"', false)
            // Số dự án bấm được → trang dự án lọc theo tag
            ->assertSee(route('backend.projects.index', ['tag_id' => $many->id]))
            ->assertSee('Hiển thị 1–2 / 2 tag');
        // Tham số cũ trên URL bị bỏ qua, vẫn đủ tag theo tên
        $this->actingAs($root)->get('/backend/tag?q=zeta&tab=unused&sort=projects:desc')->assertOk()->assertSeeInOrder(['Alpha', 'Zeta']);
        $this->actingAs($root)->get('/backend/tag?page=5')->assertRedirect(route('backend.tags.index', ['page' => 1]));
    }
}
