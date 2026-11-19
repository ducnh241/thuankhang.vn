<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/**
 * Một dòng sổ hoa hồng (bảng commission_ledger): hoa hồng tầng F1 hoặc F2 của một đơn đã thanh toán cho một người hưởng.
 * Lưu snapshot lúc tính (cấp, tỉ lệ, phí doanh nghiệp, cơ sở). Chỉ ghi thêm — do App\Services\CommissionEngine tạo.
 */
class Commission extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_CREDITED = 'credited';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUSES = [self::STATUS_CREDITED => 'Đã cộng ví', self::STATUS_SKIPPED => 'Không được nhận'];

    public const TIERS = ['F1' => 'F1 (trực tiếp)', 'F2' => 'F2 (gián tiếp)'];

    protected $table = 'commission_ledger';

    protected $fillable = [
        'order_id', 'buyer_id', 'beneficiary_id', 'tier', 'level_id', 'level_name', 'rate_percent', 'goods_amount',
        'fee_percent', 'base_amount', 'amount', 'reconsume_percent', 'tax_percent', 'cash_amount', 'reconsume_amount',
        'tax_amount', 'status', 'skip_reason', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'rate_percent' => 'decimal:2', 'fee_percent' => 'decimal:2', 'reconsume_percent' => 'decimal:2', 'tax_percent' => 'decimal:2',
            'goods_amount' => 'integer', 'base_amount' => 'integer', 'amount' => 'integer',
            'cash_amount' => 'integer', 'reconsume_amount' => 'integer', 'tax_amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Sổ hoa hồng chỉ ghi thêm, không được sửa.'));
        static::deleting(fn () => throw new LogicException('Sổ hoa hồng chỉ ghi thêm, không được xóa.'));
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'buyer_id');
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'beneficiary_id');
    }

    /** Bút toán cộng ví có thể rút của dòng này (chỉ dòng đã cộng ví mới có). */
    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class)->where('wallet', WalletTransaction::WALLET_CASH)
            ->where('type', WalletTransaction::TYPE_COMMISSION);
    }

    /** Mọi bút toán ví của dòng này (cả hai ví + bút toán chia lại dữ liệu cũ). */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /** "70%" — tỉ lệ vào ví có thể rút = 100% − tái tiêu dùng − thuế TNCN. */
    public function cashPercent(): string
    {
        return number_format(100 - (float) $this->reconsume_percent - (float) $this->tax_percent, 2, '.', '');
    }

    public function scopeCredited(Builder $query): void
    {
        $query->where('status', self::STATUS_CREDITED);
    }

    public function isCredited(): bool
    {
        return $this->status === self::STATUS_CREDITED;
    }

    /** "10%" / "7,5%" — bỏ số 0 thừa. */
    public static function percentLabel(string|float|null $percent): string
    {
        return str_replace('.', ',', rtrim(rtrim(number_format((float) $percent, 2, '.', ''), '0'), '.')).'%';
    }
}
