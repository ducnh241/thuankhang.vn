<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Step 2 – CRUD danh mục sản phẩm (A6): loại 'product' của trang Danh mục, quyền chung với sản phẩm (product,
 * product/add|edit|delete — vai trò Vận hành).
 */
class ProductCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $name): User
    {
        $this->seed(RoleSeeder::class);

        return User::factory()->create(['group_id' => Group::where('name', $name)->value('id')]);
    }

    private function form(array $data): array
    {
        return ['slug' => '', 'parent_id' => '', 'status' => '1', 'description' => '', ...$data];
    }

    public function test_operations_role_manages_product_categories(): void
    {
        $ops = $this->role('Vận hành');

        // Menu Categories hiện; trang mở thẳng loại sản phẩm, chưa có gì thì mở form thêm
        $this->actingAs($ops)->get('/backend')->assertSee('<a href="'.route('backend.categories.index').'" ><span class="mr-auto">Categories</span></a>', false);
        $this->actingAs($ops)->get('/backend/category')->assertOk()
            ->assertSee('Danh mục sản phẩm')->assertSee('Chưa có danh mục nào')->assertSee('VD: Thực phẩm chức năng')
            ->assertSee('action="'.route('backend.categories.store', 'product').'"', false);

        // Tạo cha + con (slug tự sinh)
        $this->actingAs($ops)->post('/backend/category/product', $this->form(['name' => 'Thực phẩm chức năng']))->assertSessionHasNoErrors();
        $parent = Category::where('slug', 'thuc-pham-chuc-nang')->sole();
        $this->assertSame(Category::TYPE_PRODUCT, $parent->type);
        $this->actingAs($ops)->post('/backend/category/product', $this->form(['name' => 'Vitamin', 'parent_id' => $parent->id]))
            ->assertSessionHasNoErrors();
        $child = Category::where('slug', 'vitamin')->sole();
        $this->assertSame([Category::TYPE_PRODUCT, $parent->id], [$child->type, $child->parent_id]);

        // Sửa, ẩn
        $this->actingAs($ops)->put("/backend/category/product/{$child->id}", $this->form(['name' => 'Vitamin tổng hợp', 'slug' => 'vitamin-tong-hop', 'parent_id' => $parent->id, 'status' => '0']))
            ->assertRedirect(route('backend.categories.index', ['type' => 'product', 'id' => $child->id]));
        $this->assertSame(['Vitamin tổng hợp', false], [$child->fresh()->name, $child->fresh()->status]);
        $this->assertSame('/danh-muc/vitamin-tong-hop', $child->fresh()->url());

        // Xóa: chặn khi còn con, xóa được khi trống
        $this->actingAs($ops)->delete("/backend/category/product/{$parent->id}")->assertSessionHas('error');
        $this->actingAs($ops)->delete("/backend/category/product/{$child->id}")->assertSessionHas('success');
        $this->actingAs($ops)->delete("/backend/category/product/{$parent->id}")->assertSessionHas('success');
        $this->assertSame(0, Category::where('type', Category::TYPE_PRODUCT)->count());

        // Mọi thao tác ghi nhật ký hệ thống
        $this->assertSame(['created', 'created', 'updated', 'deleted', 'deleted'],
            AuditLog::where('auditable_type', Category::class)->where('user_id', $ops->id)->orderBy('id')->pluck('action')->all());
    }

    public function test_operations_role_cannot_touch_news_categories(): void
    {
        $ops = $this->role('Vận hành');
        $news = Category::factory()->create(['name' => 'Công nghệ', 'type' => Category::TYPE_POST]);

        // Chỉ một loại được xem: không có ô chọn loại, ?type=news bị bỏ qua
        $html = $this->actingAs($ops)->get('/backend/category?type=news')->assertOk()->getContent();
        $this->assertStringNotContainsString('id="category_type"', $html);
        $this->assertStringNotContainsString('Công nghệ', $html);
        $this->actingAs($ops)->post('/backend/category/news', $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($ops)->delete("/backend/category/news/{$news->id}")->assertForbidden();
        // Không lách qua URL loại sản phẩm
        $this->actingAs($ops)->delete("/backend/category/product/{$news->id}")->assertNotFound();
        $this->assertModelExists($news);
    }

    public function test_other_roles_have_no_access(): void
    {
        foreach (['CSKH', 'Kế toán'] as $role) {
            $user = $this->role($role);
            $this->actingAs($user)->get('/backend/category')->assertForbidden();
            $this->actingAs($user)->post('/backend/category/product', $this->form(['name' => 'X']))->assertForbidden();
            $this->actingAs($user)->get('/backend')->assertDontSee(route('backend.categories.index'), false);
        }

        // Chỉ xem (product, không có product/add): form khóa, không thêm được
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'product')->create()->id]);
        $category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'name' => 'Mỹ phẩm']);
        $this->actingAs($viewer)->get('/backend/category')->assertOk()->assertSee('Mỹ phẩm')->assertSee('Bạn chỉ có quyền xem danh mục.');
        $this->actingAs($viewer)->post('/backend/category/product', $this->form(['name' => 'X']))->assertForbidden();
        $this->actingAs($viewer)->put("/backend/category/product/{$category->id}", $this->form(['name' => 'X']))->assertForbidden();
    }

    public function test_slug_and_parent_are_scoped_to_product_type(): void
    {
        $root = User::factory()->superAdmin()->create();
        $news = Category::factory()->create(['name' => 'Khuyến mãi', 'slug' => 'khuyen-mai', 'type' => Category::TYPE_POST]);

        // Trùng slug với danh mục tin tức vẫn được; cha phải là danh mục sản phẩm
        $this->actingAs($root)->post('/backend/category/product', $this->form(['name' => 'Khuyến mãi']))->assertSessionHasNoErrors();
        $this->actingAs($root)->post('/backend/category/product', $this->form(['name' => 'Khuyến mãi']))->assertSessionHasErrors('slug');
        $this->actingAs($root)->post('/backend/category/product', $this->form(['name' => 'Con', 'parent_id' => $news->id]))->assertSessionHasErrors('parent_id');

        // Root thấy cả 2 loại trong ô chọn loại, sản phẩm đứng đầu
        $html = $this->actingAs($root)->get('/backend/category')->assertOk()->getContent();
        $this->assertStringContainsString('<option value="product" data-url="'.route('backend.categories.index', ['type' => 'product']).'" selected>Danh mục sản phẩm</option>', $html);
        $this->assertStringContainsString('<option value="news"', $html);
    }
}
