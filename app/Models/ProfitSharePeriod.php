<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Ảnh chụp bảng đồng chia của một tháng tại lúc kế toán duyệt (đã cộng ví). Chỉ ghi thêm. */
class ProfitSharePeriod extends Model
{
    protected $fillable = [
        'period', 'fee_percent', 'share_percent', 'slots', 'order_total', 'pool_total', 'distributed_total', 'paid_count',
        'fingerprint', 'approved_by', 'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime', 'slots' => 'integer', 'order_total' => 'integer', 'pool_total' => 'integer',
            'distributed_total' => 'integer', 'paid_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Bảng đồng chia đã duyệt không được sửa.'));
        static::deleting(fn () => throw new LogicException('Bảng đồng chia đã duyệt không được xóa.'));
    }

    public function groups(): HasMany
    {
        return $this->hasMany(ProfitShareGroup::class, 'period_id')->orderBy('min_amount');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
