<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\ProductReview;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Đánh giá sản phẩm: danh sách (tab đang hiện / đã ẩn, lọc số sao, tìm sản phẩm / thành viên), ẩn / hiện từng đánh giá
 * (review/moderate, lý do không bắt buộc, ghi nhật ký). Không sửa / xóa nội dung của khách.
 */
class ProductReviewController extends Controller
{
    public const TABS = ['' => 'Tất cả', 'visible' => 'Đang hiện', 'hidden' => 'Đã ẩn'];

    public function index(Request $request): View|RedirectResponse
    {
        $tab = $this->queryString($request, 'tab');
        $rating = $this->queryString($request, 'rating');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, self::TABS) ? $tab : '',
            'rating' => in_array($rating, ['1', '2', '3', '4', '5'], true) ? $rating : '',
        ];

        $base = ProductReview::query()
            ->when($filter['q'] !== '', function (Builder $query) use ($filter) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
                $phone = Member::normalizePhone($filter['q']);
                $query->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->withTrashed()->whereRaw("name LIKE ? ESCAPE '!'", [$like]))
                    ->orWhereHas('member', fn ($m) => $m->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like])->when($phone, fn ($m) => $m->orWhere('phone', $phone)))
                    ->orWhereRaw("comment LIKE ? ESCAPE '!'", [$like]));
            })
            ->when($filter['rating'] !== '', fn ($q) => $q->where('rating', (int) $filter['rating']));

        $counts = [
            '' => (clone $base)->count(),
            'visible' => (clone $base)->where('is_visible', true)->count(),
            'hidden' => (clone $base)->where('is_visible', false)->count(),
        ];

        $reviews = (clone $base)
            ->when($filter['tab'] !== '', fn ($q) => $q->where('is_visible', $filter['tab'] === 'visible'))
            ->with(['product:id,name,slug,deleted_at', 'member:id,full_name,phone'])
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        if ($reviews->isEmpty() && $reviews->currentPage() > 1) {
            return redirect($reviews->url($reviews->lastPage()));
        }

        return view('backend.reviews.index', compact('reviews', 'filter', 'counts'));
    }

    /** Ẩn (action = hide) / hiện lại (action = show). */
    public function visibility(Request $request, ProductReview $review): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:hide,show'],
            'reason' => ['nullable', 'string', 'max:300'],
        ]);
        $visible = $data['action'] === 'show';
        if ($review->is_visible === $visible) {
            return back()->with('error', $visible ? 'Đánh giá đang hiện.' : 'Đánh giá đã bị ẩn từ trước.');
        }

        $review->forceFill(['is_visible' => $visible])->save();
        $product = $review->product?->name ?? 'sản phẩm';
        AuditLog::record('updated', $review, ['old' => ['is_visible' => ! $visible], 'new' => ['is_visible' => $visible]],
            ($visible ? 'Hiện lại' : 'Ẩn').' đánh giá '.$review->rating."★ của {$review->member?->full_name} cho “{$product}”"
            .(! empty($data['reason']) ? '. Lý do: '.trim($data['reason']) : ''));

        return back()->with('success', $visible ? 'Đã hiện lại đánh giá.' : 'Đã ẩn đánh giá khỏi trang sản phẩm.');
    }
}
