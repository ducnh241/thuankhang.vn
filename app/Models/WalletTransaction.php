<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bút toán ví của thành viên: amount có dấu (+ cộng, − trừ), balance_after = số dư CỦA VÍ ĐÓ ngay sau bút toán. Chỉ ghi
 * thêm; số dư mỗi ví = tổng amount của ví (Member::walletBalance($wallet)). Hai ví: cash (rút tiền — 70% hoa hồng) và
 * reconsume (tái tiêu dùng — 20%); 10% thuế TNCN khấu trừ không vào ví (ghi ở commission_ledger.tax_amount).
 * Loại: commission (hoa hồng F1 / F2), split (chia lại hoa hồng cũ đã cộng 100% trước 19/11/2026); rút tiền làm ở Step 9.
 */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_COMMISSION = 'commission';

    public const TYPE_SPLIT = 'split';

    public const TYPES = [self::TYPE_COMMISSION => 'Hoa hồng', self::TYPE_SPLIT => 'Chia lại hoa hồng cũ'];

    public const WALLET_CASH = 'cash';

    public const WALLET_RECONSUME = 'reconsume';

    public const WALLETS = [self::WALLET_CASH => 'Ví rút tiền', self::WALLET_RECONSUME => 'Ví tái tiêu dùng'];

    protected $fillable = ['member_id', 'wallet', 'type', 'amount', 'balance_after', 'commission_id', 'note', 'created_at'];

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
