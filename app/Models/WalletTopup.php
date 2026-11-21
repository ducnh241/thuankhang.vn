<?php

namespace App\Models;

use App\Support\AffiliateSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Lệnh nạp tiền vào ví tái tiêu dùng — luồng giống đơn hàng chuyển khoản:
 *   new (hiện QR công ty, số tiền + nội dung = mã lệnh, đếm hạn payment_expiry_hours)
 *   → awaiting_confirmation (thành viên tải ảnh bill + bấm "Tôi đã chuyển khoản", dừng đếm hạn)
 *   → paid (kế toán đối chiếu sao kê, nhập mã giao dịch + số tiền thực nhận → cộng ĐÚNG SỐ THỰC NHẬN vào ví tái tiêu dùng)
 *   new | awaiting_confirmation → cancelled (thành viên tự hủy lệnh mới / kế toán hủy / quá hạn); awaiting → new (chưa nhận
 *   được tiền, chuyển thiếu → khách chuyển bù); cancelled → paid (tiền về muộn, bắt buộc ghi chú).
 * Tiền nạp KHÔNG tính doanh số / hoa hồng — chỉ khi dùng ví mua hàng mới tính (đơn thường).
 */
class WalletTopup extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_AWAITING = 'awaiting_confirmation';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_NEW => 'Chờ chuyển khoản',
        self::STATUS_AWAITING => 'Chờ xác nhận',
        self::STATUS_PAID => 'Đã cộng ví',
        self::STATUS_CANCELLED => 'Đã hủy',
    ];

    public const STATUS_COLORS = [
        self::STATUS_NEW => 'orange', self::STATUS_AWAITING => 'gold', self::STATUS_PAID => 'green', self::STATUS_CANCELLED => 'red',
    ];

    public const CANCEL_CUSTOMER = 'customer';

    public const CANCEL_ADMIN = 'admin';

    public const CANCEL_EXPIRED = 'expired';

    public const CANCEL_SOURCES = [self::CANCEL_CUSTOMER => 'Thành viên tự hủy', self::CANCEL_ADMIN => 'Kế toán hủy', self::CANCEL_EXPIRED => 'Quá hạn chuyển khoản'];

    /** Số tiền nạp tối thiểu / tối đa một lệnh (VNĐ). */
    public const MIN_AMOUNT = 100000;

    public const MAX_AMOUNT = 1000000000;

    /** Số lệnh chưa xong (chờ chuyển khoản còn hạn / chờ xác nhận) tối đa của một thành viên. */
    public const MAX_OPEN = 3;

    /** Thư mục (disk riêng tư) lưu ảnh bill: topup-bills/{id lệnh}/… */
    public const BILL_DIRECTORY = 'topup-bills';

    public const CODE_PREFIX = 'NAP';

    /** Cùng bảng ký tự với mã đơn: bỏ 0 / O / 1 / I / L cho khỏi gõ nhầm khi chuyển khoản. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = ['code', 'member_id', 'amount', 'expires_at'];

    protected $attributes = ['status' => self::STATUS_NEW];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'expires_at' => 'datetime',
            'confirm_requested_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** Bút toán cộng ví của lệnh (có khi đã xác nhận). */
    public function walletTransaction(): HasOne
    {
        return $this->hasOne(WalletTransaction::class, 'topup_id');
    }

    /** Lệnh mới đã quá hạn (topups:expire sẽ chuyển hẳn sang "Đã hủy"). Lệnh chờ xác nhận không bị quá hạn. */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_NEW && $this->expires_at?->isPast();
    }

    public function displayStatus(): string
    {
        return $this->isOverdue() ? self::STATUS_CANCELLED : $this->status;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->displayStatus()] ?? $this->status;
    }

    public function cancelReasonText(): ?string
    {
        return $this->isOverdue() ? self::CANCEL_SOURCES[self::CANCEL_EXPIRED] : $this->cancel_reason;
    }

    /** Thành viên: báo đã chuyển khoản / tự hủy — chỉ khi lệnh mới còn hạn. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === self::STATUS_NEW && ! $this->isOverdue();
    }

    /** Kế toán xác nhận: lệnh chưa xong, hoặc đã hủy mà tiền về muộn (bắt buộc ghi chú). */
    public function canConfirmPayment(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_AWAITING, self::STATUS_CANCELLED], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_AWAITING], true);
    }

    /** Lệnh chưa xong còn hiệu lực: mới (chưa quá hạn) hoặc đã báo chuyển khoản, chờ kế toán. */
    public function scopeOpen(Builder $query): void
    {
        $query->where(fn (Builder $q) => $q->where('status', self::STATUS_AWAITING)
            ->orWhere(fn (Builder $n) => $n->where('status', self::STATUS_NEW)->where('expires_at', '>', now())));
    }

    public static function openLimitReached(int $memberId): bool
    {
        return self::where('member_id', $memberId)->open()->count() >= self::MAX_OPEN;
    }

    /** Lọc theo trạng thái hiển thị (lệnh mới quá hạn mà lệnh tự hủy chưa chạy tới vẫn tính là "Đã hủy"). */
    public function scopeWhereDisplayStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::STATUS_NEW => $query->where('status', self::STATUS_NEW)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            self::STATUS_CANCELLED => $query->where(fn ($q) => $q->where('status', self::STATUS_CANCELLED)
                ->orWhere(fn ($q) => $q->where('status', self::STATUS_NEW)->where('expires_at', '<=', now()))),
            default => $query->where('status', $status),
        };
    }

    public function hasBill(): bool
    {
        return $this->payment_bill !== null;
    }

    /** Ảnh bill chuyển khoản: nội dung đã giải mã + kiểu MIME; null nếu chưa có / file hỏng. */
    public function billImage(): ?array
    {
        return MemberKyc::readImage($this->payment_bill);
    }

    /** Tạo lệnh mới cho thành viên (gọi trong transaction đã khóa dòng thành viên). */
    public static function start(Member $member, int $amount): self
    {
        return self::create([
            'code' => self::generateCode(),
            'member_id' => $member->id,
            'amount' => $amount,
            'expires_at' => now()->addHours((int) AffiliateSettings::get('payment_expiry_hours')),
        ]);
    }

    /** Sinh mã lệnh chưa dùng: NAP + 8 ký tự (không trùng mã đơn hàng nhờ tiền tố riêng). */
    public static function generateCode(): string
    {
        do {
            $code = self::CODE_PREFIX;
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::where('code', $code)->exists() || Order::where('code', $code)->exists());

        return $code;
    }
}
