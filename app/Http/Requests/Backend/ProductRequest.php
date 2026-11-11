<?php

namespace App\Http\Requests\Backend;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Money;
use App\Support\Percent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Thêm / sửa sản phẩm. Giá nhập "1.100.000" (đã gồm VAT) → số nguyên; VAT "10" / "8,5". Đường dẫn để trống thì theo
 * tên. Ảnh: keep_images[] = id ảnh đang có giữ lại, theo thứ tự (đầu tiên = ảnh đại diện); images[] = ảnh mới thêm
 * vào cuối. Tổng tối đa Product::MAX_IMAGES ảnh.
 */
class ProductRequest extends FormRequest
{
    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash:ascii', Rule::unique('products', 'slug')->ignore($product)],
            'sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($product)],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('type', Category::TYPE_PRODUCT)],
            'price' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'vat_rate' => ['required', 'string'],
            'brand' => ['nullable', 'string', 'max:100'],
            'origin' => ['nullable', 'string', 'max:100'],
            'stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'variant_label' => ['nullable', 'string', 'max:50'],
            'variants' => ['nullable', 'array', 'max:'.Product::MAX_VARIANTS],
            'variants.*' => ['array'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.name' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'variants.*.sku' => ['nullable', 'string', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/', 'distinct:ignore_case'],
            'variants.*.price' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'variants.*.image_id' => ['nullable', 'integer'],
            'variants.*.active' => ['nullable', 'boolean'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'description' => ['nullable', 'string', 'max:500000'],
            'meta_title' => ['nullable', 'string', 'max:70'],
            'meta_description' => ['nullable', 'string', 'max:160'],
            'status' => ['required', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-9999', 'max:9999'],
            'keep_images' => ['nullable', 'array'],
            'keep_images.*' => ['integer'],
            'images' => ['nullable', 'array', 'max:'.Product::MAX_IMAGES],
            'images.*' => ['image', 'mimes:png,jpg,jpeg,webp', 'max:4096'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'tên sản phẩm', 'slug' => 'đường dẫn', 'sku' => 'mã sản phẩm', 'category_id' => 'danh mục', 'price' => 'giá bán',
            'vat_rate' => 'VAT', 'brand' => 'thương hiệu', 'origin' => 'xuất xứ', 'stock' => 'tồn kho', 'short_description' => 'mô tả ngắn', 'description' => 'mô tả', 'meta_title' => 'tiêu đề SEO', 'meta_description' => 'mô tả SEO', 'sort_order' => 'thứ tự', 'images' => 'ảnh', 'images.*' => 'ảnh',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.required' => 'Nhập đường dẫn (hoặc tên có chữ / số để tự tạo).',
            'slug.unique' => 'Đường dẫn này đã có sản phẩm khác dùng (kể cả sản phẩm đã xóa).',
            'sku.unique' => 'Mã sản phẩm đã có sản phẩm khác dùng.',
            'sku.regex' => 'Mã sản phẩm chỉ gồm chữ không dấu, số và các dấu . _ -',
            'category_id.required' => 'Chọn danh mục.',
            'category_id.exists' => 'Danh mục không hợp lệ.',
            'price.integer' => 'Giá bán là số tiền nguyên (đồng).',
            'stock.integer' => 'Tồn kho là số nguyên (để trống = không theo dõi).',
            'stock.min' => 'Tồn kho không được âm.',
            'variants.max' => 'Tối đa '.Product::MAX_VARIANTS.' biến thể.',
            'variants.*.name.required' => 'Nhập tên biến thể.',
            'variants.*.name.distinct' => 'Tên biến thể bị trùng.',
            'variants.*.sku.distinct' => 'SKU biến thể bị trùng.',
            'variants.*.sku.regex' => 'SKU chỉ gồm chữ không dấu, số và . _ -',
            'variants.*.price.integer' => 'Giá là số tiền nguyên.',
            'variants.*.stock.integer' => 'Tồn kho là số nguyên.',
            'variants.*.stock.min' => 'Tồn kho không được âm.',
            'images.max' => 'Tối đa '.Product::MAX_IMAGES.' ảnh.',
            'images.*.image' => 'Chọn ảnh PNG, JPG hoặc WEBP.',
            'images.*.mimes' => 'Chọn ảnh PNG, JPG hoặc WEBP.',
            'images.*.max' => 'Mỗi ảnh tối đa 4 MB.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        $source = $this->filled('slug') ? $this->input('slug') : $this->input('name');
        $data['slug'] = is_string($source) || $source === null ? Str::slug((string) $source) : $source;
        $price = $this->input('price');
        if (is_string($price) && trim($price) !== '' && ($parsed = Money::parse($price)) !== null && ! str_contains($parsed, '.')) {
            $data['price'] = $parsed;
        }
        $stock = $this->input('stock');
        if (is_string($stock)) {
            $stock = str_replace(['.', ' '], '', trim($stock));
            $data['stock'] = $stock === '' ? null : $stock;
        }
        if (is_array($variants = $this->input('variants'))) {
            $data['variants'] = array_map(function ($row) {
                if (! is_array($row)) {
                    return $row;
                }
                foreach (['name', 'sku', 'price', 'stock', 'image_id'] as $f) {
                    $row[$f] = is_string($row[$f] ?? null) && trim($row[$f]) !== '' ? trim($row[$f]) : null;
                }
                if ($row['sku'] !== null) {
                    $row['sku'] = Str::upper($row['sku']);
                }
                if ($row['price'] !== null && ($parsed = Money::parse($row['price'])) !== null && ! str_contains($parsed, '.')) {
                    $row['price'] = $parsed;
                }
                if ($row['stock'] !== null) {
                    $row['stock'] = str_replace(['.', ' '], '', $row['stock']);
                }

                return $row;
            }, $variants);
        }
        if (is_string($sku = $this->input('sku'))) {
            $data['sku'] = trim($sku) === '' ? null : Str::upper(trim($sku));
        }
        $this->merge($data);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if (! $validator->errors()->has('vat_rate') && Percent::parse($this->input('vat_rate')) === null) {
                $validator->errors()->add('vat_rate', 'Nhập VAT từ 0 đến 100, tối đa 2 chữ số lẻ.');
            }
            $this->after_variants($validator);
            $total = count($this->keptImageIds()) + count($this->file('images') ?? []);
            if ($total > Product::MAX_IMAGES) {
                $validator->errors()->add('images', 'Tối đa '.Product::MAX_IMAGES.' ảnh (đang có '.$total.').');
            }
        }];
    }

