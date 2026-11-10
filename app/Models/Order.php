<?php

namespace App\Models;

use App\Support\AffiliateSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Đơn hàng. Luồng trạng thái (xem claude/CLAUDE.md — "Trạng thái đơn hàng"):
 *   new (hiện QR) → awaiting_confirmation (khách bấm "Tôi đã chuyển khoản") → paid (Kế toán xác nhận / webhook Step 6)
 *   → processing (đang giao / chờ nhận tại VP) → completed;  new | awaiting_confirmation → cancelled (khách / admin / quá hạn).
 * "Đã thanh toán" là mốc kích hoạt, lên cấp, hoa hồng (Step 7–8) và không quay lại được (Phase 1 không hoàn tiền).
 */
class Order extends Model
{
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_AWAITING = 'awaiting_confirmation';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_NEW => 'Đơn mới',
        self::STATUS_AWAITING => 'Chờ xác nhận thanh toán',
        self::STATUS_PAID => 'Đã thanh toán',
        self::STATUS_PROCESSING => 'Đang giao',
        self::STATUS_COMPLETED => 'Hoàn tất',
        self::STATUS_CANCELLED => 'Đã hủy',
    ];

    /** Màu Tag (Ant Design) theo trạng thái. */
    public const STATUS_COLORS = [
        self::STATUS_NEW => 'orange', self::STATUS_AWAITING => 'gold', self::STATUS_PAID => 'blue',
        self::STATUS_PROCESSING => 'geekblue', self::STATUS_COMPLETED => 'green', self::STATUS_CANCELLED => 'red',
    ];

    public const CANCEL_CUSTOMER = 'customer';

    public const CANCEL_ADMIN = 'admin';

    public const CANCEL_EXPIRED = 'expired';

    public const CANCEL_SOURCES = [self::CANCEL_CUSTOMER => 'Khách tự hủy', self::CANCEL_ADMIN => 'Quản trị hủy', self::CANCEL_EXPIRED => 'Quá hạn thanh toán'];

    public const DELIVERY_SHIP = 'ship';

    public const DELIVERY_PICKUP = 'pickup';

    public const DELIVERIES = [self::DELIVERY_SHIP => 'Giao tận nơi', self::DELIVERY_PICKUP => 'Nhận tại VP tỉnh'];

    /** Ký tự mã đơn: chữ in hoa + số, bỏ 0 / O / 1 / I / L cho khỏi gõ nhầm khi chuyển khoản. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = [
        'code', 'member_id', 'status', 'goods_amount', 'vat_amount', 'shipping_fee', 'total_amount', 'delivery_type',
        'pickup_point_id', 'recipient_name', 'recipient_phone', 'province', 'address', 'note', 'expires_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_NEW,
        'shipping_fee' => 0,
    ];

    protected function casts(): array
    {
        return [
            'goods_amount' => 'integer',
            'vat_amount' => 'integer',
            'shipping_fee' => 'integer',
            'total_amount' => 'integer',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'processing_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'confirm_requested_at' => 'datetime',
            'paid_amount' => 'integer',
            'stock_reserved' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(PickupPoint::class)->withTrashed();
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** Chuyển tay ở admin (order/status) sau khi đã thanh toán: Đã thanh toán → Đang giao → Hoàn tất. */
    public const TRANSITIONS = [
        self::STATUS_PAID => self::STATUS_PROCESSING,
        self::STATUS_PROCESSING => self::STATUS_COMPLETED,
    ];

    /** Lọc theo trạng thái hiển thị (đơn mới quá hạn mà lệnh tự hủy chưa chạy tới vẫn tính là "Đã hủy"). */
    public function scopeWhereDisplayStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::STATUS_NEW => $query->where('status', self::STATUS_NEW)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            self::STATUS_CANCELLED => $query->where(fn ($q) => $q->where('status', self::STATUS_CANCELLED)
                ->orWhere(fn ($q) => $q->where('status', self::STATUS_NEW)->where('expires_at', '<=', now()))),
            default => $query->where('status', $status),
        };
    }

    /** Đơn mới đã quá hạn thanh toán (orders:expire sẽ chuyển hẳn sang "Đã hủy"). Đơn chờ xác nhận không bị quá hạn. */
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
        return self::labelFor($this->displayStatus(), $this->delivery_type);
    }

    /** Nhãn trạng thái; "Đang giao" đổi thành "Chờ nhận tại VP" với đơn nhận tại VP tỉnh. */
    public static function labelFor(string $status, ?string $delivery = null): string
    {
        return $status === self::STATUS_PROCESSING && $delivery === self::DELIVERY_PICKUP ? 'Chờ nhận tại VP' : (self::STATUSES[$status] ?? $status);
    }

    /** Lý do hủy để hiển thị (gồm đơn quá hạn chưa được lệnh tự hủy xử lý). */
    public function cancelReasonText(): ?string
    {
        return $this->isOverdue() ? self::CANCEL_SOURCES[self::CANCEL_EXPIRED] : $this->cancel_reason;
    }

    public function nextStatus(): ?string
    {
        return self::TRANSITIONS[$this->status] ?? null;
    }

    /** Khách: bấm "Tôi đã chuyển khoản" / tự hủy — chỉ khi đơn mới còn hạn. */
    public function isAwaitingPayment(): bool
    {
        return $this->status === self::STATUS_NEW && ! $this->isOverdue();
    }

    /** Admin hủy: đơn chưa thanh toán (mới / chờ xác nhận). */
    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_AWAITING], true);
    }

    /** Xác nhận đã thanh toán: đơn chưa thanh toán, hoặc đã hủy mà tiền về muộn (khôi phục — bắt buộc ghi chú). */
    public function canConfirmPayment(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_AWAITING, self::STATUS_CANCELLED], true);
    }

    /**
     * Giữ hàng: trừ tồn kho các sản phẩm có theo dõi tồn (gọi lúc đặt đơn, và khi khôi phục đơn đã hủy thành đã thanh toán).
     * Gọi trong transaction đã khóa đơn. Không làm gì nếu đơn đang giữ hàng.
     */
    public function takeStock(): void
    {
        if ($this->stock_reserved) {
            return;
        }
        foreach ($this->items as $item) {
            Product::withTrashed()->whereKey($item->product_id)->whereNotNull('stock')->decrement('stock', $item->qty);
        }
        $this->forceFill(['stock_reserved' => true])->save();
    }

    /** Trả hàng về kho khi đơn bị hủy (khách / admin / quá hạn). Không làm gì nếu đơn không giữ hàng. */
    public function releaseStock(): void
    {
        if (! $this->stock_reserved) {
            return;
        }
        foreach ($this->items as $item) {
            Product::withTrashed()->whereKey($item->product_id)->whereNotNull('stock')->increment('stock', $item->qty);
        }
        $this->forceFill(['stock_reserved' => false])->save();
    }

    public function isPickup(): bool
    {
        return $this->delivery_type === self::DELIVERY_PICKUP;
    }

    /** Địa chỉ giao (giao tận nơi) dạng một dòng. */
    public function shippingAddress(): ?string
    {
        if ($this->isPickup()) {
            return null;
        }

        return trim($this->address.', '.(PickupPoint::provinces()[$this->province] ?? $this->province), ', ');
    }

    /** Sinh mã đơn chưa dùng: tiền tố (Tham số chung, mặc định TK) + 8 ký tự. */
    public static function generateCode(): string
    {
        $prefix = (string) (AffiliateSettings::get('order_code_prefix') ?: 'TK');
        do {
            $code = $prefix;
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
