<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductReview;
use App\Support\Catalog;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Chi tiết sản phẩm (S2, kiểu Shoplife): ảnh, giá, lượt bán, chọn số lượng + Thêm vào giỏ / Mua ngay, chat Zalo / hotline,
 * link giới thiệu sản phẩm (?ref=mã của thành viên đã kích hoạt), thương hiệu / xuất xứ / tồn kho, mô tả, đánh giá sao
 * (người đã mua mới gửi được), sản phẩm cùng danh mục. Sản phẩm ẩn / danh mục ẩn → 404 với khách; người
 * quản trị có quyền sản phẩm (đang đăng nhập) vẫn xem được — chế độ xem trước (nút "Xem trước" ở trang quản trị mở
 * đúng link thật này): có dải báo, không cho máy tìm kiếm lập chỉ mục. Sản phẩm đã xóa → 404 với mọi người.
 */
class ProductController extends Controller
{
    /** Số đánh giá mới nhất hiện ở trang sản phẩm. */
    public const REVIEW_LIMIT = 20;

    public function __invoke(Request $request, Catalog $catalog, string $slug): View
    {
        $product = $catalog->products()->where('slug', $slug)->first();
        $preview = false;
        if (! $product && $request->user()?->can('product')) {
            $product = Product::where('slug', $slug)->first();
            $preview = true;
        }
        abort_unless($product, 404);

        $product->load(['images', 'category', 'activeVariants'])
            ->loadSum(['orderItems as sold_qty' => fn ($q) => $q->whereHas('order', fn ($o) => $o->whereNotNull('paid_at'))], 'qty');
        $related = $catalog->products()->where('category_id', $product->category_id)->whereKeyNot($product->id)
            ->forCard()->orderBy('sort_order')->orderByDesc('id')->limit(8)->get();
        $member = $request->user('member');

        // Đánh giá: chỉ đánh giá đang hiện; thống kê theo số sao; đánh giá của chính người xem (kể cả đang bị ẩn)
        $visible = $product->reviews()->visible();
        $stats = (clone $visible)->selectRaw('rating, COUNT(*) as total')->groupBy('rating')->pluck('total', 'rating');
        $reviewCount = (int) $stats->sum();

        return view('frontend.product', [
            'product' => $product,
            'trail' => $catalog->trail($product->category),
            'related' => $related,
            'member' => $member,
            'reviews' => (clone $visible)->with('member:id,full_name')->latest('id')->limit(ProductController::REVIEW_LIMIT)->get(),
            'reviewStats' => [
                'count' => $reviewCount,
                'avg' => $reviewCount ? round($stats->reduce(fn ($sum, $total, $rating) => $sum + $rating * $total, 0) / $reviewCount, 1) : null,
                'bars' => collect([5, 4, 3, 2, 1])->mapWithKeys(fn ($star) => [$star => (int) ($stats[$star] ?? 0)]),
            ],
            'myReview' => $member ? $product->reviews()->where('member_id', $member->id)->first() : null,
            'canReview' => ProductReview::canReview($member, $product),
            // Link giới thiệu chỉ cho thành viên đã kích hoạt (mới được nhận hoa hồng — đặc tả S2)
            'shareLink' => $member?->isActivated() ? url($product->url()).'?ref='.$member->referral_code : null,
            'preview' => $preview ? ['editUrl' => $request->user()->can('product/edit') ? route('backend.products.edit', $product) : null] : null,
        ]);
    }
}
