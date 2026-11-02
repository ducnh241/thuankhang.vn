<?php

namespace App\Models;

use App\Support\Renewal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Project extends Model
{
    use HasFactory, SoftDeletes;

    public const STATUSES = [
        'lead' => 'Đang đàm phán',
        'in_progress' => 'Đang triển khai',
        'delivered' => 'Đã bàn giao',
        'maintenance' => 'Đang bảo trì',
        'closed' => 'Đã đóng dự án',
        'cancelled' => 'Đã hủy gia hạn',
    ];

    /** Màu tag trạng thái trong giao diện admin (như src/js/projects.js của template). */
    public const STATUS_TAGS = [
        'lead' => 'ant-tag-blue',
        'in_progress' => 'ant-tag-gold',
        'delivered' => 'ant-tag-green',
        'maintenance' => 'ant-tag-cyan',
        'closed' => '',
        'cancelled' => 'ant-tag-red',
    ];

    /**
     * Trạng thái không còn gia hạn: không nhắc gia hạn, không đếm quá hạn, không vào dự kiến dòng tiền gia hạn.
     * (Dự án đã đóng thường để lại next_renewal_date cũ từ nhiều năm trước — so theo ngày–tháng sẽ bị nhắc mỗi năm.)
     */
    public const NO_RENEWAL_STATUSES = ['cancelled', 'closed'];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Chưa thanh toán',
        'partial' => 'Thanh toán một phần',
        'paid' => 'Đã thanh toán',
    ];

    protected $fillable = [
        'customer_id', 'cat_id', 'name', 'slug', 'primary_domain', 'is_promo_active', 'domain_note',
        'tech_stack', 'description', 'status', 'start_date', 'deliver_date', 'next_renewal_date',
        'price', 'currency', 'renewal_hosting_amount', 'renewal_domain_amount', 'payment_status', 'note',
    ];

    protected $attributes = [
        'status' => 'in_progress',
        'payment_status' => 'unpaid',
        'currency' => 'VND',
        'price' => 0,
        'renewal_hosting_amount' => 0,
        'renewal_domain_amount' => 0,
        'is_promo_active' => false,
    ];

    protected function casts(): array
    {
        return [
            // Số đếm từ withCount: một số driver MySQL trả về chuỗi ("0") → ép số nguyên để so sánh === 0 không sai
            'payments_count' => 'integer',
            'start_date' => 'date',
            'deliver_date' => 'date',
            'next_renewal_date' => 'date',
            'price' => 'decimal:2',
            'renewal_hosting_amount' => 'decimal:2',
            'renewal_domain_amount' => 'decimal:2',
            'is_promo_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if (empty($project->slug)) {
                $project->slug = Str::slug($project->name).'-'.Str::lower(Str::random(6));
            }

            // site_key luôn bám theo domain chính, không sửa tay
            $project->site_key = self::normalizeSiteKey($project->primary_domain);
        });
    }

    /**
     * Chuẩn hoá domain thành khoá duy nhất: "https://WWW.Shop.com:8080/a?x=1" → "shop.com".
     */
    public static function normalizeSiteKey(?string $domain): ?string
    {
        $domain = trim((string) $domain);
        if ($domain === '') {
            return null;
        }

        $domain = preg_replace('#^[a-z][a-z0-9+.\-]*://#i', '', $domain);
        $domain = preg_split('#[/?\#:]#', $domain)[0];
        $domain = preg_replace('#^www\.#i', '', strtolower($domain));
        $domain = trim($domain, '.');

        return $domain !== '' ? $domain : null;
    }

    /**
     * Link mở website, chỉ khi domain là tên miền thật (dữ liệu cũ có giá trị như "CMS VR Tour").
     */
    public function domainUrl(): ?string
    {
        return self::hostUrl($this->site_key ?? self::normalizeSiteKey($this->primary_domain));
    }

    /** https://host nếu host là tên miền thật, null nếu không (dùng chung cho domain chính và domain alias). */
    public static function hostUrl(?string $host): ?string
    {
        return $host && preg_match('/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/i', $host) ? 'https://'.$host : null;
    }

    /** Domain alias (cùng website, chung source code và database), theo thứ tự nhập. */
    public function domains(): HasMany
    {
        return $this->hasMany(ProjectDomain::class)->orderBy('position')->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'cat_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function hostings(): BelongsToMany
    {
        return $this->belongsToMany(Hosting::class)->withPivot('note');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ProjectAuditLog::class);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function paymentStatusLabel(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? $this->payment_status;
    }

    /** Tổng đã thu (gồm cả tiền gia hạn, phát sinh đã thu). */
    public function paidAmount(): float
    {
        return (float) $this->payments()->received()->sum('amount');
    }

    /** Thu mới đã thu (không tính gia hạn, phát sinh) — dùng để so với giá hợp đồng. */
    public function paidNewAmount(): float
    {
        return (float) $this->payments()->received()->contract()->sum('amount');
    }

    /** Phát sinh chưa thu. */
    public function unpaidAmount(): float
    {
        return (float) $this->payments()->unpaid()->sum('amount');
    }

    /**
     * Nạp số liệu để tính công nợ (debtAmount): paid_new = thu mới đã thu, unpaid_extra = phát sinh chưa thu.
     */
    public function scopeWithDebt(Builder $query): void
    {
        $query->withSum(['payments as paid_new' => fn ($q) => $q->received()->contract()], 'amount')
            ->withSum(['payments as unpaid_extra' => fn ($q) => $q->unpaid()], 'amount');
    }

    /**
     * Công nợ của dự án = nợ hợp đồng (giá − thu mới đã thu, không âm) + phát sinh chưa thu. Cần scope withDebt().
     * Cùng công thức với hasDebt(), debtSummary() và báo cáo doanh thu.
     */
    public function debtAmount(): float
    {
        return round(max(0, (float) $this->price - (float) $this->paid_new) + (float) $this->unpaid_extra, 2);
    }

    /** Dự án còn công nợ (bộ lọc "Còn công nợ"); dự án đã hủy gia hạn không tính nợ. */
    public function scopeHasDebt(Builder $query): void
    {
        $paidNew = 'SELECT COALESCE(SUM(payments.amount), 0) FROM payments WHERE payments.project_id = projects.id'
            ." AND payments.status = 'paid' AND (payments.type = 'new' OR payments.type IS NULL)";
        $query->where('projects.status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereRaw("projects.price - ({$paidNew}) > 0.01")
                ->orWhereHas('payments', fn ($p) => $p->unpaid()));
    }

    public function stillRenews(): bool
    {
        return ! in_array($this->status, self::NO_RENEWAL_STATUSES, true);
    }

    /**
     * Khách tự gia hạn: dự án chỉ chạy trên hosting của khách — khách tự gia hạn, không qua mình.
     * Dự án chưa gắn hosting vẫn coi là mình lo (như dự kiến dòng tiền của nhánh main). Cần nạp sẵn quan hệ hostings.
     */
    public function selfRenews(): bool
    {
        return $this->hostings->isNotEmpty() && ! $this->hostings->contains('ownership', 'mine');
    }

    /**
     * Ngày gia hạn dùng cho nhắc gia hạn / dòng tiền: next_renewal_date; chưa có thì theo ngày bàn giao — kỳ đầu tiên
     * là 1 năm sau bàn giao (cùng ngày–tháng). Null nếu không có cả hai.
     */
    public function renewalDate(): ?CarbonImmutable
    {
        if ($this->next_renewal_date) {
            return CarbonImmutable::make($this->next_renewal_date)->startOfDay();
        }

        return $this->deliver_date ? Renewal::inYear($this->deliver_date, $this->deliver_date->year + 1) : null;
    }

    /** Ngày gia hạn đang tính theo ngày bàn giao (dự án chưa có next_renewal_date). */
    public function renewalDateFromDelivery(): bool
    {
        return ! $this->next_renewal_date && $this->deliver_date;
    }

    /** Có ngày để xếp vào kỳ gia hạn: next_renewal_date hoặc ngày bàn giao. */
    public function scopeHasRenewalDate(Builder $query): void
    {
        $query->where(fn ($q) => $q->whereNotNull('next_renewal_date')->orWhereNotNull('deliver_date'));
    }

    public function scopeStillRenewing(Builder $query): void
    {
        $query->whereNotIn('status', self::NO_RENEWAL_STATUSES);
    }

    /**
     * Kỳ gia hạn cần chú ý tính tới hôm nay, cùng cách tính với dashboard / trang Nhắc gia hạn:
     *   upcoming: kỳ trong $days ngày tới chưa gia hạn
     *   overdue:  kỳ đã qua trong năm nay (từ 01/01) chưa gia hạn
     * Mỗi mục là ['due' => ngày đến hạn, 'days' => số ngày còn lại (âm = đã quá)] hoặc null. Dự án đã đóng / hủy, khách
     * tự gia hạn (cần nạp sẵn hostings) hoặc chưa có ngày gia hạn thì không có kỳ nào.
     */
    public function renewalAlerts(CarbonImmutable $today, int $days = Renewal::SOON_DAYS): array
    {
        $alerts = ['upcoming' => null, 'overdue' => null];
        $date = $this->renewalDate();
        if (! $date || ! $this->stillRenews() || $this->selfRenews()) {
            return $alerts;
        }

        $due = Renewal::occurrence($date, $today, $today->addDays($days), $today);
        if ($due && ! Renewal::isRenewed($date, $due)) {
            $alerts['upcoming'] = ['due' => $due, 'days' => Renewal::daysLeft($due, $today)];
        }
        if ($today->month > 1 || $today->day > 1) {
            $past = Renewal::occurrence($date, $today->startOfYear(), $today->subDay(), $today);
            if ($past && ! Renewal::isRenewed($date, $past)) {
                $alerts['overdue'] = ['due' => $past, 'days' => Renewal::daysLeft($past, $today)];
            }
        }

        return $alerts;
    }

    /**
     * Kỳ gia hạn kế tiếp để hiển thị ở danh sách dự án, khớp trang Nhắc gia hạn. Năm trong dữ liệu cũ không chuẩn nên
     * ngày đã qua được đưa về kỳ của năm nay / năm sau. Trả về ['date', 'days', 'state'] hoặc null nếu chưa có ngày:
     *   overdue / upcoming  kỳ cần nhắc (xem renewalAlerts), days = số ngày còn lại (âm = đã quá)
     *   later               kỳ tới còn xa;  self: khách tự gia hạn (không nhắc)
     *   stopped             dự án đã đóng / hủy gia hạn: giữ nguyên ngày đã lưu
     * Cần nạp sẵn hostings.
     */
    public function nextRenewal(CarbonImmutable $today): ?array
    {
        $date = $this->renewalDate();
        if (! $date) {
            return null;
        }
        if (! $this->stillRenews()) {
            return ['date' => $date, 'days' => null, 'state' => 'stopped'];
        }

        $alerts = $this->renewalAlerts($today);
        if ($alert = $alerts['overdue'] ?? $alerts['upcoming']) {
            return ['date' => $alert['due'], 'days' => $alert['days'], 'state' => $alerts['overdue'] ? 'overdue' : 'upcoming'];
        }

        $next = $date->gte($today) ? $date : Renewal::inYear($date, $today->year);
        if ($next->lt($today)) {
            $next = Renewal::inYear($date, $today->year + 1);
        }

        return ['date' => $next, 'days' => null, 'state' => $this->selfRenews() ? 'self' : 'later'];
    }

    /** Công nợ: các dự án (trừ đã hủy) còn nợ hợp đồng hoặc phát sinh chưa thu — cùng cách tính với báo cáo doanh thu. */
    public static function debtSummary(): array
    {
        $remaining = self::query()->hasDebt()->withDebt()->get(['id', 'price'])->map(fn (Project $p) => $p->debtAmount());

        return ['total' => (float) $remaining->sum(), 'count' => $remaining->count()];
    }

    public function renewalTotal(): float
    {
        return (float) $this->renewal_hosting_amount + (float) $this->renewal_domain_amount;
    }

    /**
     * Suy ra payment_status từ thu mới đã thu so với giá dự án (như nhánh main); thu đủ nhưng còn phát sinh chưa thu thì
     * vẫn là "một phần".
     */
    public function recalculatePaymentStatus(): void
    {
        $paid = $this->paidNewAmount();
        $price = (float) $this->price;

        $this->payment_status = match (true) {
            $price <= 0, $paid <= 0 => 'unpaid',
            $paid + 0.01 < $price, $this->payments()->unpaid()->exists() => 'partial',
            default => 'paid',
        };

        $this->saveQuietly();
    }
}
