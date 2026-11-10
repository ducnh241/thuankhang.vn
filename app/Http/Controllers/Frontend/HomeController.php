<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang chủ cửa hàng (S1), theo luồng Shoplife (claude/Phan-tich-luong-Shoplife.md mục 4.3): thẻ thành viên (cấp, link
 * giới thiệu, đơn chờ thanh toán) hoặc lời mời đăng ký; hàng sản phẩm nổi bật; link các danh mục gốc; toàn bộ
 * sản phẩm + số sản phẩm + sắp xếp; lưới sản phẩm có lượt bán và nút thêm nhanh vào giỏ.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request, Catalog $catalog): View
    {
        $roots = $catalog->roots();
        $sort = $catalog->sort($this->queryString($request, 'sort'));

        $featured = $catalog->products()->where('is_featured', true)->forCard()
            ->orderBy('sort_order')->orderByDesc('id')->limit(10)->get();

        $products = $catalog->sorted($catalog->products(), $sort)->forCard()->paginate(24)->withQueryString()->fragment('san-pham');

        $member = $request->user('member');
        $pendingOrders = $member
            ? $member->orders()->where('status', Order::STATUS_NEW)->where('expires_at', '>', now())->count()
            : 0;

        return view('frontend.home', compact('roots', 'sort', 'featured', 'products', 'member', 'pendingOrders'));
    }
}
