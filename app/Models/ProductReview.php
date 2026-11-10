<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Đánh giá sản phẩm: 1–5 sao + nhận xét. Chỉ thành viên đã mua sản phẩm (có đơn đã thanh toán chứa sản phẩm) mới đánh
 * giá được; mỗi người một đánh giá / sản phẩm (gửi lại = sửa). Quản trị ẩn / hiện (quyền review/moderate).
 */
class ProductReview extends Model
{
    public const MAX_COMMENT = 1000;

    protected $fillable = ['product_id', 'member_id', 'rating', 'comment'];

    protected $attributes = ['is_visible' => true];

    protected function casts(): array
    {
        return ['rating' => 'integer', 'is_visible' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopeVisible(Builder $query): void
    {
        $query->where('is_visible', true);
    }

    /** Thành viên đã mua sản phẩm (đơn đã thanh toán) chưa. */
    public static function canReview(?Member $member, Product $product): bool
    {
        return $member !== null && $member->orders()->whereNotNull('paid_at')
            ->whereHas('items', fn ($q) => $q->where('product_id', $product->id))->exists();
    }
}
