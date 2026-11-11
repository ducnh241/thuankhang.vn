<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Group;
use App\Models\Member;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Support\Cart;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Biến thể sản phẩm: admin nhập / sửa / xóa, khách bắt buộc chọn, giá + tồn theo biến thể, đơn lưu tên biến thể. */
class ProductVariantTest extends TestCase
{
    use RefreshDatabase;

    private Category $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->category = Category::factory()->create(['type' => Category::TYPE_PRODUCT, 'status' => true]);
    }

    private function form(array $data = []): array
    {
        return [
            'name' => 'Son WSKIN', 'slug' => 'son-wskin', 'sku' => '', 'category_id' => (string) $this->category->id, 'price' => '350.000', 'vat_rate' => '10',
            'short_description' => '', 'description' => '<p></p>', 'status' => '1', 'is_featured' => '0', 'sort_order' => '0', 'variant_label' => 'Màu sắc', ...$data,
        ];
    }

    private function lipstick(): Product
    {
        $product = Product::factory()->create(['category_id' => $this->category->id, 'status' => true, 'name' => 'Son WSKIN', 'slug' => 'son-wskin', 'price' => 350_000, 'variant_label' => 'Màu sắc']);
        $product->variants()->createMany([
            ['name' => '401 Cam san hô', 'sort_order' => 0, 'stock' => 2],
            ['name' => '402 Hồng đào', 'sort_order' => 1, 'price' => 380_000],
            ['name' => '403 Hồng san hô', 'sort_order' => 2, 'stock' => 0],
            ['name' => '404 Đỏ nhung', 'sort_order' => 3, 'active' => false],
        ]);

        return $product;
    }

    public function test_admin_creates_updates_and_removes_variants(): void
    {
        $ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        $this->actingAs($ops)->post('/backend/product', $this->form(['variants' => [
            ['id' => '', 'name' => '401 Cam san hô', 'sku' => 'wsk-401', 'price' => '', 'stock' => '10', 'active' => '1'],
            ['id' => '', 'name' => '402 Hồng đào', 'sku' => '', 'price' => '380.000', 'stock' => '', 'active' => '1'],
        ]]))->assertSessionHasNoErrors();
        $product = Product::sole();
        $this->assertSame('Màu sắc', $product->variant_label);
        $this->assertSame([['401 Cam san hô', 'WSK-401', null, 10], ['402 Hồng đào', null, 380000, null]],
            $product->variants->map(fn ($v) => [$v->name, $v->sku, $v->price, $v->stock])->all());

        $this->get(route('backend.products.edit', $product))->assertSee('value="401 Cam san hô"', false)->assertSee('value="380.000"', false);
        $this->get('/backend/product')->assertSee('2 biến thể');

        // Sửa dòng 1, bỏ dòng 2, thêm dòng mới
        $first = $product->variants->first();
        $this->put(route('backend.products.update', $product), $this->form(['variants' => [
            ['id' => (string) $first->id, 'name' => '401 Cam', 'sku' => 'WSK-401', 'price' => '', 'stock' => '5', 'active' => '0'],
            ['id' => '', 'name' => '404 Đỏ nhung', 'sku' => '', 'price' => '', 'stock' => '', 'active' => '1'],
        ]]))->assertSessionHasNoErrors();
        $this->assertSame([[$first->id, '401 Cam', 5, false], [null, '404 Đỏ nhung', null, true]],
            $product->variants()->get()->map(fn ($v) => [$v->id === $first->id ? $v->id : null, $v->name, $v->stock, $v->active])->all());

        // Lỗi: thiếu tên, trùng tên, SKU trùng biến thể của sản phẩm khác, id không thuộc sản phẩm
        $other = Product::factory()->create(['category_id' => $this->category->id]);
        $foreign = $other->variants()->create(['name' => 'X', 'sku' => 'TAKEN']);
        foreach ([
            'variants.0.name' => [['id' => '', 'name' => '', 'sku' => '', 'price' => '', 'stock' => '', 'active' => '1']],
            'variants.1.name' => [['id' => '', 'name' => 'A', 'active' => '1'], ['id' => '', 'name' => 'a', 'active' => '1']],
            'variants.0.sku' => [['id' => '', 'name' => 'A', 'sku' => 'taken', 'active' => '1']],
        ] as $field => $rows) {
            $this->put(route('backend.products.update', $product), $this->form(['variants' => $rows]))->assertSessionHasErrors($field);
        }
        $this->put(route('backend.products.update', $product), $this->form(['variants' => [['id' => (string) $foreign->id, 'name' => 'Hack', 'active' => '1']]]))
            ->assertSessionHasErrors('variants.0.name');
        $this->assertSame('X', $foreign->refresh()->name);

        // Bỏ hết biến thể
        $this->put(route('backend.products.update', $product), $this->form())->assertSessionHasNoErrors();
        $this->assertSame(0, $product->variants()->count());
    }

    public function test_storefront_requires_choosing_a_variant(): void
    {
        $product = $this->lipstick();
        [$cam, $hong, $hetHang] = $product->variants;

        $this->get('/')->assertSee('Từ ')->assertSee('350.000đ')->assertSee('data-choose-variant', false);
        $this->get('/san-pham/son-wskin')->assertOk()
            ->assertSee('Màu sắc:')->assertSee('401 Cam san hô')->assertSee('402 Hồng đào')->assertDontSee('404 Đỏ nhung')
            ->assertSee('data-price="380.000đ"', false)->assertSee('Còn 2 sản phẩm')->assertSee('max="2"', false);

        // Không chọn / chọn biến thể đã tắt hoặc hết hàng / chọn biến thể của sản phẩm khác
        $this->post('/gio-hang', ['product_id' => $product->id])->assertRedirect('/san-pham/son-wskin')->assertSessionHas('error', 'Vui lòng chọn màu sắc trước khi thêm vào giỏ.');
        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $product->variants()->where('active', false)->value('id')])->assertSessionHas('error');
        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $hetHang->id])->assertSessionHas('error', '“Son WSKIN — 403 Hồng san hô” đã hết hàng.');
        $this->assertSame([], session(Cart::SESSION_KEY, []));

        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $cam->id, 'qty' => 2])->assertSessionHas('success', 'Đã thêm “Son WSKIN — 401 Cam san hô” vào giỏ hàng.');
        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $cam->id])->assertSessionHas('error', '“Son WSKIN — 401 Cam san hô” chỉ còn 2 sản phẩm (giỏ đã có 2).');
        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $hong->id]);
        $this->assertSame(["{$product->id}:{$cam->id}" => 2, "{$product->id}:{$hong->id}" => 1], session(Cart::SESSION_KEY));

        $this->get('/gio-hang')->assertSee('401 Cam san hô')->assertSee('402 Hồng đào')->assertSee('380.000đ')->assertSee('1.080.000đ');
        $this->patch("/gio-hang/{$product->id}:{$hong->id}", ['qty' => 3])->assertRedirect(route('shop.cart'));
        $this->patch("/gio-hang/{$product->id}:{$cam->id}", ['qty' => 5])->assertSessionHas('error');
        $this->delete("/gio-hang/{$product->id}:{$hong->id}");
        $this->assertSame(["{$product->id}:{$cam->id}" => 2], session(Cart::SESSION_KEY));

        // Biến thể bị tắt sau khi đã vào giỏ → tự bỏ khỏi giỏ
        $cam->update(['active' => false]);
        $this->get('/gio-hang')->assertSee('Giỏ hàng đang trống');
    }

    public function test_order_stores_variant_and_moves_variant_stock(): void
    {
        $product = $this->lipstick();
        [$cam, $hong] = $product->variants;
        $member = Member::factory()->create();

        $this->actingAs($member, 'member')->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $cam->id, 'qty' => 2]);
        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $hong->id]);
        $this->post('/thanh-toan', ['recipient_name' => 'A', 'recipient_phone' => '0912345678', 'delivery_type' => 'ship', 'province' => 'ha-noi', 'address' => '1 Láng Hạ'])
            ->assertSessionHas('success');

        $order = Order::sole();
        $this->assertSame(700_000 + 380_000, $order->goods_amount);
        $this->assertSame([[$cam->id, '401 Cam san hô', 350000, 2], [$hong->id, '402 Hồng đào', 380000, 1]],
            $order->items->map(fn ($i) => [$i->variant_id, $i->variant_name, $i->unit_price, $i->qty])->all());
        $this->assertSame([0, null], [$cam->refresh()->stock, $hong->refresh()->stock]);
        $this->get(route('member.orders.show', $order))->assertSee('401 Cam san hô');

        // Hết biến thể 401 → không đặt thêm được; hủy đơn → trả về biến thể
        $this->post('/gio-hang', ['product_id' => $product->id, 'variant_id' => $cam->id])->assertSessionHas('error');
        $this->post(route('member.orders.cancel', $order))->assertSessionHas('success');
        $this->assertSame(2, $cam->refresh()->stock);

        $root = User::factory()->superAdmin()->create();
        $this->actingAs($root, 'web')->get(route('backend.orders.show', $order))->assertSee('Phân loại: 401 Cam san hô');

        // Xóa biến thể: đơn cũ vẫn giữ tên
        $cam->delete();
        $this->assertSame('401 Cam san hô', $order->items()->first()->variant_name);
        $this->assertNull($order->items()->first()->variant_id);
        $this->assertSame(3, ProductVariant::count());
    }
}
