<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Bút toán ví của thành viên: amount có dấu (+ cộng, − trừ), balance_after = số dư CỦA VÍ ĐÓ ngay sau bút toán. Chỉ ghi
 * thêm; số dư mỗi ví = tổng amount của ví (Member::walletBalance($wallet)). Hai ví: cash (rút tiền — 70% hoa hồng) và
 * reconsume (tái tiêu dùng — 20%); 10% thuế TNCN khấu trừ không vào ví (ghi ở commission_ledger.tax_amount).
 * Loại: commission (hoa hồng F1 / F2), split (chia lại hoa hồng cũ đã cộng 100% trước 19/11/2026); từ 21/11/2026 ví tái
 * tiêu dùng dùng được (App\Services\Wallet): purchase (trừ ví khi mua hàng — order_id), transfer_out / transfer_in (chuyển ví
 * cho thành viên khác — counterpart_id), topup (nạp tiền đã được kế toán xác nhận — topup_id), convert (chuyển từ ví rút
 * tiền sang ví tái tiêu dùng: một bút toán trừ ví rút + một bút toán cộng ví tái tiêu dùng). Rút tiền làm ở Step 9.
 */
class WalletTransaction extends Model
{
    public const UPDATED_AT = null;

    public const TYPE_COMMISSION = 'commission';

    public const TYPE_SPLIT = 'split';

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_TRANSFER_OUT = 'transfer_out';

    public const TYPE_TRANSFER_IN = 'transfer_in';

    public const TYPE_TOPUP = 'topup';

    public const TYPE_CONVERT = 'convert';

    public const TYPE_PROFIT_SHARE = 'profit_share';

    public const TYPES = [
        self::TYPE_COMMISSION => 'Hoa hồng', self::TYPE_SPLIT => 'Chia lại hoa hồng cũ',
        self::TYPE_PURCHASE => 'Thanh toán đơn hàng', self::TYPE_TRANSFER_OUT => 'Chuyển tiền đi',
        self::TYPE_TRANSFER_IN => 'Nhận tiền chuyển', self::TYPE_TOPUP => 'Nạp tiền', self::TYPE_CONVERT => 'Chuyển giữa hai ví',
        self::TYPE_PROFIT_SHARE => 'Đồng chia',
    ];

    public const WALLET_CASH = 'cash';

    public const WALLET_RECONSUME = 'reconsume';

    public const WALLETS = [self::WALLET_CASH => 'Ví rút tiền', self::WALLET_RECONSUME => 'Ví tái tiêu dùng'];

    protected $fillable = [
        'member_id', 'wallet', 'type', 'amount', 'balance_after', 'commission_id', 'order_id', 'topup_id', 'counterpart_id', 'profit_share_line_id', 'note', 'created_at',
    ];

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

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function topup(): BelongsTo
    {
        return $this->belongsTo(WalletTopup::class, 'topup_id');
    }

    /** Người nhận (chuyển đi) / người chuyển (nhận về). */
    public function counterpart(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'counterpart_id');
    }

    /** Nhãn loại để hiện cho thành viên / quản trị; "chuyển giữa hai ví" ghi rõ chiều theo ví của bút toán. */
    public function typeLabel(): string
    {
        if ($this->type === self::TYPE_CONVERT) {
            return $this->wallet === self::WALLET_CASH ? 'Chuyển sang ví tái tiêu dùng' : 'Chuyển từ ví rút tiền';
        }

        return self::TYPES[$this->type] ?? $this->type;
    }
}
