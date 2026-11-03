<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use Illuminate\View\View;

/** Chi tiết sản phẩm (S2): ảnh, giá, mô tả; sản phẩm cùng danh mục. Sản phẩm ẩn / đã xóa / danh mục ẩn → 404. */
class ProductController extends Controller
{
    public function __invoke(Catalog $catalog, string $slug): View
    {
        $product = $catalog->products()->where('slug', $slug)->with(['images', 'category'])->first() ?? abort(404);

        $related = $catalog->products()->where('category_id', $product->category_id)->whereKeyNot($product->id)
            ->with('cover')->orderBy('sort_order')->orderByDesc('id')->limit(4)->get();

        return view('frontend.product', [
            'product' => $product,
            'trail' => $catalog->trail($product->category),
            'related' => $related,
        ]);
    }
}
