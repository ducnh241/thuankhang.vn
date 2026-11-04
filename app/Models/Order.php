<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Đơn hàng (Step 5). Tạo ở checkout với trạng thái "Chờ thanh toán" và hạn thanh toán; webhook ngân hàng (Step 6) chuyển
 * "Đã thanh toán" và kích hoạt engine lên cấp / hoa hồng (Step 7–8). Vận hành chuyển "Đang xử lý" → "Hoàn tất".
 */
class Order extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_PENDING => 'Chờ thanh toán',
        self::STATUS_PAID => 'Đã thanh toán',
        self::STATUS_PROCESSING => 'Đang xử lý',
        self::STATUS_COMPLETED => 'Hoàn tất',
        self::STATUS_EXPIRED => 'Hết hạn',
        self::STATUS_CANCELLED => 'Đã hủy',
    ];

    /** Màu Tag (Ant Design) theo trạng thái. */
    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'orange', self::STATUS_PAID => 'blue', self::STATUS_PROCESSING => 'geekblue',
        self::STATUS_COMPLETED => 'green', self::STATUS_EXPIRED => 'default', self::STATUS_CANCELLED => 'red',
    ];

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
        'status' => self::STATUS_PENDING,
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

    public function pickupPoint(): BelongsTo
    {
        return $this->belongsTo(PickupPoint::class)->withTrashed();
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /**
     * Chuyển trạng thái tay ở admin (order/status): Đã thanh toán → Đang xử lý → Hoàn tất. "Đã thanh toán" chỉ do thanh toán
     * (webhook / gán tay giao dịch — Step 6) đặt, không chọn tay ở đây.
     */
    public const TRANSITIONS = [
        self::STATUS_PAID => self::STATUS_PROCESSING,
        self::STATUS_PROCESSING => self::STATUS_COMPLETED,
    ];

    /** Lọc theo trạng thái hiển thị (đơn chờ thanh toán quá hạn tính là "Hết hạn"). */
    public function scopeWhereDisplayStatus(Builder $query, string $status): void
    {
        match ($status) {
            self::STATUS_PENDING => $query->where('status', self::STATUS_PENDING)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())),
            self::STATUS_EXPIRED => $query->where(fn ($q) => $q->where('status', self::STATUS_EXPIRED)
                ->orWhere(fn ($q) => $q->where('status', self::STATUS_PENDING)->where('expires_at', '<=', now()))),
            default => $query->where('status', $status),
        };
    }

    public function nextStatus(): ?string
    {
        return self::TRANSITIONS[$this->status] ?? null;
    }

    /** Hủy được khi chưa thanh toán (kể cả đã quá hạn mà job chưa chuyển). */
    public function isCancellable(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /** Chờ thanh toán nhưng đã quá hạn (job Step 6 sẽ chuyển hẳn sang "Hết hạn"). */
    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->expires_at?->isPast();
    }

    /** Trạng thái để hiển thị: đơn chờ thanh toán đã quá hạn coi như "Hết hạn". */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? self::STATUS_EXPIRED : $this->status;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->displayStatus()] ?? $this->status;
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

    /** Sinh mã đơn chưa dùng: AF + 8 ký tự. */
    public static function generateCode(): string
    {
        do {
            $code = 'AF';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::where('code', $code)->exists());

        return $code;
    }
}