    /** Id ảnh đang có được giữ lại, đúng thứ tự gửi lên (chỉ ảnh của chính sản phẩm này). */
    public function keptImageIds(): array
    {
        $product = $this->route('product');
        if (! $product instanceof Product) {
            return [];
        }
        $own = $product->images()->pluck('id')->all();

        return array_values(array_unique(array_filter(array_map('intval', (array) $this->input('keep_images', [])), fn ($id) => in_array($id, $own, true))));
    }

    public function after_variants(Validator $validator): void
    {
        $product = $this->route('product');
        $own = $product instanceof Product ? $product->variants()->pluck('id')->all() : [];
        $ownImages = $product instanceof Product ? $product->images()->pluck('id')->all() : [];
        $skus = [];
        foreach ((array) $this->input('variants', []) as $i => $row) {
            $id = is_array($row) && isset($row['id']) && ctype_digit((string) $row['id']) ? (int) $row['id'] : null;
            if ($id !== null && ! in_array($id, $own, true)) {
                $validator->errors()->add("variants.{$i}.name", 'Biến thể không thuộc sản phẩm này — tải lại trang.');
            }
            $imageId = is_array($row) && isset($row['image_id']) && ctype_digit((string) $row['image_id']) ? (int) $row['image_id'] : null;
            if ($imageId !== null && ! in_array($imageId, $ownImages, true)) {
                $validator->errors()->add("variants.{$i}.image_id", 'Ảnh không thuộc sản phẩm này.');
            }
            if (is_array($row) && is_string($row['sku'] ?? null)) {
                $taken = ProductVariant::where('sku', $row['sku'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists();
                if ($taken) {
                    $validator->errors()->add("variants.{$i}.sku", 'SKU này đã có biến thể khác dùng.');
                }
            }
        }
    }

    /** Biến thể đã chuẩn hóa để controller đồng bộ: [id|null, name, sku, price, stock, image_id, active, sort_order]. */
    public function variantRows(): array
    {
        return array_values(array_map(fn ($row, $i) => [
            'id' => isset($row['id']) && ctype_digit((string) $row['id']) ? (int) $row['id'] : null,
            'name' => $row['name'], 'sku' => $row['sku'] ?? null,
            'price' => isset($row['price']) ? (int) $row['price'] : null, 'stock' => isset($row['stock']) ? (int) $row['stock'] : null,
            // Ảnh bị gỡ trong cùng lần lưu: về ảnh đại diện
            'image_id' => isset($row['image_id']) && in_array((int) $row['image_id'], $this->keptImageIds(), true) ? (int) $row['image_id'] : null,
            'active' => (string) ($row['active'] ?? '0') === '1', 'sort_order' => $i,
        ], $v = array_values($this->validated('variants') ?? []), array_keys($v)));
    }

    /** Dữ liệu lưu vào bảng products (ảnh xử lý riêng ở controller). */
    public function productData(): array
    {
        $trim = fn ($v) => is_string($v) && trim($v) !== '' ? trim($v) : null;
        $description = $this->validated('description');

        return [
            'name' => trim($this->validated('name')),
            'slug' => $this->validated('slug'),
            'sku' => $this->validated('sku'),
            'category_id' => (int) $this->validated('category_id'),
            'price' => (int) $this->validated('price'),
            'vat_rate' => Percent::parse($this->validated('vat_rate')),
            'brand' => $trim($this->validated('brand')),
            'origin' => $trim($this->validated('origin')),
            'stock' => $this->validated('stock') === null ? null : (int) $this->validated('stock'),
            'variant_label' => $trim($this->validated('variant_label')),
            'short_description' => $trim($this->validated('short_description')),
            // Trình soạn thảo trống vẫn gửi "<p></p>" → coi như không có mô tả
            'description' => trim(strip_tags((string) $description, '<img><iframe><video><hr>')) === '' ? null : $description,
            'meta_title' => $trim($this->validated('meta_title')),
            'meta_description' => $trim($this->validated('meta_description')),
            'status' => $this->boolean('status'),
            'is_featured' => $this->boolean('is_featured'),
            'sort_order' => (int) ($this->validated('sort_order') ?? 0),
        ];
    }
}
