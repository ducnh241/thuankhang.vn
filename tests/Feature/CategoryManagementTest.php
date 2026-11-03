<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
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
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('news_category')->create()->id]);
        $noAccess = User::factory()->create(['group_id' => Group::factory()->withPermissions('news')->create()->id]);
        $category = Category::factory()->create();
        $other = Category::factory()->create();

        $this->actingAs($noAccess)->get($this->page())->assertForbidden();
        $this->actingAs($noAccess)->get($this->page('type=news'))->assertForbidden();

        // Chỉ xem: thấy cây + form khóa (inert), không có nút Thêm / Xóa / dải lưu, không kéo thả được
        $this->actingAs($viewer)->get($this->page("type=news&id={$category->id}"))->assertOk()->assertSee($category->name)
            ->assertSee('Bạn chỉ có quyền xem danh mục.')->assertSee('<div class="ant-card ant-card-bordered"  inert >', false)
            ->assertDontSee('id="create-category"', false)->assertDontSee('data-delete-url', false)->assertDontSee('id="save-bar"', false)
            ->assertDontSee('draggable="true"', false)->assertDontSee('data-move-url', false)->assertDontSee('data-edit-form', false);
        // ?new không có quyền thêm: mở danh mục đầu tiên thay vì form trống
        $this->actingAs($viewer)->get($this->page('type=news&new'))->assertOk()->assertDontSee('Danh mục mới')
            ->assertSee('action="'.url('/backend/category/news').'/', false)->assertDontSee('action="'.route('backend.categories.store', 'news').'"', false);
        $this->actingAs($viewer)->get('/backend/category/news/create')->assertForbidden();
        $this->actingAs($viewer)->post('/backend/category/news', $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->put("/backend/category/news/{$category->id}", $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->postJson("/backend/category/news/{$category->id}/move", ['target_id' => $other->id, 'zone' => 'inside'])->assertForbidden();
        $this->actingAs($viewer)->delete("/backend/category/news/{$category->id}")->assertForbidden();
        $this->assertModelExists($category);
        $this->assertNull($category->fresh()->parent_id);
    }

    public function test_cannot_touch_categories_of_other_types_via_url(): void
    {
        $otherCategory = Category::factory()->create(['type' => Category::TYPE_EVENT, 'name' => 'Tin bài']);
        $newsCategory = Category::factory()->create(['name' => 'Bán hàng']);
        $root = $this->root();

        $this->actingAs($root)->get("/backend/category/news/{$otherCategory->id}/edit")->assertNotFound();
        $this->actingAs($root)->put("/backend/category/news/{$otherCategory->id}", $this->form(['name' => 'X', 'slug' => 'x']))->assertNotFound();
        $this->actingAs($root)->delete("/backend/category/news/{$otherCategory->id}")->assertNotFound();
        $this->actingAs($root)->postJson("/backend/category/news/{$otherCategory->id}/move", ['target_id' => $newsCategory->id, 'zone' => 'inside'])->assertNotFound();
        $this->actingAs($root)->postJson("/backend/category/news/{$newsCategory->id}/move", ['target_id' => $otherCategory->id, 'zone' => 'inside'])->assertJsonValidationErrors('target_id');
        // id của loại khác → mở danh mục tin tức đầu tiên, không lộ danh mục loại khác
        $this->actingAs($root)->get($this->page("type=news&id={$otherCategory->id}"))->assertDontSee('Tin bài')
            ->assertSee('<h1 class="page-topbar-title">Bán hàng</h1>', false);
        $this->assertModelExists($otherCategory);
        $this->assertNull($newsCategory->fresh()->parent_id);
    }

    // ---- Tạo / sửa ----

    public function test_create_with_auto_slug_and_parent_goes_to_end_of_siblings(): void
    {
        $root = $this->root();
        $parent = Category::factory()->create(['name' => 'Bán hàng']);
        Category::factory()->create(['name' => 'Cũ', 'parent_id' => $parent->id, 'priority' => 9999]);

        $this->actingAs($root)->get($this->page('type=news&new'))->assertOk()->assertSee('<h1 class="page-topbar-title">Danh mục mới</h1>', false)
            ->assertSee('action="'.route('backend.categories.store', 'news').'"', false)->assertSee('VD: Công nghệ');
        $res = $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Thời trang Nữ', 'parent_id' => $parent->id, 'priority' => '-5', 'status' => '0']));

        $category = Category::where('name', 'Thời trang Nữ')->firstOrFail();
        $res->assertRedirect(route('backend.categories.index', ['type' => 'news', 'id' => $category->id]))->assertSessionHas('success', 'Đã tạo danh mục.');
        $this->assertSame(['thoi-trang-nu', Category::TYPE_POST, $parent->id, 10000, false],
            [$category->slug, $category->type, $category->parent_id, $category->priority, $category->status]);
        // Trang sau khi tạo: mở đúng danh mục vừa tạo, trạng thái Ẩn
        $this->actingAs($root)->get($this->page("type=news&id={$category->id}"))
            ->assertSee('<h1 class="page-topbar-title">Thời trang Nữ</h1>', false)->assertSee('<span class="ant-tag">Ẩn</span>', false)
            ->assertSee('<option value="0" selected>Ẩn</option>', false);
    }

    public function test_update_keeps_order_unless_parent_changes(): void
    {
        $root = $this->root();
        $a = Category::factory()->create(['name' => 'A', 'priority' => 1]);
        $b = Category::factory()->create(['name' => 'B', 'priority' => 2]);
        $child = Category::factory()->create(['name' => 'Con', 'parent_id' => $a->id, 'priority' => 7]);

        $this->actingAs($root)->put("/backend/category/news/{$child->id}", $this->form(['name' => 'Con mới', 'slug' => 'con-moi', 'parent_id' => (string) $a->id]))
            ->assertRedirect(route('backend.categories.index', ['type' => 'news', 'id' => $child->id]))->assertSessionHas('success', 'Đã lưu danh mục.');
        $this->assertSame(['Con mới', 7], [$child->fresh()->name, $child->fresh()->priority]);

        // Đổi cha → xuống cuối nhóm mới; về cấp gốc → sau A, B
        $this->actingAs($root)->put("/backend/category/news/{$child->id}", $this->form(['name' => 'Con mới', 'slug' => 'con-moi', 'parent_id' => '']));
        $this->assertSame([null, 3], [$child->fresh()->parent_id, $child->fresh()->priority]);
        $this->assertSame([1, 2], [$a->fresh()->priority, $b->fresh()->priority]);
    }

    public function test_slug_unique_within_type_only(): void
    {
        $root = $this->root();
        Category::factory()->create(['slug' => 'tin-tuc']);
        Category::factory()->create(['slug' => 'khac', 'type' => Category::TYPE_EVENT]);

        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Tin tức']))->assertSessionHasErrors('slug');
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Khác']))->assertSessionHasNoErrors();
    }

    public function test_validation_errors_stay_on_the_same_category(): void
    {
        $root = $this->root();
        Category::factory()->create(['slug' => 'trung', 'name' => 'Trùng']);
        $category = Category::factory()->create(['name' => 'Đang sửa', 'slug' => 'dang-sua']);

        $html = $this->actingAs($root)->from($this->page("type=news&id={$category->id}"))->followingRedirects()
            ->put("/backend/category/news/{$category->id}", $this->form(['name' => '', 'slug' => 'trung', 'status' => '0']))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('backend.categories.update', ['news', $category]).'"', $html);
        $this->assertStringContainsString('data-dirty', $html);
        $this->assertStringContainsString('Vui lòng nhập tên danh mục.', $html);
        // Ô tên xóa trắng vẫn trống (không hiện lại tên đã lưu ngay trên dòng báo lỗi)
        $this->assertStringContainsString('id="category_name" name="name" value=""', $html);
        $this->assertStringContainsString('value="trung"', $html);
        $this->assertStringContainsString('<option value="0" selected>Ẩn</option>', $html);
        $this->assertSame(['Đang sửa', 'dang-sua'], [$category->fresh()->name, $category->fresh()->slug]);

        foreach (['status' => 'abc', 'parent_id' => 'x'] as $field => $value) {
            $this->actingAs($root)->put("/backend/category/news/{$category->id}", $this->form(['name' => 'A', $field => $value]))->assertSessionHasErrors($field);
        }
    }

    public function test_parent_cannot_be_self_or_descendant(): void
    {
        $root = $this->root();
        $a = Category::factory()->create();
        $b = Category::factory()->create(['parent_id' => $a->id]);
        $c = Category::factory()->create(['parent_id' => $b->id]);

        foreach ([$a->id, $c->id] as $parentId) {
            $this->actingAs($root)->put("/backend/category/news/{$a->id}", $this->form(['name' => $a->name, 'slug' => $a->slug, 'parent_id' => $parentId]))
                ->assertSessionHasErrors('parent_id');
        }
        $this->assertNull($a->fresh()->parent_id);

        // Form sửa không đưa chính nó / con cháu vào ô chọn cha
        $html = $this->actingAs($root)->get($this->page("type=news&id={$a->id}"))->getContent();
        $form = substr($html, strpos($html, 'id="category_parent"'));
        $form = substr($form, 0, strpos($form, '</select>'));
        $this->assertStringNotContainsString('<option value="'.$c->id.'"', $form);
        $this->assertStringNotContainsString('<option value="'.$a->id.'"', $form);
    }

    public function test_parent_must_be_same_type(): void
    {
        $otherCategory = Category::factory()->create(['type' => Category::TYPE_EVENT]);

        $this->actingAs($this->root())->post('/backend/category/news', $this->form(['name' => 'Con', 'parent_id' => $otherCategory->id]))
            ->assertSessionHasErrors('parent_id');
    }

    // ---- Cây danh mục ----

    public function test_tree_order_and_selection(): void
    {
        $root = $this->root();
        $parent = Category::factory()->create(['name' => 'Cha A', 'priority' => 1]);
        $child = Category::factory()->create(['name' => 'Con A1', 'parent_id' => $parent->id]);
        $hidden = Category::factory()->create(['name' => 'Cha B', 'priority' => 2, 'status' => false]);

        // Không có id: mở danh mục đầu tiên của cây
        $html = $this->actingAs($root)->get($this->page())->assertOk()->getContent();
        $this->assertStringContainsString('<h1 class="page-topbar-title">Cha A</h1>', $html);
        $this->assertMatchesRegularExpression('#data-id="'.$parent->id.'" data-depth="0" data-parent="" role="treeitem" aria-level="1"\s+aria-selected="true"\s+aria-expanded="true"#', $html);
        $this->assertMatchesRegularExpression('#data-id="'.$child->id.'" data-depth="1" data-parent="'.$parent->id.'" role="treeitem" aria-level="2"\s+aria-selected="false"\s*>#', $html);
        $this->assertTrue(strpos($html, '>Cha A<') < strpos($html, '>Con A1<') && strpos($html, '>Con A1<') < strpos($html, '>Cha B<'), 'Con nằm ngay dưới cha');
        $this->assertStringContainsString('style="margin-left: 24px"', $html);
        $this->assertMatchesRegularExpression('#>Cha B</span>\s*(<span[^>]*>\(0\)</span>)?\s*<span class="ant-tag cat-tag">Ẩn</span>#', $html);

        // Mở danh mục con: dòng đó được chọn
        $html = $this->actingAs($root)->get($this->page("type=news&id={$child->id}"))->assertOk()->getContent();
        $this->assertStringContainsString('<div class="cat-row is-selected"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertModelExists($hidden);
    }

    public function test_tree_survives_parent_cycle_and_orphans(): void
    {
        $a = Category::factory()->create(['name' => 'A']);
        $b = Category::factory()->create(['name' => 'B', 'parent_id' => $a->id]);
        $a->update(['parent_id' => $b->id]); // dữ liệu lỗi: vòng lặp A ↔ B

        $tree = Category::tree(Category::TYPE_POST);
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
        $order = fn (?int $parent = null) => Category::where('type', Category::TYPE_POST)->where('parent_id', $parent)
            ->orderBy('priority')->orderBy('name')->pluck('name')->all();
        $move = fn (Category $c, Category $target, string $zone) => $this->actingAs($root)
            ->postJson("/backend/category/news/{$c->id}/move", ['target_id' => $target->id, 'zone' => $zone]);

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
            $this->actingAs($root)->postJson("/backend/category/news/{$from->id}/move", ['target_id' => $to->id, 'zone' => $zone])
                ->assertStatus(422)->assertJson(['message' => 'Không thể chuyển danh mục vào chính nó hoặc danh mục con của nó.']);
        }
        $this->actingAs($root)->postJson("/backend/category/news/{$b->id}/move", ['target_id' => $a->id, 'zone' => 'top'])->assertJsonValidationErrors('zone');
        $this->actingAs($root)->postJson("/backend/category/news/{$b->id}/move", ['target_id' => ['x'], 'zone' => 'before'])->assertJsonValidationErrors('target_id');
        $this->actingAs($root)->postJson("/backend/category/news/{$b->id}/move", ['target_id' => 99999, 'zone' => 'before'])->assertJsonValidationErrors('target_id');
        $this->assertSame([null, $a->id, $b->id], [$a->fresh()->parent_id, $b->fresh()->parent_id, $c->fresh()->parent_id]);
    }

    // ---- Xóa ----

    public function test_cannot_delete_category_with_children(): void
    {
        $root = $this->root();
        $parent = Category::factory()->create();
        Category::factory()->create(['parent_id' => $parent->id]);
        $free = Category::factory()->create(['name' => 'Xóa được']);

        $this->actingAs($root)->delete("/backend/category/news/{$parent->id}")->assertSessionHas('error');
        $this->assertModelExists($parent);
        $this->actingAs($root)->get($this->page("type=news&id={$parent->id}"))->assertSee('data-tooltip="Không xóa được: còn danh mục con"', false);

        $this->actingAs($root)->get($this->page("type=news&id={$free->id}"))
            ->assertSee('data-delete-url="'.route('backend.categories.destroy', ['news', $free]).'" data-delete-title="Xóa danh mục “Xóa được”?"', false);
        $this->actingAs($root)->delete("/backend/category/news/{$free->id}")
            ->assertRedirect(route('backend.categories.index', ['type' => 'news']))->assertSessionHas('success');
        $this->assertModelMissing($free);
    }

    // ---- Menu, đổi loại ----

    public function test_menu_shows_one_categories_item_with_any_permission(): void
    {
        $pageOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('page')->create()->id]);
        $newsOnly = User::factory()->create(['group_id' => Group::factory()->withPermissions('news_category')->create()->id]);
        $url = route('backend.categories.index');

        $this->actingAs($pageOnly)->get('/backend')->assertSee(route('backend.pages.index'))->assertDontSee('>Categories<', false);
        $this->actingAs($newsOnly)->get('/backend')->assertSee('<a href="'.$url.'" ><span class="mr-auto">Categories</span></a>', false);
        $this->actingAs($this->root())->get('/backend')->assertSee('<a href="'.$url.'" ><span class="mr-auto">Categories</span></a>', false);
        // Đang ở trang danh mục (bất kỳ loại nào): mục được chọn
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote($url, '#').'"\s+aria-current="page"\s*><span class="mr-auto">Categories</span></a>#',
            $this->actingAs($this->root())->get($this->page('type=news'))->getContent());
    }

    public function test_news_crud(): void
    {
        $root = $this->root();
        Category::factory()->create(['type' => Category::TYPE_EVENT, 'name' => 'Bán hàng', 'slug' => 'ban-hang']); // danh mục loại khác trùng slug

        // Chưa có danh mục tin tức: trang mở thẳng form thêm mới
        $this->actingAs($root)->get($this->page('type=news'))->assertOk()->assertSee('Chưa có danh mục nào')
            ->assertSee('action="'.route('backend.categories.store', 'news').'"', false)->assertSee('VD: Công nghệ');

        // Tạo: cùng slug với danh mục loại khác vẫn được (slug duy nhất trong từng loại)
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Bán hàng', 'description' => 'Tin bán hàng']))->assertSessionHasNoErrors();
        $parent = Category::where('type', Category::TYPE_POST)->where('slug', 'ban-hang')->firstOrFail();
        $this->assertSame([1, 'Tin bán hàng'], [$parent->priority, $parent->description]);
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Bán hàng']))->assertSessionHasErrors('slug');

        // Con: cha phải là danh mục tin tức
        $projectCat = Category::where('type', Category::TYPE_EVENT)->firstOrFail();
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Mẹo', 'parent_id' => $projectCat->id]))->assertSessionHasErrors('parent_id');
        $this->actingAs($root)->post('/backend/category/news', $this->form(['name' => 'Mẹo bán hàng', 'parent_id' => $parent->id]))->assertSessionHasNoErrors();
        $child = Category::where('slug', 'meo-ban-hang')->firstOrFail();
        $this->assertSame([Category::TYPE_POST, $parent->id], [$child->type, $child->parent_id]);

        // Trang: cây chỉ có danh mục tin tức; ô chọn cha không có danh mục loại khác / chính nó
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

        // Xóa: chặn khi còn con; xóa được khi trống; không đụng danh mục loại khác cùng slug
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
        $this->actingAs($root)->get('/backend/category/news')->assertRedirect(route('backend.categories.index', ['type' => 'news']));
        $this->actingAs($root)->get('/backend/category/news/create')->assertRedirect(route('backend.categories.index', ['type' => 'news', 'new' => 1]));
        $this->actingAs($root)->get("/backend/category/news/{$category->id}/edit")->assertRedirect(route('backend.categories.index', ['type' => 'news', 'id' => $category->id]));
    }
}
