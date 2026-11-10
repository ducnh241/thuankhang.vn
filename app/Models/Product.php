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
 * Sản phẩm của cửa hàng (A6). price = giá bán ĐÃ GỒM VAT (đồng); vat_rate = VAT % riêng từng sản phẩm. Ảnh: bảng
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

    protected $fillable = ['category_id', 'name', 'slug', 'sku', 'brand', 'origin', 'price', 'vat_rate', 'stock', 'short_description', 'description', 'status', 'is_featured', 'sort_order'];

    protected $attributes = [
        'status' => true,
        'is_featured' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'integer',
            'vat_rate' => 'decimal:2',
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
        $query->with('cover')
            ->withSum(['orderItems as sold_qty' => fn ($q) => $q->whereHas('order', fn ($o) => $o->whereNotNull('paid_at'))], 'qty')
            ->withAvg(['reviews as rating_avg' => fn ($q) => $q->where('is_visible', true)], 'rating')
            ->withCount(['reviews as rating_count' => fn ($q) => $q->where('is_visible', true)]);
    }

    /** Có theo dõi tồn kho không (stock = NULL: không giới hạn số lượng bán). */
    public function tracksStock(): bool
    {
        return $this->stock !== null;
    }

    public function isOutOfStock(): bool
    {
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

    /** Giá trước VAT (đồng, làm tròn xuống): giá đã gồm VAT / (1 + VAT%). Hoa hồng tính trên giá này (đặc tả 8.3). */
    public function priceBeforeVat(): int
    {
        return intdiv($this->price * 10000, 10000 + (int) round((float) $this->vat_rate * 100));
    }

    public function url(): string
    {
        return '/'.self::PATH.'/'.$this->slug;
    }
}
