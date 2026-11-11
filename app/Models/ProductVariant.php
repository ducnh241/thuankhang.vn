<?php

namespace App\Models;

use App\Support\Cart;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Biến thể sản phẩm (VD một màu son). Giá NULL = giá sản phẩm; tồn NULL = không theo dõi. */
class ProductVariant extends Model
{
    protected $fillable = ['product_id', 'name', 'sku', 'price', 'stock', 'image_id', 'sort_order', 'active'];

    protected $attributes = ['active' => true, 'sort_order' => 0];

    protected function casts(): array
    {
        return ['price' => 'integer', 'stock' => 'integer', 'sort_order' => 'integer', 'active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(ProductImage::class, 'image_id');
    }

    /** Giá bán (đã gồm VAT): giá riêng hoặc giá sản phẩm. */
    public function effectivePrice(?Product $product = null): int
    {
        return $this->price ?? ($product ?? $this->product)->price;
    }

    public function tracksStock(): bool
    {
        return $this->stock !== null;
    }

    public function isOutOfStock(): bool
    {
        return $this->tracksStock() && $this->stock <= 0;
    }

    public function maxOrderQty(): int
    {
        return $this->tracksStock() ? max(0, min(Cart::MAX_QTY, $this->stock)) : Cart::MAX_QTY;
    }
}
