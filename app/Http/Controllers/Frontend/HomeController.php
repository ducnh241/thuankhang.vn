<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang chủ cửa hàng (S1), theo luồng Shoplife (claude/Phan-tich-luong-Shoplife.md mục 4.3): thẻ thành viên (cấp, link
 * giới thiệu, đơn chờ thanh toán) hoặc lời mời đăng ký; slider sản phẩm nổi bật; rồi từng DANH MỤC GỐC một khối (27/11/2026):
 * tối đa 10 sản phẩm (gồm cả sản phẩm của danh mục con), kèm link "Xem tất cả" sang trang danh mục. Thứ tự các khối = thứ tự
 * danh mục gốc ở trang quản trị Danh mục (kéo thả). Danh mục chưa có sản phẩm thì không hiện khối.
 */
class HomeController extends Controller
{
    /** Số sản phẩm mỗi danh mục ở trang chủ. */
    public const PER_CATEGORY = 10;

    public function __invoke(Request $request, Catalog $catalog): View
    {
        $roots = $catalog->roots();

        $featured = $catalog->products()->where('is_featured', true)->forCard()
            ->orderBy('sort_order')->orderByDesc('id')->limit(10)->get();

        // Một truy vấn đếm theo danh mục, rồi cộng theo cây con của từng danh mục gốc
        $counts = $catalog->products()->selectRaw('category_id, count(*) as total')->groupBy('category_id')->pluck('total', 'category_id');
        $sections = $roots->map(function ($category) use ($catalog, $counts) {
            $ids = $catalog->subtreeIds($category->id);
            $total = (int) collect($ids)->sum(fn ($id) => $counts[$id] ?? 0);

            return $total === 0 ? null : [
                'category' => $category,
                'total' => $total,
                'products' => $catalog->sorted($catalog->products()->whereIn('category_id', $ids), '')->forCard()->limit(self::PER_CATEGORY)->get(),
            ];
        })->filter()->values();

        $member = $request->user('member');
        $pendingOrders = $member
            ? $member->orders()->where('status', Order::STATUS_NEW)->where('expires_at', '>', now())->count()
            : 0;

        return view('frontend.home', compact('roots', 'featured', 'sections', 'member', 'pendingOrders'));
    }
}
