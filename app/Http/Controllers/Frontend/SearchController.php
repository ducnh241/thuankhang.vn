<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\Catalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Tìm kiếm (S1): theo tên, mã, mô tả ngắn; lọc danh mục; sắp xếp giá. */
class SearchController extends Controller
{
    public function __invoke(Request $request, Catalog $catalog): View
    {
        $q = mb_substr($this->queryString($request, 'q'), 0, 100);
        $categoryId = $this->queryString($request, 'category');
        $category = ctype_digit($categoryId) ? $catalog->visible()->firstWhere('id', (int) $categoryId) : null;
        $sort = $catalog->sort($this->queryString($request, 'sort'));

        $products = null;
        if ($q !== '' || $category) {
            $query = $catalog->products()
                ->when($q !== '', function (Builder $query) use ($q) {
                    foreach (preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY) as $word) {
                        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';
                        $query->where(fn ($w) => $w->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("sku LIKE ? ESCAPE '!'", [$like])
                            ->orWhereRaw("short_description LIKE ? ESCAPE '!'", [$like]));
                    }
                })
                ->when($category, fn ($query) => $query->whereIn('category_id', $catalog->subtreeIds($category->id)));
            $products = $catalog->sorted($query, $sort)->with('cover')->paginate(24)->withQueryString();
        }

        return view('frontend.search', compact('q', 'category', 'sort', 'products') + ['categories' => $catalog->visible()]);
    }
}
