<?php

namespace App\Models;

use App\Support\Cart;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Sản phẩm của cửa hàng (A6). price = giá bán (đồng, đã gồm thuế). Cột vat_rate còn trong DB nhưng KHÔNG dùng nữa (chủ dự án
 * bỏ VAT 19/11/2026 — luôn ghi 0, sẽ cập nhật dữ liệu cũ về 0). Ảnh: bảng
 * product_images, ảnh sort_order nhỏ nhất là ảnh đại diện.
 */
class Product extends Model
{
    use HasFactory, SoftDeletes;

    /** Đoạn đầu đường dẫn trang sản phẩm trên website. */
    public const PATH = 'san-pham';

    /** Thư mục ảnh trên disk public. */
    public const IMAGE_DIRECTORY = 'products';

    public const MAX_IMAGES = 10;

    public const MAX_VARIANTS = 50;

    protected $fillable = ['category_id', 'name', 'slug', 'sku', 'brand', 'origin', 'price', 'stock', 'variant_label', 'short_description', 'description', 'meta_title', 'meta_description', 'status', 'is_featured', 'sort_order'];

    protected $attributes = [
        'status' => true,
        'is_featured' => false,
        'sort_order' => 0,
        'vat_rate' => 0, // cột cũ, không dùng
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'stock' => 'integer',
            'status' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Ảnh đại diện (ảnh đầu tiên). */
    public function cover(): HasOne
    {
        return $this->hasOne(ProductImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    /**
     * Dữ liệu cho thẻ sản phẩm: ảnh đại diện, lượt bán (`sold_qty` = tổng số lượng trong các đơn đã thanh toán), điểm đánh
     * giá (`rating_avg`, `rating_count` — chỉ đánh giá đang hiện).
     */
    public function scopeForCard(Builder $query): void
    {
        $query->with(['cover', 'activeVariants'])
            ->withSum(['orderItems as sold_qty' => fn ($q) => $q->whereHas('order', fn ($o) => $o->whereNotNull('paid_at'))], 'qty')
            ->withAvg(['reviews as rating_avg' => fn ($q) => $q->where('is_visible', true)], 'rating')
            ->withCount(['reviews as rating_count' => fn ($q) => $q->where('is_visible', true)]);
    }

    /** Tất cả biến thể (admin), theo thứ tự. */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }

    /** Biến thể đang bán (website). */
    public function activeVariants(): HasMany
    {
        return $this->variants()->where('active', true);
    }

    /** Có biến thể đang bán → khách phải chọn một biến thể; giá / tồn kho tính theo biến thể. */
    public function hasVariants(): bool
    {
        return $this->activeVariants->isNotEmpty();
    }

    /** [giá thấp nhất, giá cao nhất] của các biến thể đang bán (không có biến thể: giá sản phẩm). */
    public function priceRange(): array
    {
        if (! $this->hasVariants()) {
            return [$this->price, $this->price];
        }
        $prices = $this->activeVariants->map(fn (ProductVariant $v) => $v->effectivePrice($this));

        return [$prices->min(), $prices->max()];
    }

    /** Có theo dõi tồn kho không (stock = NULL: không giới hạn số lượng bán). Sản phẩm có biến thể: theo từng biến thể. */
    public function tracksStock(): bool
    {
        return $this->stock !== null && ! $this->hasVariants();
    }

    /** Hết hàng: sản phẩm thường hết tồn, hoặc mọi biến thể đang bán đều hết. */
    public function isOutOfStock(): bool
    {
        if ($this->hasVariants()) {
            return $this->activeVariants->every(fn (ProductVariant $v) => $v->isOutOfStock());
        }

        return $this->tracksStock() && $this->stock <= 0;
    }

    /** Số lượng tối đa được đặt một lần: min(giới hạn giỏ, tồn kho nếu có theo dõi). */
    public function maxOrderQty(): int
    {
        return $this->tracksStock() ? max(0, min(Cart::MAX_QTY, $this->stock)) : Cart::MAX_QTY;
    }

    public function scopeOnSale(Builder $query): void
    {
        $query->where('status', true);
    }

    public function url(): string
    {
        return '/'.self::PATH.'/'.$this->slug;
    }
}
