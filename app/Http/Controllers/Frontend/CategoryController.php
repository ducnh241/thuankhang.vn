<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Trang danh mục (S1): sản phẩm của danh mục và mọi danh mục con đang hiện, sắp xếp, phân trang. */
class CategoryController extends Controller
{
    public function __invoke(Request $request, Catalog $catalog, string $slug): View
    {
        $category = $catalog->visible()->firstWhere('slug', $slug) ?? abort(404);
        $sort = $catalog->sort($this->queryString($request, 'sort'));

        $products = $catalog->sorted($catalog->products()->whereIn('category_id', $catalog->subtreeIds($category->id)), $sort)
            ->forCard()->paginate(24)->withQueryString();

        return view('frontend.category', [
            'category' => $category,
            'trail' => $catalog->trail($category),
            'children' => $catalog->visible()->where('parent_id', $category->id)->values(),
            'products' => $products,
            'sort' => $sort,
        ]);
    }
}
