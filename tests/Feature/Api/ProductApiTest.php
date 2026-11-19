<?php

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Group;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\AffiliateConfigSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** API /api/v1: token, quyền, danh mục, sản phẩm (ảnh base64 / URL, biến thể), xóa. */
class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $ops;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->seed(AffiliateConfigSeeder::class);
        $this->seed(RoleSeeder::class);
        $this->ops = User::factory()->create(['group_id' => Group::where('name', 'Vận hành')->value('id')]);
        [, $this->token] = ApiToken::issue($this->ops, 'Claude');
    }

    private function api(): static
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->token, 'Accept' => 'application/json']);
    }

    private function png(int $w = 20, int $h = 20): string
    {
        $img = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($img);

        return base64_encode(ob_get_clean());
    }

    public function test_token_authentication_and_permissions(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->withHeaders(['Authorization' => 'Bearer tk_sai'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->api()->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $this->ops->email)
            ->assertJsonPath('data.abilities', ['product', 'product/add', 'product/edit', 'product/delete']);
        $this->assertNotNull(ApiToken::first()->last_used_at);

        // Nhóm không có quyền sản phẩm → 403; tài khoản bị khóa → 403; token bị thu hồi → 401
        $cskh = User::factory()->create(['group_id' => Group::where('name', 'CSKH')->value('id')]);
        [, $plain] = ApiToken::issue($cskh, 'x');
        $this->withHeaders(['Authorization' => "Bearer {$plain}"])->getJson('/api/v1/products')->assertForbidden();
        $this->ops->update(['status' => false]);
        $this->api()->getJson('/api/v1/me')->assertForbidden();
        $this->ops->update(['status' => true]);
        ApiToken::where('user_id', $this->ops->id)->delete();
        $this->api()->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_category_crud(): void
    {
        $root = $this->api()->postJson('/api/v1/categories', ['name' => 'Mỹ phẩm & làm đẹp'])->assertCreated()
            ->assertJsonPath('data.slug', 'my-pham-lam-dep')->json('data');
        $child = $this->api()->postJson('/api/v1/categories', ['name' => 'Son môi', 'parent_id' => $root['id']])->assertCreated()->json('data');
        $this->api()->postJson('/api/v1/categories', ['name' => 'Son môi'])->assertUnprocessable()->assertJsonValidationErrors('slug');
        $this->api()->getJson('/api/v1/categories')->assertOk()->assertJsonCount(2, 'data');

        $this->api()->patchJson("/api/v1/categories/{$child['id']}", ['name' => 'Son dưỡng', 'description' => 'Son'])->assertOk()->assertJsonPath('data.name', 'Son dưỡng');
        $this->api()->patchJson("/api/v1/categories/{$root['id']}", ['parent_id' => $child['id']])->assertUnprocessable();
        $this->api()->deleteJson("/api/v1/categories/{$root['id']}")->assertStatus(409);
        Product::factory()->create(['category_id' => $child['id']]);
        $this->api()->deleteJson("/api/v1/categories/{$child['id']}")->assertStatus(409);

        $news = Category::factory()->create(['type' => Category::TYPE_POST]);
        $this->api()->patchJson("/api/v1/categories/{$news->id}", ['name' => 'Hack'])->assertNotFound();
    }

    public function test_create_update_delete_product_with_images_and_variants(): void
    {
        $cat = Category::factory()->create(['type' => Category::TYPE_PRODUCT]);
        Http::fake(['93.184.216.34/*' => Http::response(base64_decode($this->png(30, 10)), 200, ['Content-Type' => 'image/png'])]);

        $res = $this->api()->postJson('/api/v1/products', [
            'name' => 'Nước rửa chén ROW', 'category_id' => $cat->id, 'price' => 45000, 'sku' => 'row-nrc', 'brand' => 'ROW Home Care',
            'short_description' => 'Sạch dầu mỡ', 'description' => '<p>Mô tả</p>', 'is_featured' => true, 'variant_label' => 'Hương',
            'images' => [['base64' => $this->png(), 'filename' => 'a.png'], 'https://93.184.216.34/b.png'],
            'variants' => [['name' => 'Sữa gạo', 'image' => 0], ['name' => 'Quế', 'sku' => 'row-nrc-que', 'price' => 50000, 'image' => 1]],
        ])->assertCreated();
        $data = $res->json('data');
        $this->assertSame(['nuoc-rua-chen-row', 'ROW-NRC', 45000, true], [$data['slug'], $data['sku'], $data['price'], $data['is_featured']]);
        // VAT đã bỏ (19/11/2026): API không trả về / không nhận vat_rate; cột trong DB luôn 0
        $this->assertArrayNotHasKey('vat_rate', $data);
        $this->assertSame(0.0, (float) \App\Models\Product::find($data['id'])->getRawOriginal('vat_rate'));
        $this->assertCount(2, $data['images']);
        $this->assertSame([0, 1], array_column($data['variants'], 'image'));
        $this->assertSame([45000, 50000], array_column($data['variants'], 'effective_price'));
        $product = Product::sole();
        Storage::disk('public')->assertExists($product->images->first()->path);
        $this->assertTrue(AuditLog::where('auditable_type', Product::class)->where('action', 'created')->where('user_id', $this->ops->id)->exists());

        // Trùng tên → slug tự thêm hậu tố; SKU trùng → lỗi
        $this->api()->postJson('/api/v1/products', ['name' => 'Nước rửa chén ROW', 'category_id' => $cat->id, 'price' => 1])->assertCreated()->assertJsonPath('data.slug', 'nuoc-rua-chen-row-2');
        $this->api()->postJson('/api/v1/products', ['name' => 'X', 'category_id' => $cat->id, 'price' => 1, 'sku' => 'ROW-NRC'])->assertJsonValidationErrors('sku');
        $this->api()->postJson('/api/v1/products', ['name' => 'X', 'category_id' => 999, 'price' => 'abc'])->assertJsonValidationErrors(['category_id', 'price']);

        // Sửa: đổi giá, đảo ảnh (ảnh 2 lên đầu, bỏ ảnh 1, thêm ảnh mới), giữ biến thể Quế, bỏ Sữa gạo, thêm Chanh sả
        [$img1, $img2] = $data['images'];
        $img1Path = ProductImage::find($img1['id'])->path;
        $que = collect($data['variants'])->firstWhere('name', 'Quế');
        $this->api()->patchJson("/api/v1/products/{$product->id}", [
            'price' => 48000,
            'images' => [['id' => $img2['id']], ['base64' => $this->png()]],
            'variants' => [['id' => $que['id'], 'name' => 'Quế', 'image' => 0], ['name' => 'Chanh sả', 'image' => 1]],
        ])->assertOk()->assertJsonPath('data.price', 48000)->assertJsonPath('data.images.0.id', $img2['id'])
            ->assertJsonPath('data.variants.0.id', $que['id'])->assertJsonPath('data.variants.1.name', 'Chanh sả');
        Storage::disk('public')->assertMissing($img1Path);
        $this->assertSame(2, $product->images()->count());

        // add_images thêm cuối; ảnh của sản phẩm khác / ảnh hỏng / link nội bộ bị chặn
        $this->api()->patchJson("/api/v1/products/{$product->slug}", ['add_images' => [['base64' => $this->png()]]])->assertOk()->assertJsonCount(3, 'data.images');
        $this->api()->patchJson("/api/v1/products/{$product->id}", ['images' => [['base64' => base64_encode('không phải ảnh')]]])->assertJsonValidationErrors('images.0');
        $this->api()->patchJson("/api/v1/products/{$product->id}", ['images' => ['http://127.0.0.1/x.png']])->assertJsonValidationErrors('images.0');
        $this->api()->patchJson("/api/v1/products/{$product->id}", ['images' => ['http://localhost/x.png']])->assertJsonValidationErrors('images.0');
        $this->api()->patchJson("/api/v1/products/{$product->id}", ['variants' => [['name' => 'A'], ['name' => 'a']]])->assertJsonValidationErrors('variants.1.name');
        $this->assertSame(3, $product->images()->count());

        // Danh sách + tìm
        $this->api()->getJson('/api/v1/products?q=row-nrc')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $product->id);

        // Xóa mềm
        $this->api()->deleteJson("/api/v1/products/{$product->id}")->assertOk();
        $this->assertSoftDeleted($product);
        $this->api()->getJson("/api/v1/products/{$product->id}")->assertNotFound();
    }

    public function test_profile_token_management(): void
    {
        $this->actingAs($this->ops)->get('/backend/profile')->assertOk()->assertSee('Token API')->assertSee('Claude');
        $this->post(route('backend.profile.tokens.store'), ['token_name' => 'Claude chat'])->assertRedirect(route('backend.profile.edit'))->assertSessionHas('new_api_token');
        $plain = session('new_api_token');
        $this->assertStringStartsWith('tk_', $plain);
        $this->get('/backend/profile')->assertSee($plain); // flash: hiện một lần
        $this->get('/backend/profile')->assertDontSee($plain);
        $this->assertNotNull(ApiToken::findByPlain($plain));

        $other = User::factory()->create();
        [$foreign] = ApiToken::issue($other, 'của người khác');
        $this->delete(route('backend.profile.tokens.destroy', $foreign->id))->assertNotFound();
        $mine = ApiToken::findByPlain($plain);
        $this->delete(route('backend.profile.tokens.destroy', $mine->id))->assertRedirect();
        $this->assertNull(ApiToken::find($mine->id));
    }
}
