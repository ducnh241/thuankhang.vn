<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\ProductImageImporter;
use App\Services\ProductVariantSync;
use App\Support\AffiliateSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * API sản phẩm (cùng dữ liệu với trang admin /backend/product). Quyền: product (xem), product/add, product/edit,
 * product/delete. Mọi thay đổi ghi Nhật ký dưới tên tài khoản sở hữu token.
 *
 * Ảnh — "images": danh sách theo thứ tự (phần tử đầu = ảnh đại diện), mỗi phần tử là một trong:
 *   {"id": 12} (ảnh đang có) · {"url": "https://…"} · {"base64": "…", "filename": "a.webp"} · "https://…" (link).
 * Khi sửa: có "images" = thay toàn bộ bộ ảnh (ảnh cũ không có trong danh sách bị xóa); "add_images" = thêm vào cuối.
 * Biến thể — "variants": danh sách đầy đủ (có = đồng bộ: id cũ giữ / sửa, không id thêm, thiếu thì xóa); mỗi phần tử
 *   {"id"?, "name", "sku"?, "price"?, "stock"?, "active"?, "image": số thứ tự ảnh (0 = ảnh đầu) | "image_id"}.
 */
class ProductController extends Controller
{
    public function __construct(private ProductImageImporter $importer) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('product');
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $q = trim((string) ($data['q'] ?? ''));
        $products = Product::query()
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
                $query->where(fn ($w) => $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$like])->orWhereRaw("brand LIKE ? ESCAPE '!'", [$like]));
            })
            ->when(isset($data['category_id']), fn ($query) => $query->where('category_id', (int) $data['category_id']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', (bool) $data['status']))
            ->with(['category:id,name', 'cover', 'variants'])
            ->orderByDesc('id')
            ->paginate((int) ($data['per_page'] ?? 20))
            ->withQueryString();

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $p) => $this->summary($p))->values(),
            'meta' => ['page' => $products->currentPage(), 'per_page' => $products->perPage(), 'total' => $products->total(), 'last_page' => $products->lastPage()],
        ]);
    }

    public function show(string $product): JsonResponse
    {
        Gate::authorize('product');

        return response()->json(['data' => $this->detail($this->find($product))]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('product/add');
        $this->normalize($request);
        $data = $request->validate($this->rules(null), $this->messages());
        $data['slug'] ??= $this->uniqueSlug(Str::slug($data['name']));
        $data['vat_rate'] ??= AffiliateSettings::get('default_vat_percent');

        $product = $this->save(new Product, $data, $request);

        return response()->json(['data' => $this->detail($product)], 201);
    }

    public function update(Request $request, string $product): JsonResponse
    {
        Gate::authorize('product/edit');
        $model = $this->find($product);
        $this->normalize($request);
        $data = $request->validate($this->rules($model), $this->messages());

        $model = $this->save($model, $data, $request);

        return response()->json(['data' => $this->detail($model)]);
    }

    public function destroy(string $product): JsonResponse
    {
        Gate::authorize('product/delete');
        $model = $this->find($product);
        $model->delete();

        return response()->json(['message' => "Đã xóa sản phẩm {$model->name} (xóa mềm — đơn cũ vẫn giữ thông tin)."]);
    }

    /** Lưu sản phẩm + ảnh + biến thể trong một transaction. Ảnh tải về trước (ngoài transaction); lỗi thì dọn tệp. */
    private function save(Product $product, array $data, Request $request): Product
    {
        $images = $request->has('images') ? $this->prepareImages((array) $request->input('images'), 'images', $product) : null;
        $extra = $request->has('add_images') ? $this->prepareImages((array) $request->input('add_images'), 'add_images', $product) : [];
        $imported = collect([...($images ?? []), ...$extra])->where('kind', 'new')->pluck('path')->all();

        try {
            $removedPaths = DB::transaction(function () use ($product, $data, $request, $images, $extra) {
                $fields = collect($data)->except(['images', 'add_images', 'variants'])->all();
                $product->fill($fields)->save();

                $removed = [];
                if ($images !== null) {
                    $keepIds = collect($images)->where('kind', 'existing')->pluck('id')->all();
                    $gone = $product->images()->whereNotIn('id', $keepIds ?: [0])->get();
                    $removed = $gone->pluck('path')->all();
                    $gone->each->delete();
                    $order = $this->materialize($product, $images);
                } else {
                    $order = $product->images()->pluck('id')->all();
                }
                $order = [...$order, ...$this->materialize($product, $extra)];
                foreach ($order as $i => $id) {
                    ProductImage::whereKey($id)->where('sort_order', '!=', $i)->update(['sort_order' => $i]);
                }

                if ($request->has('variants')) {
                    ProductVariantSync::sync($product, $this->variantRows($product, (array) $request->input('variants'), $order));
                }

                return $removed;
            });
        } catch (\Throwable $e) {
            foreach ($imported as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }
        foreach ($removedPaths as $path) {
            if (! ProductImage::where('path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        }

        return $product->refresh();
    }

    /** Kiểm tra + tải trước ảnh mới. Trả về danh sách ['kind' => existing|new, 'id'|'path']. */
    private function prepareImages(array $items, string $field, Product $product): array
    {
        if (count($items) > Product::MAX_IMAGES) {
            throw ValidationException::withMessages([$field => 'Tối đa '.Product::MAX_IMAGES.' ảnh.']);
        }
        $own = $product->exists ? $product->images()->pluck('id')->all() : [];
        $out = [];
        foreach (array_values($items) as $i => $item) {
            $key = "{$field}.{$i}";
            if (is_string($item)) {
                $item = ['url' => $item];
            }
            if (! is_array($item)) {
                throw ValidationException::withMessages([$key => 'Ảnh phải là link, {"url"}, {"base64"} hoặc {"id"}.']);
            }
            if (isset($item['id'])) {
                if (! in_array((int) $item['id'], $own, true)) {
                    throw ValidationException::withMessages([$key => 'Ảnh không thuộc sản phẩm này.']);
                }
                $out[] = ['kind' => 'existing', 'id' => (int) $item['id']];
            } elseif (is_string($item['url'] ?? null)) {
                $out[] = ['kind' => 'new', 'path' => $this->importer->fromUrl($item['url'], $key)];
            } elseif (is_string($item['base64'] ?? null)) {
                $out[] = ['kind' => 'new', 'path' => $this->importer->fromBase64($item['base64'], $key)];
            } else {
                throw ValidationException::withMessages([$key => 'Ảnh phải là link, {"url"}, {"base64"} hoặc {"id"}.']);
            }
        }
        $total = ($field === 'add_images' ? $product->images()->count() : 0) + count($out);
        if ($total > Product::MAX_IMAGES) {
            throw ValidationException::withMessages([$field => 'Tối đa '.Product::MAX_IMAGES.' ảnh mỗi sản phẩm.']);
        }

        return $out;
    }

    /** Tạo bản ghi cho ảnh mới; trả về id theo đúng thứ tự. */
    private function materialize(Product $product, array $items): array
    {
        return array_map(fn ($item) => $item['kind'] === 'existing'
            ? $item['id']
            : $product->images()->create(['path' => $item['path'], 'sort_order' => 999])->id, $items);
    }

    private function variantRows(Product $product, array $items, array $imageOrder): array
    {
        $own = $product->variants()->pluck('id')->all();
        $rows = [];
        $names = [];
        foreach (array_values($items) as $i => $item) {
            $key = "variants.{$i}";
            if (! is_array($item) || ! is_string($item['name'] ?? null) || trim($item['name']) === '') {
                throw ValidationException::withMessages(["{$key}.name" => 'Nhập tên biến thể.']);
            }
            $name = trim($item['name']);
            if (in_array(mb_strtolower($name), $names, true)) {
                throw ValidationException::withMessages(["{$key}.name" => 'Tên biến thể bị trùng.']);
            }
            $names[] = mb_strtolower($name);
            $id = isset($item['id']) ? (int) $item['id'] : null;
            if ($id && ! in_array($id, $own, true)) {
                throw ValidationException::withMessages(["{$key}.id" => 'Biến thể không thuộc sản phẩm này.']);
            }
            $sku = isset($item['sku']) && trim((string) $item['sku']) !== '' ? Str::upper(trim((string) $item['sku'])) : null;
            if ($sku !== null && (! preg_match('/^[A-Z0-9._-]{1,50}$/', $sku) || ProductVariant::where('sku', $sku)->when($id, fn ($q) => $q->whereKeyNot($id))->exists())) {
                throw ValidationException::withMessages(["{$key}.sku" => 'SKU biến thể không hợp lệ hoặc đã có biến thể khác dùng.']);
            }
            foreach (['price' => 100_000_000_000, 'stock' => 10_000_000] as $f => $max) {
                if (isset($item[$f]) && (! is_numeric($item[$f]) || (int) $item[$f] < 0 || (int) $item[$f] > $max)) {
                    throw ValidationException::withMessages(["{$key}.{$f}" => 'Phải là số nguyên không âm.']);
                }
            }
            $imageId = null;
            if (isset($item['image'])) {
                $imageId = $imageOrder[(int) $item['image']] ?? throw ValidationException::withMessages(["{$key}.image" => 'Số thứ tự ảnh không tồn tại (0 = ảnh đầu).']);
            } elseif (isset($item['image_id'])) {
                $imageId = in_array((int) $item['image_id'], $imageOrder, true) ? (int) $item['image_id'] : throw ValidationException::withMessages(["{$key}.image_id" => 'Ảnh không thuộc sản phẩm này.']);
            }
            $rows[] = [
                'id' => $id, 'name' => mb_substr($name, 0, 100), 'sku' => $sku,
                'price' => isset($item['price']) ? (int) $item['price'] : null, 'stock' => isset($item['stock']) ? (int) $item['stock'] : null,
                'image_id' => $imageId, 'active' => (bool) ($item['active'] ?? true), 'sort_order' => $i,
            ];
        }
        if (count($rows) > Product::MAX_VARIANTS) {
            throw ValidationException::withMessages(['variants' => 'Tối đa '.Product::MAX_VARIANTS.' biến thể.']);
        }

        return $rows;
    }

    private function normalize(Request $request): void
    {
        $merge = [];
        if ($request->has('slug')) {
            $merge['slug'] = Str::slug((string) $request->input('slug')) ?: null;
        }
        if (is_string($sku = $request->input('sku'))) {
            $merge['sku'] = trim($sku) === '' ? null : Str::upper(trim($sku));
        }
        foreach (['brand', 'origin', 'short_description', 'meta_title', 'meta_description', 'variant_label'] as $f) {
            if (is_string($v = $request->input($f))) {
                $merge[$f] = trim($v) === '' ? null : trim($v);
            }
        }
        $request->merge($merge);
    }

    private function rules(?Product $product): array
    {
        $req = $product ? 'sometimes' : 'required';

        return [
            'name' => [$req, 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('products', 'slug')->ignore($product)],
            'sku' => ['sometimes', 'nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($product)],
            'category_id' => [$req, 'integer', Rule::exists('categories', 'id')->where('type', Category::TYPE_PRODUCT)],
            'price' => [$req, 'integer', 'min:0', 'max:100000000000'],
            'vat_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'stock' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000000'],
            'brand' => ['sometimes', 'nullable', 'string', 'max:100'],
            'origin' => ['sometimes', 'nullable', 'string', 'max:100'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500000'],
            'meta_title' => ['sometimes', 'nullable', 'string', 'max:70'],
            'meta_description' => ['sometimes', 'nullable', 'string', 'max:160'],
            'status' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:-9999', 'max:9999'],
            'variant_label' => ['sometimes', 'nullable', 'string', 'max:50'],
            'images' => ['sometimes', 'array', 'max:'.Product::MAX_IMAGES],
            'add_images' => ['sometimes', 'array', 'max:'.Product::MAX_IMAGES],
            'variants' => ['sometimes', 'array', 'max:'.Product::MAX_VARIANTS],
        ];
    }

    private function messages(): array
    {
        return [
            'slug.unique' => 'Đường dẫn này đã có sản phẩm khác dùng (kể cả sản phẩm đã xóa).',
            'sku.unique' => 'Mã SKU đã có sản phẩm khác dùng.',
            'category_id.exists' => 'Danh mục sản phẩm không tồn tại.',
            'price.integer' => 'Giá là số nguyên (đồng), đã gồm VAT.',
        ];
    }

    private function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : 'san-pham';
        $slug = $base;
        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /** Tìm theo id hoặc slug (chưa xóa). */
    private function find(string $key): Product
    {
        return Product::when(ctype_digit($key), fn ($q) => $q->whereKey((int) $key), fn ($q) => $q->where('slug', $key))->firstOrFail();
    }

    private function summary(Product $p): array
    {
        return [
            'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'sku' => $p->sku, 'url' => url($p->url()),
            'category' => $p->category ? ['id' => $p->category->id, 'name' => $p->category->name] : null,
            'price' => $p->price, 'status' => $p->status, 'is_featured' => $p->is_featured, 'brand' => $p->brand,
            'cover' => $p->cover?->url(), 'variant_count' => $p->variants->count(), 'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }

    private function detail(Product $p): array
    {
        $p->load(['category:id,name,slug', 'images', 'variants']);
        $index = $p->images->pluck('id')->flip();

        return [
            ...collect($p->only(['id', 'name', 'slug', 'sku', 'price', 'stock', 'brand', 'origin', 'short_description', 'description',
                'meta_title', 'meta_description', 'status', 'is_featured', 'sort_order', 'variant_label']))->all(),
            'vat_rate' => (float) $p->vat_rate,
            'url' => url($p->url()),
            'category' => $p->category ? $p->category->only(['id', 'name', 'slug']) : null,
            'images' => $p->images->map(fn ($img, $i) => ['id' => $img->id, 'index' => $i, 'url' => $img->url()])->values(),
            'variants' => $p->variants->map(fn (ProductVariant $v) => [
                'id' => $v->id, 'name' => $v->name, 'sku' => $v->sku, 'price' => $v->price, 'effective_price' => $v->price ?? $p->price,
                'stock' => $v->stock, 'active' => $v->active, 'image_id' => $v->image_id, 'image' => $v->image_id !== null ? ($index[$v->image_id] ?? null) : null,
            ])->values(),
            'created_at' => $p->created_at?->toIso8601String(),
            'updated_at' => $p->updated_at?->toIso8601String(),
        ];
    }
}
