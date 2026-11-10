<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Chi tiết sản phẩm (S2): ảnh, giá, mô tả; sản phẩm cùng danh mục. Sản phẩm ẩn / danh mục ẩn → 404 với khách; người
 * quản trị có quyền sản phẩm (đang đăng nhập) vẫn xem được — chế độ xem trước (nút "Xem trước" ở trang quản trị mở
 * đúng link thật này): có dải báo, không cho máy tìm kiếm lập chỉ mục. Sản phẩm đã xóa → 404 với mọi người.
 */
class ProductController extends Controller
{
    public function __invoke(Request $request, Catalog $catalog, string $slug): View
    {
        $product = $catalog->products()->where('slug', $slug)->first();
        $preview = false;
        if (! $product && $request->user()?->can('product')) {
            $product = Product::where('slug', $slug)->first();
            $preview = true;
        }
        abort_unless($product, 404);

        $product->load(['images', 'category']);
        $related = $catalog->products()->where('category_id', $product->category_id)->whereKeyNot($product->id)
            ->forCard()->orderBy('sort_order')->orderByDesc('id')->limit(4)->get();

        return view('frontend.product', [
            'product' => $product,
            'trail' => $catalog->trail($product->category),
            'related' => $related,
            'preview' => $preview ? ['editUrl' => $request->user()->can('product/edit') ? route('backend.products.edit', $product) : null] : null,
        ]);
    }
}
