<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Dòng đơn hàng: snapshot tên / mã / đơn giá (đã gồm VAT) / VAT % lúc đặt. */
class OrderItem extends Model
{
    protected $fillable = ['order_id', 'product_id', 'product_name', 'sku', 'qty', 'unit_price', 'vat_rate', 'line_amount', 'vat_amount'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'integer',
            'vat_rate' => 'decimal:2',
            'line_amount' => 'integer',
            'vat_amount' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** Tiền VAT trong một khoản đã gồm VAT (làm tròn xuống phần trước VAT): x − floor(x / (1 + VAT%)). */
    public static function vatOf(int $amountWithVat, string|float $vatRate): int
    {
        return $amountWithVat - intdiv($amountWithVat * 10000, 10000 + (int) round((float) $vatRate * 100));
    }
}
