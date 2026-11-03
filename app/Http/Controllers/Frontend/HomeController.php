<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use Illuminate\View\View;

/** Trang chủ cửa hàng (S1): banner, danh mục, sản phẩm nổi bật, sản phẩm mới theo từng danh mục gốc. */
class HomeController extends Controller
{
    public function __invoke(Catalog $catalog): View
    {
        $roots = $catalog->roots();
        $featured = $catalog->products()->where('is_featured', true)->with('cover')
            ->orderBy('sort_order')->orderByDesc('id')->limit(8)->get();

        // Mỗi danh mục gốc: 4 sản phẩm (gồm cả danh mục con); danh mục chưa có sản phẩm thì bỏ
        $sections = $roots->map(fn ($category) => [
            'category' => $category,
            'products' => $catalog->products()->whereIn('category_id', $catalog->subtreeIds($category->id))->with('cover')
                ->orderBy('sort_order')->orderByDesc('id')->limit(4)->get(),
        ])->filter(fn ($section) => $section['products']->isNotEmpty())->values();

        return view('frontend.home', compact('roots', 'featured', 'sections'));
    }
}
