<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function root(): User
    {
        return User::factory()->superAdmin()->create();
    }

    private function page(string $query = ''): string
    {
        return '/backend/category'.($query !== '' ? '?'.$query : '');
    }

    /** Dữ liệu form đầy đủ (form luôn gửi trạng thái). */
    private function form(array $data): array
    {
        return ['slug' => '', 'parent_id' => '', 'status' => '1', 'description' => '', ...$data];
    }

    // ---- Phân quyền ----

    public function test_pages_require_permission(): void
    {
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('project_category')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $category = Category::factory()->create();
        $other = Category::factory()->create();

        $this->actingAs($noAccess)->get($this->page())->assertForbidden();
        $this->actingAs($noAccess)->get($this->page('type=project'))->assertForbidden();

        // Chỉ xem: thấy cây + form khóa (inert), không có nút Thêm / Xóa / dải lưu, không kéo thả được
        $this->actingAs($viewer)->get($this->page("type=project&id={$category->id}"))->assertOk()->assertSee($category->name)
            ->assertSee('Bạn chỉ có quyền xem danh mục.')->assertSee('<div class="ant-card ant-card-bordered"  inert >', false)
            ->assertDontSee('id="create-category"', false)->assertDontSee('data-delete-url', false)->assertDontSee('id="save-bar"', false)
            ->assertDontSee('draggable="true"', false)->assertDontSee('data-move-url', false)->assertDontSee('data-edit-form', false);
        // ?new không có quyền thêm: mở danh mục đầu tiên thay vì form trống
        $this->actingAs($viewer)->get($this->page('type=project&new'))->assertOk()->assertDontSee('Danh mục mới')
            ->assertSee('action="'.url('/backend/category/project').'/', false)->assertDontSee('action="'.route('backend.categories.store', 'project').'"', false);
        $this->actingAs($viewer)->get("/backend/category/project/create")->assertForbidden();
        $this->actingAs($viewer)->post('/backend/category/project', $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->put("/backend/category/project/{$category->id}", $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->postJson("/backend/category/project/{$category->id}/move", ['target_id' => $other->id, 'zone' => 'inside'])->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/category/project/{$category->id}")->assertForbidden();
        $this->assertModelExists($category);
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_cannot_touch_categories_of_other_types_via_url(): void
    {
        $postCategory = Category::factory()->create(['type' => Category::TYPE_POST, 'name' => 'Tin bài']);
        $projectCategory = Category::factory()->create(['name' => 'Bán hàng']);
        $root = $this->root();

        $this->actingAs($root)->get("/backend/category/project/{$postCategory->id}/edit")->assertNotFound();
        $this->actingAs($root)->put("/backend/category/project/{$postCategory->id}", $this->form(['name' => 'X', 'slug' => 'x']))->assertNotFound();
        $this->actingAs($root)->delete("/backend/category/project/{$postCategory->id}")->assertNotFound();
        $this->actingAs($root)->postJson("/backend/category/project/{$postCategory->id}/move", ['target_id' => $projectCategory->id, 'zone' => 'inside'])->assertNotFound();
        $this->actingAs($root)->postJson("/backend/category/project/{$projectCategory->id}/move", ['target_id' => $postCategory->id, 'zone' => 'inside'])->assertJsonValidationErrors('target_id');
        // Trang loại dự án: id của loại khác → mở danh mục dự án đầu tiên, không lộ danh mục tin tức
        $this->actingAs($root)->get($this->page("type=project&id={$postCategory->id}"))->assertDontSee('Tin bài')
            ->assertSee('<h1 class="page-topbar-title">Bán hàng</h1>', false);
        $this->actingAs($root)->get($this->page('type=news'))->assertSee('Tin bài')->assertDontSee('Bán hàng</span>', false);
        $this->assertModelExists($postCategory);
        $this->assertNull($projectCategory->fresh()->parent_id);
    }

    // ---- Tạo / sửa ----

    public function test_create_with_auto_slug_and_parent_goes_to_end_of_siblings(): void
    {
        $root = $this->root();
        $parent = Category::factory()->create(['name' => 'Bán hàng']);
        Category::factory()->create(['name' => 'Cũ', 'parent_id' => $parent->id, 'priority' => 9999]);

        $this->actingAs($root)->get($this->page('type=project&new'))->assertOk()->assertSee('<h1 class="page-topbar-title">Danh mục mới</h1>', false)
            ->assertSee('action="'.route('backend.categories.store', 'project').'"', false)->assertSee('VD: Bán hàng');
        $res = $this->actingAs($root)->post('/backend/category/project', $this->form(['name' => 'Thời trang Nữ', 'parent_id' => $parent->id, 'priority' => '-5', 'status' => '0']));

        $category = Category::where('name', 'Thời trang Nữ')->firstOrFail();
        $res->assertRedirect(route('backend.categories.index', ['type' => 'project', 'id' => $category->id]))->assertSessionHas('success', 'Đã tạo danh mục.');
        $this->assertSame(['thoi-trang-nu', Category::TYPE_PROJECT, $parent->id, 10000, false],
            [$category->slug, $category->type, $category->parent_id, $category->priority, $category->status]);
        // Trang sau khi tạo: mở đúng danh mục vừa tạo, trạng thái Ẩn
        $this->actingAs($root)->get($this->page("type=project&id={$category->id}"))
            ->assertSee('<h1 class="page-topbar-title">Thời trang Nữ</h1>', false)->assertSee('<span class="ant-tag">Ẩn</span>', false)
            ->assertSee('<option value="0" selected>Ẩn</option>', false);
    }

    public function test_update_keeps_order_unless_parent_changes(): void
    {
        $root = $this->root();
        $a = Category::factory()->create(['name' => 'A', 'priority' => 1]);
        $b = Category::factory()->create(['name' => 'B', 'priority' => 2]);
        $child = Category::factory()->create(['name' => 'Con', 'parent_id' => $a->id, 'priority' => 7]);

        $this->actingAs($root)->put("/backend/category/project/{$child->id}", $this->form(['name' => 'Con mới', 'slug' => 'con-moi', 'parent_id' => (string) $a->id]))
            ->assertRedirect(route('backend.categories.index', ['type' => 'project', 'id' => $child->id]))->assertSessionHas('success', 'Đã lưu danh mục.');
        $this->assertSame(['Con mới', 7], [$child->fresh()->name, $child->fresh()->priority]);

        // Đổi cha → xuống cuối nhóm mới; về cấp gốc → sau A, B
        $this->actingAs($root)->put("/backend/category/project/{$child->id}", $this->form(['name' => 'Con mới', 'slug' => 'con-moi', 'parent_id' => '']));
        $this->assertSame([null, 3], [$child->fresh()->parent_id, $child->fresh()->priority]);
        $this->assertSame([1, 2], [$a->fresh()->priority, $b->fresh()->priority]);
    }

    public function test_slug_unique_within_type_only(): void
    {
        $root = $this->root();
        Category::factory()->create(['slug' => 'tin-tuc']);
        Category::factory()->create(['slug' => 'khac', 'type' => Category::TYPE_POST]);

        $this->actingAs($root)->post('/backend/category/project', $this->form(['name' => 'Tin tức']))->assertSessionHasErrors('slug');
        $this->actingAs($root)->post('/backend/category/project', $this->form(['name' => 'Khác']))->assertSessionHasNoErrors();
    }

    public function test_validation_errors_stay_on_the_same_category(): void
    {
        $root = $this->root();
        Category::factory()->create(['slug' => 'trung', 'name' => 'Trùng']);
        $category = Category::factory()->create(['name' => 'Đang sửa', 'slug' => 'dang-sua']);

        $html = $this->actingAs($root)->from($this->page("type=project&id={$category->id}"))->followingRedirects()
            ->put("/backend/category/project/{$category->id}", $this->form(['name' => '', 'slug' => 'trung', 'status' => '0']))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('backend.categories.update', ['project', $category]).'"', $html);
        $this->assertStringContainsString('data-dirty', $html);
        $this->assertStringContainsString('Vui lòng nhập tên danh mục.', $html);
        // Ô tên xóa trắng vẫn trống (không hiện lại tên đã lưu ngay trên dòng báo lỗi)
        $this->assertStringContainsString('id="category_name" name="name" value=""', $html);
        $this->assertStringContainsString('value="trung"', $html);
        $this->assertStringContainsString('<option value="0" selected>Ẩn</option>', $html);
        $this->assertSame(['Đang sửa', 'dang-sua'], [$category->fresh()->name, $category->fresh()->slug]);

        foreach (['status' => 'abc', 'parent_id' => 'x'] as $field => $value) {
            $this->actingAs($root)->put("/backend/category/project/{$category->id}", $this->form(['name' => 'A', $field => $value]))->assertSessionHasErrors($field);
        }
    }

    public function test_parent_cannot_be_self_or_descendant(): void
    {
        $root = $this->root();
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);
        $c = Category::factory()->create(['parent_id' => $b->id]);

        foreach ([$a->id, $c->id] as $parentId) {
            $this->actingAs($root)->put("/backend/category/project/{$a->id}", $this->form(['name' => $a->name, 'slug' => $a->slug, 'parent_id' => $parentId]))
                ->assertSessionHasErrors('parent_id');
        }
        $this->assertNull($a->fresh()->parent_id);

        // Form sửa không đưa chính nó / con cháu vào ô chọn cha
        $html = $this->actingAs($root)->get($this->page("type=project&id={$a->id}"))->getContent();
        $form = substr($html, strpos($html, 'id="category_parent"'));
        $form = substr($form, 0, strpos($form, '</select>'));
        $this->assertStringNotContainsString('<option value="'.$c->id.'"', $form);
        $this->assertStringNotContainsString('<option value="'.$a->id.'"', $form);
    }

    public function test_parent_must_be_same_type(): void
    {
        $postCategory = Category::factory()->create(['type' => Category::TYPE_POST]);

        $this->actingAs($this->root())->post('/backend/category/project', $this->form(['name' => 'Con', 'parent_id' => $postCategory->id]))
            ->assertSessionHasErrors('parent_id');
    }

    // ---- Cây danh mục ----

    public function test_tree_order_counts_and_selection(): void
    {
        $root = $this->root();
        $parent = Category::factory()->create(['name' => 'Cha A', 'priority' => 1]);
        $child = Category::factory()->create(['name' => 'Con A1', 'parent_id' => $parent->id]);
        $hidden = Category::factory()->create(['name' => 'Cha B', 'priority' => 2, 'status' => false]);
        Project::factory()->count(2)->create(['cat_id' => $child->id]);
        Project::factory()->create(['cat_id' => $child->id])->delete(); // đã xóa: không đếm

        // Không có id: mở danh mục đầu tiên của cây
        $html = $this->actingAs($root)->get($this->page())->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="page-topbar-title">Cha A</h1>', $html);
        $this->assertMatchesRegularExpression('#data-id="'.$parent->id.'" data-depth="0" data-parent="" role="treeitem" aria-level="1"\s+aria-selected="true"\s+aria-expanded="true"#', $html);
        $this->assertMatchesRegularExpression('#data-id="'.$child->id.'" data-depth="1" data-parent="'.$parent->id.'" role="treeitem" aria-level="2"\s+aria-selected="false"\s*>#', $html);
        $this->assertTrue(strpos($html, '>Cha A<') < strpos($html, '>Con A1<') && strpos($html, '>Con A1<') < strpos($html, '>Cha B<'), 'Con nằm ngay dưới cha');
        $this->assertStringContainsString('style="margin-left: 24px"', $html);
        $this->assertStringContainsString('title="2 dự án">(2)</span>', $html);
        $this->assertMatchesRegularExpression('#>Cha B</span>\s*(<span[^>]*>\(0\)</span>)?\s*<span class="ant-tag cat-tag">Ẩn</span>#', $html);

        // Mở danh mục con: dòng đó được chọn, cuối form có link sang danh sách dự án thuộc danh mục
        $html = $this->actingAs($root)->get($this->page("type=project&id={$child->id}"))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="cat-row is-selected"', $html);
        $this->assertStringContainsString('<a href="'.route('backend.projects.index', ['cat_id' => $child->id]).'">2 dự án</a> thuộc danh mục này', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        // Có dự án (kể cả đã xóa mềm) → nút Xóa bị khóa, có lý do
        $this->assertStringContainsString('data-tooltip="Không xóa được: còn 3 dự án thuộc danh mục này"', $html);
        $this->assertModelExists($hidden);
    }

    public function test_tree_survives_parent_cycle_and_orphans(): void
    {
        $a = Category::factory()->create(['name' => 'A']);
        $b = Category::factory()->create(['name' => 'B', 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]); // dữ liệu lỗi: vòng lặp A ↔ B

        $tree = Category::tree(Category::TYPE_PROJECT);
        $this->assertCount(2, $tree);
        $this->assertSame([$a->id, $b->id], $tree->pluck('id')->sort()->values()->all());
        $this->actingAs($this->root())->get($this->page())->assertOk()->assertSee('>A<', false)->assertSee('>B<', false);
    }

    // ---- Kéo thả ----

    public function test_move_reorders_siblings_and_nests(): void
    {
        $root = $this->root();
        // Thứ tự đang hiện: priority rồi tên (dữ liệu cũ đều 9999 → theo tên)
        [$a, $b, $c] = collect(['A', 'B', 'C'])->map(fn ($n) => Category::factory()->create(['name' => $n, 'priority' => 9999]))->all();
        $order = fn (?int $parent = null) => Category::where('type', Category::TYPE_PROJECT)->where('parent_id', $parent)
            ->orderBy('priority')->orderBy('name')->pluck('name')->all();
        $move = fn (Category $c, Category $target, string $zone) => $this->actingAs($root)
            ->postJson("/backend/category/project/{$c->id}/move", ['target_id' => $target->id, 'zone' => $zone]);

        $move($c, $a, 'before')->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['C', 'A', 'B'], $order());
        $this->assertSame([1, 2, 3], [$c->fresh()->priority, $a->fresh()->priority, $b->fresh()->priority]);
        $this->assertSame('Đã chuyển “C”.', session('success'));

        $move($c, $b, 'after')->assertOk();
        $this->assertSame(['A', 'B', 'C'], $order());

        // Vào trong: thành con cuối của B
        Category::factory()->create(['name' => 'B1', 'parent_id' => $b->id, 'priority' => 1]);
        $move($a, $b, 'inside')->assertOk();
        $this->assertSame([$b->id, ['B1', 'A']], [$a->fresh()->parent_id, $order($b->id)]);
        $this->assertSame(['B', 'C'], $order());

        // Kéo ra ngoài: đặt trước C ở cấp gốc
        $move($a, $c, 'before')->assertOk();
        $this->assertSame([null, ['B', 'A', 'C'], ['B1']], [$a->fresh()->parent_id, $order(), $order($b->id)]);
    }

    public function test_move_rejects_own_subtree_and_bad_input(): void
    {
        $root = $this->root();
        $a = Category::factory()->create(['name' => 'A']);
        $b = Category::factory()->create(['name' => 'B', 'parent_id' => $a->id]);
        $c = Category::factory()->create(['name' => 'C', 'parent_id' => $b->id]);

        foreach ([[$a, $a, 'inside'], [$a, $c, 'inside'], [$a, $b, 'before']] as [$from, $to, $zone]) {
            $this->actingAs($root)->postJson("/backend/category/project/{$from->id}/move", ['target_id' => $to->id, 'zone' => $zone])
                ->assertStatus(422)->assertJson(['message' => 'Không thể chuyển danh mục vào chính nó hoặc danh mục con của nó.']);
        }
        $this->actingAs($root)->postJson("/backend/category/project/{$b->id}/move", ['target_id' => $a->id, 'zone' => 'top'])->assertJsonValidationErrors('zone');
        $this->actingAs($root)->postJson("/backend/category/project/{$b->id}/move", ['target_id' => ['x'], 'zone' => 'before'])->assertJsonValidationErrors('target_id');
        $this->actingAs($root)->postJson("/backend/category/project/{$b->id}/move", ['target_id' => 99999, 'zone' => 'before'])->assertJsonValidationErrors('target_id');
        $this->assertSame([null, $a->id, $b->id], [$a->fresh()->parent_id, $b->fresh()->parent_id, $c->fresh()->parent_id]);
    }

    // ---- Xóa ----

    public function test_cannot_delete_category_in_use_or_with_children(): void
    {
        $root = $this->root();
        $used = Category::factory()->create();
        Project::factory()->create(['cat_id' => $used->id])->delete(); // kể cả dự án đã xóa mềm vẫn giữ cat_id
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        $free = Category::factory()->create(['name' => 'Xóa được']);

        $this->actingAs($root)->delete("/backend/category/project/{$used->id}")->assertSessionHas('error');
        $this->actingAs($root)->delete("/backend/category/project/{$parent->id}")->assertSessionHas('error');
        $this->assertModelExists($used);
        $this->assertModelExists($parent);
        $this->actingAs($root)->get($this->page("type=project&id={$parent->id}"))->assertSee('data-tooltip="Không xóa được: còn danh mục con"', false);

        $this->actingAs($root)->get($this->page("type=project&id={$free->id}"))
            ->assertSee('data-delete-url="'.route('backend.categories.destroy', ['project', $free]).'" data-delete-title="Xóa danh mục “Xóa được”?"', false);
        $this->actingAs($root)->delete("/backend/category/project/{$free->id}")
            ->assertRedirect(route('backend.categories.index', ['type' => 'project']))->assertSessionHas('success');
        $this->assertModelMissing($free);
    }

    // ---- Tích hợp với dự án ----

    public function test_project_filter_by_category_includes_children(): void
    {
        $root = $this->root();
        $parent = Category::factory()->create();
        $child = Category::factory()->create(['parent_id' => $parent->id]);
        $other = Category::factory()->create();
        Project::factory()->create(['name' => 'Dự án Cha', 'cat_id' => $parent->id]);
        Project::factory()->create(['name' => 'Dự án Con', 'cat_id' => $child->id]);
        Project::factory()->create(['name' => 'Dự án Khác', 'cat_id' => $other->id]);
        Project::factory()->create(['name' => 'Chưa Phân Loại', 'cat_id' => null]);

        $this->actingAs($root)->get("/backend/project?cat_id={$parent->id}")
            ->assertSee('Dự án Cha')->assertSee('Dự án Con')->assertDontSee('Dự án Khác')->assertDontSee('Chưa Phân Loại');
        $this->actingAs($root)->get("/backend/project?cat_id={$child->id}")
            ->assertSee('Dự án Con')->assertDontSee('Dự án Cha');
    }

    public function test_project_form_saves_and_validates_category(): void
    {
        $root = $this->root();
        $category = Category::factory()->create();
        $postCategory = Category::factory()->create(['type' => Category::TYPE_POST]);
        $project = Project::factory()->create();
        $payload = fn ($catId) => ['customer_id' => $project->customer_id, 'name' => $project->name, 'primary_domain' => $project->primary_domain, 'status' => 'delivered', 'cat_id' => $catId];

        $this->actingAs($root)->put("/backend/project/{$project->id}", $payload($category->id))->assertSessionHasNoErrors();
        $this->assertSame($category->id, $project->fresh()->cat_id);

        $this->actingAs($root)->put("/backend/project/{$project->id}", $payload($postCategory->id))->assertSessionHasErrors('cat_id');

        $this->actingAs($root)->put("/backend/project/{$project->id}", $payload(''))->assertSessionHasNoErrors();
        $this->assertNull($project->fresh()->cat_id);

        // Trang chi tiết + lịch sử hiển thị tên danh mục, không phải id; form dự án có link "Quản lý danh mục"
        $project->update(['cat_id' => $category->id]);
        $this->actingAs($root)->get("/backend/project/{$project->id}")->assertSee($category->name);
        $this->actingAs($root)->get("/backend/project/{$project->id}/edit")->assertSee('href="'.route('backend.categories.index', ['type' => 'project']).'"', false);
    }

    // ---- Menu, đổi loại ----

    public function test_menu_shows_one_categories_item_with_any_permission(): void
    {
        $projectOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('project')->create()->id]);
        $newsOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('news_category')->create()->id]);
        $url = route('backend.categories.index');

        $this->actingAs($projectOnly)->get('/backend/project')->assertSee(route('backend.projects.index'))->assertDontSee('>Categories<', false);
        $this->actingAs($newsOnly)->get('/backend')->assertSee('<a href="'.$url.'" ><span class="mr-auto">Categories</span></a>', false);
        $this->actingAs($this->root())->get('/backend')->assertSee('<a href="'.$url.'" ><span class="mr-auto">Categories</span></a>', false);
        // Đang ở trang danh mục (bất kỳ loại nào): mục được chọn
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote($url, '#').'"\s+aria-current="page"\s*><span class="mr-auto">Categories</span></a>#',
            $this->actingAs($this->root())->get($this->page('type=news'))->getContent());
    }

    public function test_type_switch_lists_only_permitted_kinds(): void
    {
        $root = $this->root();
        Category::factory()->create(['name' => 'Bán hàng']);
        $news = Category::factory()->create(['type' => Category::TYPE_POST, 'name' => 'Công nghệ']);
        $newsOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('news_category', 'news_category/add')->create()->id]);

        // Cả hai quyền: ô chọn loại có 2 lựa chọn, đang chọn đúng loại
        $html = $this->actingAs($root)->get($this->page('type=news'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="project" data-url="'.route('backend.categories.index', ['type' => 'project']).'" >Danh mục dự án</option>', $html);
        $this->assertStringContainsString('<option value="news" data-url="'.route('backend.categories.index', ['type' => 'news']).'" selected>Danh mục tin tức</option>', $html);
        $this->assertStringContainsString('dùng cho <strong>bài viết tin tức</strong>', $html);
        $this->assertStringNotContainsString('title="0 dự án"', $html); // tin tức chưa có bảng dùng: không đếm

        // Chỉ quyền tin tức: không có ô chọn loại; ?type=project bị bỏ qua (mở tin tức)
        $html = $this->actingAs($newsOnly)->get($this->page('type=project'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="category_type"', $html);
        $this->assertStringContainsString('<h1 class="page-topbar-title">Công nghệ</h1>', $html);
        $this->assertStringNotContainsString('Bán hàng', $html);
        $this->assertStringContainsString('id="create-category"', $html);
        $this->assertModelExists($news);
    }

    public function test_news_categories_have_their_own_permission(): void
    {
        $projectCat = User::factory()->create(['group_id' => Group::factory()->withPermissions('project_category', 'project_category/add', 'project_category/edit', 'project_category/delete')->create()->id]);
        $news = Category::factory()->create(['type' => Category::TYPE_POST, 'name' => 'Công nghệ', 'slug' => 'cong-nghe']);
        $projectCategory = Category::factory()->create();

        $this->actingAs($projectCat)->get($this->page('type=news'))->assertOk()->assertDontSee('Công nghệ');
        $this->actingAs($projectCat)->get('/backend/category/news')->assertForbidden();
        $this->actingAs($projectCat)->get('/backend/category/news/create')->assertForbidden();
        $this->actingAs($projectCat)->post('/backend/category/news', $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($projectCat)->put("/backend/category/news/{$news->id}", $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($projectCat)->postJson("/backend/category/news/{$news->id}/move", ['target_id' => $news->id, 'zone' => 'before'])->assertForbidden();
        $this->actingAs($projectCat)->delete("/backend/category/news/{$news->id}")->assertForbidden();
        // …và cũng không lách qua URL loại dự án
        $this->actingAs($projectCat)->put("/backend/category/project/{$news->id}", $this->form(['name' => 'X', 'slug' => 'x']))->assertNotFound();
        $this->actingAs($projectCat)->delete("/backend/category/project/{$news->id}")->assertNotFound();
        $this->actingAs($projectCat)->postJson("/backend/category/project/{$projectCategory->id}/move", ['target_id' => $news->id, 'zone' => 'inside'])->assertJsonValidationErrors('target_id');
        $this->assertSame(['Công nghệ', 'cong-nghe'], [$news->fresh()->name, $news->fresh()->slug]);
    }

    public function test_news_crud(): void
    {
        $root = $this->root();
        Category::factory()->create(['name' => 'Bán hàng', 'slug' => 'ban-hang']); // danh mục dự án trùng slug

        // Chưa có danh mục tin tức: trang mở thẳng form thêm mới
        $this->actingAs($root)->get($this->page('type=news'))->assertOk()->assertSee('Chưa có danh mục nào')
            ->assertSee('action="'.route('backend.categories.store', 'news').'"', false)->assertSee('VD: Công nghệ');

        // Tạo: cùng slug với danh mục dự án vẫn được (slug duy nhất trong từng loại)
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Bán hàng', 'description' => 'Tin bán hàng']))->assertSessionHasNoErrors();
        $parent = Category::where('type', Category::TYPE_POST)->where('slug', 'ban-hang')->firstOrFail();
        $this->assertSame([1, 'Tin bán hàng'], [$parent->priority, $parent->description]);
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Bán hàng']))->assertSessionHasErrors('slug');

        // Con: cha phải là danh mục tin tức
        $projectCat = Category::where('type', Category::TYPE_PROJECT)->firstOrFail();
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Mẹo', 'parent_id' => $projectCat->id]))->assertSessionHasErrors('parent_id');
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Mẹo bán hàng', 'parent_id' => $parent->id]))->assertSessionHasNoErrors();
        $child = Category::where('slug', 'meo-ban-hang')->firstOrFail();
        $this->assertSame([Category::TYPE_POST, $parent->id], [$child->type, $child->parent_id]);

        // Trang: cây chỉ có danh mục tin tức; ô chọn cha không có danh mục dự án / chính nó
        $html = $this->actingAs($root)->get($this->page("type=news&id={$child->id}"))->assertOk()->getContent();
        $tree = substr($html, strpos($html, 'id="category-tree"'));
        $this->assertTrue(strpos($tree, '>Mẹo bán hàng<') > strpos($tree, '>Bán hàng<'), 'Con nằm dưới cha');
        $this->assertSame(0, substr_count($tree, 'data-id="'.$projectCat->id.'"'));
        $select = substr($html, strpos($html, 'id="category_parent"'));
        $select = substr($select, 0, strpos($select, '</select>'));
        $this->assertStringContainsString('<option value="'.$parent->id.'" selected>Bán hàng</option>', $select);
        $this->assertStringNotContainsString('value="'.$projectCat->id.'"', $select);
        $this->assertStringNotContainsString('value="'.$child->id.'"', $select);
        $this->assertStringNotContainsString('class="cat-usage"', $html);

        $this->actingAs($root)->put("/backend/category/news/{$parent->id}", $this->form(['name' => 'Kinh doanh', 'slug' => 'kinh-doanh', 'parent_id' => $child->id]))
            ->assertSessionHasErrors('parent_id');
        $this->actingAs($root)->put("/backend/category/news/{$child->id}", $this->form(['name' => 'Mẹo hay', 'slug' => 'meo-hay']))
            ->assertSessionHasNoErrors()->assertRedirect(route('backend.categories.index', ['type' => 'news', 'id' => $child->id]));
        $this->assertSame(['Mẹo hay', 'meo-hay', null], [$child->fresh()->name, $child->fresh()->slug, $child->fresh()->parent_id]);

        // Xóa: chặn khi còn con; xóa được khi trống; không đụng danh mục dự án cùng slug
        $this->actingAs($root)->postJson("/backend/category/news/{$child->id}/move", ['target_id' => $parent->id, 'zone' => 'inside'])->assertOk();
        $this->actingAs($root)->delete("/backend/category/news/{$parent->id}")->assertSessionHas('error', 'Không xóa được "Bán hàng": còn danh mục con.');
        $this->actingAs($root)->delete("/backend/category/news/{$child->id}")->assertSessionHas('success');
        $this->actingAs($root)->delete("/backend/category/news/{$parent->id}")->assertSessionHas('success');
        $this->assertSame(0, Category::where('type', Category::TYPE_POST)->count());
        $this->assertModelExists($projectCat);
    }

    public function test_unknown_kind_odd_query_and_old_urls(): void
    {
        $root = $this->root();
        $category = Category::factory()->create(['name' => 'Bán hàng']);

        $this->actingAs($root)->get('/backend/category/event')->assertNotFound();
        $this->actingAs($root)->get('/backend/category/store/create')->assertNotFound();
        foreach (['type=event', 'type[]=news', 'id[]=1', 'id=abc', 'id=99999', 'id=-1'] as $query) {
            $this->actingAs($root)->get($this->page($query))->assertOk()->assertSee('<h1 class="page-topbar-title">Bán hàng</h1>', false);
        }
        // URL cũ chuyển sang trang gộp
        $this->actingAs($root)->get('/backend/category/project')->assertRedirect(route('backend.categories.index', ['type' => 'project']));
        $this->actingAs($root)->get('/backend/category/news/create')->assertRedirect(route('backend.categories.index', ['type' => 'news', 'new' => 1]));
        $this->actingAs($root)->get("/backend/category/project/{$category->id}/edit")->assertRedirect(route('backend.categories.index', ['type' => 'project', 'id' => $category->id]));
        $this->actingAs($root)->get('/backend/project-category')->assertRedirect(route('backend.categories.index', ['type' => 'project']));
    }
}
