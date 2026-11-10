<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\ProductReview;
use App\Support\Catalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Thành viên gửi / sửa đánh giá sản phẩm (1–5 sao + nhận xét). Chỉ người đã mua (đơn đã thanh toán có sản phẩm này). */
class ProductReviewController extends Controller
{
    public function store(Request $request, Catalog $catalog, string $slug): RedirectResponse
    {
        $product = $catalog->products()->where('slug', $slug)->firstOrFail();
        $member = $request->user('member');
        $back = redirect()->to($product->url().'#danh-gia');
        if (! ProductReview::canReview($member, $product)) {
            return $back->with('error', 'Chỉ thành viên đã mua sản phẩm (đơn đã thanh toán) mới đánh giá được.');
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.ProductReview::MAX_COMMENT],
        ], ['rating.required' => 'Chọn số sao.', 'rating.between' => 'Chọn từ 1 đến 5 sao.'], ['comment' => 'nhận xét']);

        $review = ProductReview::firstOrNew(['product_id' => $product->id, 'member_id' => $member->id]);
        $editing = $review->exists;
        $review->fill(['rating' => (int) $data['rating'], 'comment' => trim((string) ($data['comment'] ?? '')) ?: null])->save();

        return $back->with('success', $editing ? 'Đã cập nhật đánh giá của bạn.' : 'Cảm ơn bạn đã đánh giá sản phẩm!');
    }
}
