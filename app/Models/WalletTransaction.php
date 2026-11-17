<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bút toán ví của thành viên: amount có dấu (+ cộng, − trừ), balance_after = số dư ngay sau bút toán. Chỉ ghi thêm; số dư
 * ví = tổng amount (Member::walletBalance()). Hiện chỉ có loại "commission" (hoa hồng F1 / F2); rút tiền làm ở Step 9.
 */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_COMMISSION = 'commission';

    public const TYPES = [self::TYPE_COMMISSION => 'Hoa hồng'];

    protected $fillable = ['member_id', 'type', 'amount', 'balance_after', 'commission_id', 'note', 'created_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'balance_after' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Giao dịch ví chỉ ghi thêm, không được sửa.'));
        static::deleting(fn () => throw new LogicException('Giao dịch ví chỉ ghi thêm, không được xóa.'));
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }
}
