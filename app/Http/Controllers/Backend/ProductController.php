<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\ProductRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Support\AffiliateSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Sản phẩm (A6): danh sách (tab đang bán / đang ẩn, tìm theo tên / mã, lọc danh mục gồm cả danh mục con), trang thêm /
 * sửa (giá đã gồm VAT, VAT %, ảnh nhiều tấm, mô tả soạn bằng trình soạn thảo). Xóa = xóa mềm (đơn hàng còn tham chiếu).
 */
class ProductController extends Controller
{
    public const TABS = ['' => 'Tất cả', 'on_sale' => 'Đang bán', 'hidden' => 'Đang ẩn'];

    public function index(Request $request): View|RedirectResponse
    {
        $tab = $this->queryString($request, 'tab');
        $categories = Category::tree(Category::TYPE_PRODUCT);
        $categoryId = $this->queryString($request, 'category_id');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, self::TABS) ? $tab : '',
            'category_id' => ctype_digit($categoryId) && $categories->contains('id', (int) $categoryId) ? $categoryId : '',
        ];

        $base = Product::query()
            ->when($filter['q'] !== '', function (Builder $query) use ($filter) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
                $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$like])->orWhereRaw("sku LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($filter['category_id'] !== '', fn ($q) => $q->whereIn('category_id', $this->subtreeIds($categories, (int) $filter['category_id'])));

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
        $counts = ['' => (int) $byStatus->sum(), 'on_sale' => (int) ($byStatus[1] ?? 0), 'hidden' => (int) ($byStatus[0] ?? 0)];

        $products = (clone $base)
            ->when($filter['tab'] !== '', fn ($q) => $q->where('status', $filter['tab'] === 'on_sale'))
            ->with(['category:id,name', 'cover'])
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        if ($products->isEmpty() && $products->currentPage() > 1) {
            return redirect($products->url($products->lastPage()));
        }

        return view('backend.products.index', compact('products', 'filter', 'counts', 'categories'));
    }

    public function create(): View
    {
        return $this->form(new Product(['vat_rate' => AffiliateSettings::get('default_vat_percent')]));
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request) {
            $product = Product::create($request->productData());
            $this->addImages($product, $request->file('images') ?? [], 0);

            return $product;
        });

        return ($request->user()->can('product/edit')
            ? redirect()->route('backend.products.edit', $product)
            : redirect()->route('backend.products.index'))->with('success', "Đã tạo sản phẩm {$product->name}.");
    }

    public function edit(Product $product): View
    {
        return $this->form($product->load('images'));
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        $removed = DB::transaction(function () use ($request, $product) {
            $product->update($request->productData());

            // Ảnh: giữ đúng các ảnh còn trong form theo thứ tự mới, bỏ ảnh đã gỡ, thêm ảnh mới vào cuối
            $keep = $request->keptImageIds();
            $removed = $product->images()->whereNotIn('id', $keep)->get();
            $removed->each->delete();
            foreach ($keep as $i => $id) {
                ProductImage::whereKey($id)->where('sort_order', '!=', $i)->first()?->update(['sort_order' => $i]);
            }
            $this->addImages($product, $request->file('images') ?? [], count($keep));

            return $removed;
        });
        // Tệp ảnh chỉ xóa sau khi lưu xong
        $removed->each(fn (ProductImage $image) => $this->deleteFile($image->path));

        return redirect()->route('backend.products.edit', $product)->with('success', 'Đã lưu sản phẩm.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->delete();

        $back = url()->previous();
        $toList = $back === route('backend.products.edit', $product) || ! str_starts_with($back, route('backend.products.index'));

        return ($toList ? redirect()->route('backend.products.index') : back())->with('success', "Đã xóa sản phẩm {$product->name}.");
    }

    private function form(Product $product): View
    {
        return view('backend.products.form', [
            'product' => $product,
            'categories' => Category::tree(Category::TYPE_PRODUCT),
            'businessFee' => AffiliateSettings::get('business_fee_percent'),
        ]);
    }

    private function addImages(Product $product, array $files, int $start): void
    {
        foreach (array_values($files) as $i => $file) {
            $product->images()->create(['path' => $file->store(Product::IMAGE_DIRECTORY, 'public'), 'sort_order' => $start + $i]);
        }
    }

    private function deleteFile(string $path): void
    {
        if (str_starts_with($path, Product::IMAGE_DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }

    /** Id danh mục và mọi danh mục con cháu của nó (cây đã nạp sẵn). */
    private function subtreeIds(Collection $categories, int $rootId): array
    {
        $ids = [$rootId];
        $byParent = $categories->groupBy(fn ($c) => $c->parent_id ?? 0);
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($byParent[$ids[$i]] ?? [] as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                }
            }
        }

        return $ids;
    }
}
