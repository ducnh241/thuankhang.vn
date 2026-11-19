<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dòng đơn hàng: snapshot tên / tên biến thể / mã / đơn giá lúc đặt. Cột vat_rate / vat_amount cũ không dùng (luôn 0). */
class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'variant_id', 'product_name', 'variant_name', 'sku', 'qty', 'unit_price', 'line_amount'];

    protected $attributes = ['vat_rate' => 0, 'vat_amount' => 0]; // cột cũ, không dùng

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'integer',
            'line_amount' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    /** Tên hiển thị: "Sản phẩm — Biến thể". */
    public function displayName(): string
    {
        return $this->product_name.($this->variant_name ? ' — '.$this->variant_name : '');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
