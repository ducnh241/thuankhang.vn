<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\Product;
use App\Models\User;
use App\Support\AffiliateSettings;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Step 2 – CRUD sản phẩm (A6): giá đã gồm VAT, VAT %, ảnh, mô tả, trạng thái; quyền product/* (vai trò Vận hành). */
class ProductManagementTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(RoleSeeder::class);
        $this->category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'name' => 'Thực phẩm chức năng']);
    }

    private function ops(): User
    {
        return User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
    }

    private function form(array $data = []): array
    {
        return [
            'name' => 'Viên uống Vitamin C', 'slug' => '', 'sku' => '', 'category_id' => (string) $this->category->id,
            'price' => '1.100.000', 'vat_rate' => '10', 'short_description' => '', 'description' => '<p></p>',
            'status' => '1', 'is_featured' => '0', 'sort_order' => '0', ...$data,
        ];
    }

    private function image(string $name = 'a.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 400, 400);
    }

    public function test_seo_fields_and_form_layout(): void
    {
        $ops = $this->ops();
        $this->actingAs($ops)->get('/backend/product/create')->assertOk()
            ->assertSeeInOrder(['data-card="variants"', 'data-variant-body class="hidden"', 'data-card="seo"', 'id="product_meta_title"', 'id="product_slug"'], false);

        $this->post('/backend/product', $this->form(['meta_title' => ' Vitamin C chính hãng ', 'meta_description' => 'Mua vitamin C giá tốt']))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame(['Vitamin C chính hãng', 'Mua vitamin C giá tốt'], [$product->meta_title, $product->meta_description]);
        $this->get(route('backend.products.edit', $product))->assertSee('value="Vitamin C chính hãng"', false);
        $this->put(route('backend.products.update', $product), $this->form(['slug' => $product->slug, 'meta_title' => str_repeat('a', 71)]))->assertSessionHasErrors('meta_title');

        // Website dùng tiêu đề / mô tả SEO
        $product->category->update(['status' => true]);
        $this->get($product->url())->assertSee('<title>Vitamin C chính hãng', false)->assertSee('content="Mua vitamin C giá tốt"', false);

        // Có biến thể: phần biến thể hiện sẵn
        $product->variants()->create(['name' => 'Hộp 30 viên']);
        $this->get(route('backend.products.edit', $product))->assertDontSee('data-variant-body class="hidden"', false)->assertSee('value="Hộp 30 viên"', false);
    }

    public function test_brand_origin_and_stock_fields(): void
    {
        $ops = $this->ops();
        $this->actingAs($ops)->post('/backend/product', $this->form(['brand' => ' Blackmores ', 'origin' => 'Úc', 'stock' => '1.250']))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame(['Blackmores', 'Úc', 1250], [$product->brand, $product->origin, $product->stock]);
        $this->actingAs($ops)->get(route('backend.products.edit', $product))->assertSee('value="Blackmores"', false)->assertSee('value="1250"', false);
        $this->get('/backend/product')->assertSee('1.250');

        // Để trống tồn kho = không theo dõi; số âm / chữ báo lỗi
        $this->put(route('backend.products.update', $product), $this->form(['slug' => $product->slug, 'stock' => '', 'brand' => '', 'origin' => '']))->assertSessionHasNoErrors();
        $this->assertSame([null, null, null], [$product->refresh()->stock, $product->brand, $product->origin]);
        $this->put(route('backend.products.update', $product), $this->form(['slug' => $product->slug, 'stock' => '-1']))->assertSessionHasErrors('stock');
        $this->put(route('backend.products.update', $product), $this->form(['slug' => $product->slug, 'stock' => 'abc']))->assertSessionHasErrors('stock');
        $this->put(route('backend.products.update', $product), $this->form(['slug' => $product->slug, 'stock' => '0']))->assertSessionHasNoErrors();
        $this->get('/backend/product')->assertSee('Hết hàng');
    }

    public function test_create_product_with_images(): void
    {
        $ops = $this->ops();
        $this->actingAs($ops)->get('/backend/product/create')->assertOk()
            ->assertSee('value="10"', false) // VAT mặc định từ tham số chung
            ->assertSee('Thực phẩm chức năng');

        $res = $this->actingAs($ops)->post('/backend/product', $this->form([
            'sku' => ' vitc-1000 ', 'is_featured' => '1', 'short_description' => 'Tăng đề kháng',
            'images' => [$this->image('1.jpg'), $this->image('2.png')],
        ]));

        $product = Product::sole();
        $res->assertRedirect(route('backend.products.edit', $product))->assertSessionHas('success', 'Đã tạo sản phẩm Viên uống Vitamin C.');
        $this->assertSame(['vien-uong-vitamin-c', 'VITC-1000', 1_100_000, '10.00', true, true],
            [$product->slug, $product->sku, $product->price, $product->vat_rate, $product->status, $product->is_featured]);
        $this->assertNull($product->description);
        $this->assertSame(1_000_000, $product->priceBeforeVat());
        $this->assertCount(2, $product->images);
        $this->assertSame([0, 1], $product->images->pluck('sort_order')->all());
        $product->images->each(fn ($image) => Storage::disk('public')->assertExists($image->path));
        $this->assertTrue(AuditLog::where('auditable_type', Product::class)->where('action', 'created')->where('user_id', $ops->id)->exists());

        // Trang sửa: ảnh, giá định dạng VNĐ, giá trước VAT
        $this->actingAs($ops)->get(route('backend.products.edit', $product))->assertOk()
            ->assertSee('value="1.100.000"', false)->assertSee('name="keep_images[]" value="'.$product->images[0]->id.'"', false)
            ->assertSee('1.000.000');
    }

    public function test_update_reorders_removes_and_adds_images(): void
    {
        $ops = $this->ops();
        $this->actingAs($ops)->post('/backend/product', $this->form(['images' => [$this->image('1.jpg'), $this->image('2.jpg'), $this->image('3.jpg')]]));
        $product = Product::sole();
        [$a, $b, $c] = $product->images->all();

        // Giữ c rồi a (c thành ảnh đại diện), bỏ b, thêm 1 ảnh mới; đổi giá, ẩn sản phẩm
        $this->actingAs($ops)->put(route('backend.products.update', $product), $this->form([
            'slug' => $product->slug, 'price' => '2.160.000', 'vat_rate' => '8', 'status' => '0',
            'keep_images' => [$c->id, $a->id], 'images' => [$this->image('4.jpg')],
        ]))->assertRedirect(route('backend.products.edit', $product))->assertSessionHas('success', 'Đã lưu sản phẩm.');

        $product->refresh();
        $images = $product->images()->get();
        $this->assertSame([$c->id, $a->id], $images->take(2)->pluck('id')->all());
        $this->assertSame([0, 1, 2], $images->pluck('sort_order')->all());
        $this->assertSame($c->id, $product->cover->id);
        $this->assertModelMissing($b);
        Storage::disk('public')->assertMissing($b->path);
        $this->assertSame([2_160_000, '8.00', false, 2_000_000], [$product->price, $product->vat_rate, $product->status, $product->priceBeforeVat()]);

        // Id ảnh của sản phẩm khác bị bỏ qua
        $other = Product::factory()->create(['category_id' => $this->category->id]);
        $foreign = $other->images()->create(['path' => 'products/x.jpg', 'sort_order' => 0]);
        $this->actingAs($ops)->put(route('backend.products.update', $product), $this->form(['slug' => $product->slug, 'keep_images' => [$foreign->id]]));
        $this->assertSame(0, $product->images()->count());
        $this->assertModelExists($foreign);
    }

    public function test_validation(): void
    {
        $ops = $this->ops();
        $news = Category::factory()->create(['type' => Category::TYPE_POST]);
        Product::factory()->create(['category_id' => $this->category->id, 'slug' => 'da-co', 'sku' => 'MA-1'])->delete();

        foreach ([
            'name' => ['name' => ''], 'price' => ['price' => 'abc'], 'vat_rate' => ['vat_rate' => '101'],
            'category_id' => ['category_id' => (string) $news->id], 'sku' => ['sku' => 'mã có dấu'],
        ] as $field => $data) {
            $this->actingAs($ops)->post('/backend/product', $this->form($data))->assertSessionHasErrors($field);
        }
        $this->actingAs($ops)->post('/backend/product', $this->form(['price' => '1.000,5']))->assertSessionHasErrors('price');
        // Slug / mã trùng cả với sản phẩm đã xóa
        $this->actingAs($ops)->post('/backend/product', $this->form(['slug' => 'da-co']))->assertSessionHasErrors('slug');
        $this->actingAs($ops)->post('/backend/product', $this->form(['sku' => 'ma-1']))->assertSessionHasErrors('sku');
        // Ảnh: sai định dạng, quá 4 MB, quá 10 ảnh
        $this->actingAs($ops)->post('/backend/product', $this->form(['images' => [UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')]]))->assertSessionHasErrors('images.0');
        $this->actingAs($ops)->post('/backend/product', $this->form(['images' => [UploadedFile::fake()->image('big.jpg')->size(5000)]]))->assertSessionHasErrors('images.0');
        $this->actingAs($ops)->post('/backend/product', $this->form(['images' => array_map(fn ($i) => $this->image("{$i}.jpg"), range(1, 11))]))->assertSessionHasErrors('images');

        $this->assertSame(0, Product::count());
        // Lưu lỗi: form hiện lại dữ liệu vừa nhập
        $this->actingAs($ops)->from('/backend/product/create')->followingRedirects()->post('/backend/product', $this->form(['vat_rate' => '200', 'price' => '2.500.000']))
            ->assertOk()->assertSee('value="2.500.000"', false)->assertSee('Nhập VAT từ 0 đến 100');
    }

    public function test_list_tabs_search_and_category_filter(): void
    {
        $root = User::factory()->superAdmin()->create();
        $child = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'name' => 'Vitamin', 'parent_id' => $this->category->id]);
        $other = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'name' => 'Mỹ phẩm']);
        Product::factory()->create(['name' => 'Cha Một', 'category_id' => $this->category->id, 'sku' => 'CHA-1']);
        Product::factory()->create(['name' => 'Con Hai', 'category_id' => $child->id, 'status' => false]);
        Product::factory()->create(['name' => 'Khác Ba', 'category_id' => $other->id, 'price' => 2_500_000]);
        Product::factory()->create(['name' => 'Đã Xóa', 'category_id' => $other->id])->delete();

        $this->actingAs($root)->get('/backend/product')->assertOk()
            ->assertSee('Cha Một')->assertSee('Con Hai')->assertSee('Khác Ba')->assertDontSee('Đã Xóa')->assertSee('2.500.000 đ');
        $this->actingAs($root)->get('/backend/product?tab=hidden')->assertSee('Con Hai')->assertDontSee('Cha Một');
        $this->actingAs($root)->get('/backend/product?q=cha-1')->assertSee('Cha Một')->assertDontSee('Khác Ba');
        // Lọc danh mục gồm cả danh mục con (link "N sản phẩm" ở trang Danh mục dùng tham số này)
        $this->actingAs($root)->get('/backend/product?category_id='.$this->category->id)
            ->assertSee('Cha Một')->assertSee('Con Hai')->assertDontSee('Khác Ba')->assertSee('Danh mục: Thực phẩm chức năng');
        $this->actingAs($root)->get('/backend/product?category_id=abc&tab=x&q[]=1')->assertOk();
    }

    public function test_delete_is_soft_and_blocks_category_delete(): void
    {
        $ops = $this->ops();
        $product = Product::factory()->create(['category_id' => $this->category->id]);

        $this->actingAs($ops)->get('/backend/category?type=product&id='.$this->category->id)->assertOk()
            ->assertSee('1 sản phẩm</a> thuộc danh mục này', false);
        $this->actingAs($ops)->delete(route('backend.products.destroy', $product))->assertRedirect(route('backend.products.index'));
        $this->assertSoftDeleted($product);

        // Danh mục còn sản phẩm (kể cả đã xóa mềm) → không xóa được
        $this->actingAs($ops)->delete('/backend/category/product/'.$this->category->id)->assertSessionHas('error');
        $this->assertModelExists($this->category);
    }

    public function test_bulk_delete(): void
    {
        $ops = $this->ops();
        [$a, $b, $c] = Product::factory()->count(3)->create(['category_id' => $this->category->id])->all();

        $this->actingAs($ops)->get('/backend/product')->assertOk()
            ->assertSee('data-bulk-form', false)->assertSee('data-select-page', false)
            ->assertSee('name="ids[]" value="'.$a->id.'" data-row-check', false);

        $this->actingAs($ops)->from('/backend/product?tab=on_sale')->post('/backend/product/bulk-destroy', ['ids' => [(string) $a->id, (string) $b->id, '99999', 'x', ['y']]])
            ->assertRedirect('/backend/product?tab=on_sale')->assertSessionHas('success', 'Đã xóa 2 sản phẩm.');
        $this->assertSoftDeleted($a);
        $this->assertSoftDeleted($b);
        $this->assertNotSoftDeleted($c);
        $this->assertSame(2, AuditLog::where('auditable_type', Product::class)->where('action', 'deleted')->where('user_id', $ops->id)->count());

        $this->actingAs($ops)->post('/backend/product/bulk-destroy', [])->assertSessionHas('error', 'Chưa chọn sản phẩm nào.');

        // Không có quyền xóa: không có ô chọn, gửi thẳng bị chặn
        $editor = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'product', 'product/edit')->create()->id]);
        $this->actingAs($editor)->get('/backend/product')->assertOk()->assertDontSee('data-bulk-form', false)->assertDontSee('data-row-check', false);
        $this->actingAs($editor)->post('/backend/product/bulk-destroy', ['ids' => [(string) $c->id]])->assertForbidden();
        $this->assertNotSoftDeleted($c);
    }

    public function test_permissions(): void
    {
        $product = Product::factory()->create(['category_id' => $this->category->id]);

        $this->actingAs($this->ops())->get('/backend')->assertSee(route('backend.products.index'), false);
        foreach (['CSKH', 'Kế toán'] as $role) {
            $user = User::factory()->create(['group_id' => Group::where('name', $role)->value('id')]);
            $this->actingAs($user)->get('/backend/product')->assertForbidden();
            $this->actingAs($user)->get('/backend')->assertDontSee(route('backend.products.index'), false);
        }

        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'product')->create()->id]);
        $this->actingAs($viewer)->get('/backend/product')->assertOk()->assertSee($product->name)->assertDontSee(route('backend.products.create'), false);
        $this->actingAs($viewer)->get('/backend/product/create')->assertForbidden();
        $this->actingAs($viewer)->post('/backend/product', $this->form())->assertForbidden();
        $this->actingAs($viewer)->get(route('backend.products.edit', $product))->assertForbidden();
        $this->actingAs($viewer)->delete(route('backend.products.destroy', $product))->assertForbidden();
        $this->assertNotSoftDeleted($product);
    }

    public function test_preview_uses_real_link_and_works_for_hidden_products(): void
    {
        $ops = $this->ops();
        $product = Product::factory()->create(['category_id' => $this->category->id, 'name' => 'Serum Xem Trước', 'slug' => 'serum-xem-truoc', 'price' => 550_000]);

        // Nút xem trước ở danh sách và trang sửa: link thật, tab mới
        $this->actingAs($ops)->get('/backend/product')->assertSee('href="/san-pham/serum-xem-truoc" target="_blank"', false);
        $this->actingAs($ops)->get(route('backend.products.edit', $product))->assertSee('href="/san-pham/serum-xem-truoc" target="_blank"', false);

        // Đang bán: trang bình thường, không có dải xem trước
        $this->actingAs($ops)->get('/san-pham/serum-xem-truoc')->assertOk()->assertSee('550.000đ')
            ->assertDontSee('data-preview-banner', false)->assertDontSee('name="robots"', false);

        // Đang ẩn: khách 404; quản trị xem được ở đúng link đó, có dải xem trước + link sửa, không cho lập chỉ mục
        $product->update(['status' => false]);
        auth()->logout();
        $this->get('/san-pham/serum-xem-truoc')->assertNotFound();
        $this->actingAs($ops)->get('/san-pham/serum-xem-truoc')->assertOk()
            ->assertSee('Serum Xem Trước')->assertSee('data-preview-banner', false)->assertSee('Sản phẩm đang ẩn với khách')
            ->assertSee('href="'.route('backend.products.edit', $product).'"', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow" />', false);

        // Danh mục ẩn: cũng chỉ quản trị xem được
        $product->update(['status' => true]);
        $this->category->update(['status' => false]);
        $this->actingAs($ops)->get('/san-pham/serum-xem-truoc')->assertOk()->assertSee('data-preview-banner', false);
        $this->category->update(['status' => true]);
        $product->update(['status' => false]);

        // Quyền: chỉ xem (product) xem được nhưng không có link sửa; không có quyền sản phẩm → 404 như khách
        $viewer = User::factory()->create(['group_id' => Group::factory()->withPermissions('backend_access', 'product')->create()->id]);
        $this->actingAs($viewer)->get('/san-pham/serum-xem-truoc')->assertOk()->assertDontSee('Sửa sản phẩm');
        $cs = User::factory()->create(['group_id' => Group::where('name', 'CSKH')->value('id')]);
        $this->actingAs($cs)->get('/san-pham/serum-xem-truoc')->assertNotFound();

        // Đã xóa: 404 với mọi người
        $product->delete();
        $this->actingAs($ops)->get('/san-pham/serum-xem-truoc')->assertNotFound();
    }

    public function test_price_before_vat_rounds_down(): void
    {
        $this->assertSame(5_000_000, (new Product(['price' => 5_500_000, 'vat_rate' => '10']))->priceBeforeVat());
        $this->assertSame(1_000_000, (new Product(['price' => 1_080_000, 'vat_rate' => '8']))->priceBeforeVat());
        $this->assertSame(90_909, (new Product(['price' => 100_000, 'vat_rate' => '10']))->priceBeforeVat()); // 90.909,09 → xuống
        $this->assertSame(100_000, (new Product(['price' => 100_000, 'vat_rate' => '0']))->priceBeforeVat());
        AffiliateSettings::save(['default_vat_percent' => '8.00']);
        $this->actingAs(User::factory()->superAdmin()->create())->get('/backend/product/create')->assertSee('id="product_vat" name="vat_rate" value="8"', false);
    }
}
