<?php

namespace App\Models;

use App\Services\BinaryTree;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thành viên website (khách mua hàng / cộng tác viên). Đăng nhập bằng số điện thoại qua guard "member" — hoàn toàn
 * tách khỏi tài khoản quản trị (App\Models\User). referrer = người giới thiệu (F1 của mình), gắn khi đăng ký.
 * parent_id + position = vị trí trong cây nhị phân (App\Services\BinaryTree), gán tự động một lần khi tạo.
 */
#[Hidden(['password', 'remember_token'])]
class Member extends Authenticatable
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    /** Ký tự mã giới thiệu: bỏ 0 / O / 1 / I / L cho khỏi đọc nhầm. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = ['phone', 'email', 'full_name', 'password', 'referral_code', 'referrer_id', 'sponsor_side', 'level_id', 'status'];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'cumulative_amount' => 0,
    ];

    /**
     * Thành viên mới (đăng ký, hoặc tạo từ bất kỳ đâu) được xếp ngay vào cây nhị phân — vị trí cố định từ đó. Đăng ký trên
     * website luôn kèm nhánh tự chọn (sponsor_side: L = Nhánh A, R = Nhánh B).
     */
    protected static function booted(): void
    {
        static::created(fn (Member $member) => BinaryTree::place($member));
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'placed_at' => 'datetime',
            'cumulative_amount' => 'integer',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'qr_uploaded_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    /** Người đứng ngay trên trong cây nhị phân (khác người giới thiệu khi bị xếp xuống tầng dưới). Ông tổng: null. */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** Hai người đứng ngay dưới trong cây nhị phân (Nhánh A = L / Nhánh B = R). */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position');
    }

    /** Là ông tổng: gốc của cây nhị phân chính (cấu hình ở Tham số chung, xem BinaryTree::root()). */
    public function isRoot(): bool
    {
        return BinaryTree::root()?->id === $this->id;
    }

    /** Id mọi người trong nhánh của mình ở cây nhị phân — từ vị trí đang đứng tính xuống tất cả các nhánh con. */
    public function branchIds(): array
    {
        return BinaryTree::branchIds($this->id);
    }

    /** F1: người do thành viên này giới thiệu trực tiếp. */
    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    /** Điểm nhận hàng / VP tỉnh thành viên này làm chủ (cấp VP). */
    public function ownedPickupPoint(): HasOne
    {
        return $this->hasOne(PickupPoint::class, 'owner_member_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** Vết lên cấp (mới nhất trước). */
    public function levelHistories(): HasMany
    {
        return $this->hasMany(LevelHistory::class)->latest('id');
    }

    /** Sổ hoa hồng: các dòng F1 / F2 mà thành viên này là người hưởng (mới nhất trước), gồm cả dòng không được nhận. */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class, 'beneficiary_id')->latest('id');
    }

    /** Bút toán ví (mới nhất trước). */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }

    /** Số dư một ví (mặc định ví rút tiền) = tổng bút toán của ví đó (ví chỉ ghi thêm). */
    public function walletBalance(string $wallet = WalletTransaction::WALLET_CASH): int
    {
        return (int) WalletTransaction::where('member_id', $this->id)->where('wallet', $wallet)->sum('amount');
    }

    /**
     * Tóm tắt ví hoa hồng: balance (số dư ví rút tiền), reconsume (số dư ví tái tiêu dùng), total (tổng hoa hồng đã nhận,
     * trước khi chia), tax (tổng thuế TNCN đã khấu trừ), month / month_cash (hoa hồng tháng này: tổng / phần vào ví có thể
     * rút), f1 / f2 (tổng hoa hồng theo tầng), lines (số dòng đã cộng ví), skipped (số dòng không được nhận).
     *
     * @return array{balance: int, reconsume: int, total: int, tax: int, month: int, month_cash: int, f1: int, f2: int, lines: int, skipped: int}
     */
    public function walletSummary(): array
    {
        $credited = fn () => Commission::where('beneficiary_id', $this->id)->credited();

        return [
            'balance' => $this->walletBalance(),
            'reconsume' => $this->walletBalance(WalletTransaction::WALLET_RECONSUME),
            'total' => (int) $credited()->sum('amount'),
            'tax' => (int) $credited()->sum('tax_amount'),
            'month' => (int) $credited()->where('created_at', '>=', now()->startOfMonth())->sum('amount'),
            'month_cash' => (int) $credited()->where('created_at', '>=', now()->startOfMonth())->sum('cash_amount'),
            'f1' => (int) $credited()->where('tier', 'F1')->sum('amount'),
            'f2' => (int) $credited()->where('tier', 'F2')->sum('amount'),
            'lines' => $credited()->count(),
            'skipped' => Commission::where('beneficiary_id', $this->id)->where('status', Commission::STATUS_SKIPPED)->count(),
        ];
    }

    /**
     * Luật kiểm tra ảnh mã QR nhận tiền (không bắt buộc) + thông báo lỗi — dùng chung cho form KYC và trang sửa thông tin.
     *
     * @return array{0: array<int, mixed>, 1: array<string, string>}
     */
    public static function qrImageRules(string $field = 'qr_image'): array
    {
        $max = MemberKyc::maxKilobytes();
        $size = $max >= 1024 ? round($max / 1024, 1).' MB' : $max.' KB';

        return [
            ['nullable', 'file', 'mimes:'.implode(',', MemberKyc::IMAGE_TYPES), 'max:'.$max, 'dimensions:min_width=150,min_height=150'],
            [
                "$field.file" => 'Ảnh mã QR tải lên không thành công. Hãy chọn lại ảnh.',
                "$field.uploaded" => "Ảnh mã QR tải lên không thành công (ảnh quá lớn?). Ảnh tối đa $size.",
                "$field.mimes" => 'Ảnh mã QR phải là ảnh JPG, PNG hoặc WEBP.',
                "$field.max" => "Ảnh mã QR quá lớn. Ảnh tối đa $size.",
                "$field.dimensions" => 'Ảnh mã QR quá nhỏ, không quét được. Hãy chọn ảnh rõ hơn.',
            ],
        ];
    }

    /** Đã tải mã QR nhận tiền chưa. */
    public function hasQr(): bool
    {
        return $this->qr_image !== null;
    }

    /**
     * Lưu ảnh mã QR nhận tiền mới (mã hóa, disk riêng tư — như ảnh CCCD) và xóa ảnh cũ. Ghi nhật ký (không lưu nội dung ảnh).
     * $source: nơi tải lên, để ghi vào nhật ký ("form KYC" / "trang thông tin tài khoản").
     */
    public function replaceQr(UploadedFile $file, string $source): void
    {
        $old = $this->qr_image;
        $this->forceFill(['qr_image' => MemberKyc::storeImage($file, $this->id), 'qr_uploaded_at' => now()])->saveQuietly();
        MemberKyc::deleteImages($old);
        AuditLog::record('updated', $this, null, "Thành viên {$this->full_name} ({$this->referral_code}) ".($old ? 'đổi' : 'tải lên')." mã QR nhận tiền ở {$source}");
    }

    /** Ảnh mã QR nhận tiền: nội dung đã giải mã + kiểu MIME; null nếu chưa có / file hỏng. */
    public function qrImage(): ?array
    {
        return MemberKyc::readImage($this->qr_image);
    }

    /** Mã QR được đổi SAU khi hồ sơ KYC đã duyệt — quản trị cần đối chiếu lại với tài khoản ngân hàng đã xác minh. */
    public function qrChangedAfterKyc(): bool
    {
        $kyc = $this->kyc;

        return $this->qr_uploaded_at !== null && $kyc?->isApproved() && $kyc->reviewed_at !== null && $this->qr_uploaded_at->gt($kyc->reviewed_at);
    }

    /** Hồ sơ KYC (CCCD + tài khoản ngân hàng) — mỗi thành viên một hồ sơ. */
    public function kyc(): HasOne
    {
        return $this->hasOne(MemberKyc::class);
    }

    public function level(): BelongsTo
    {
        return $this->belongsTo(Level::class);
    }

    public function isLocked(): bool
    {
        return $this->status === self::STATUS_LOCKED;
    }

    public function isActivated(): bool
    {
        return $this->activated_at !== null;
    }

    /**
     * Kích hoạt thủ công (quản trị): đặt activated_at, nâng từ cấp gốc lên cấp hưởng hoa hồng thấp nhất đang bật ("Nhân
     * viên"); không đổi doanh số cộng dồn. Một chiều: không có hủy kích hoạt. Ghi một dòng nhật ký với $note.
     * Trả về cấp sau khi kích hoạt; đã kích hoạt từ trước thì không làm gì.
     */
    public function activateManually(string $note): ?Level
    {
        return DB::transaction(function () use ($note) {
            $member = self::lockForUpdate()->findOrFail($this->id);
            $oldLevel = $member->level;
            if ($member->isActivated()) {
                return $oldLevel;
            }
            $level = $oldLevel?->isBase() ? Level::earning()->firstWhere('active', true) : $oldLevel;
            $member->forceFill(['activated_at' => now(), 'level_id' => $level?->id ?? $member->level_id])->saveQuietly();
            AuditLog::record('updated', $member,
                ['old' => ['activated_at' => null, 'level' => $oldLevel?->name], 'new' => ['activated_at' => $member->activated_at->toDateTimeString(), 'level' => $level?->name]],
                $note);
            if ($level && $level->id !== $oldLevel?->id) {
                LevelHistory::create([
                    'member_id' => $member->id, 'from_level_id' => $oldLevel?->id, 'to_level_id' => $level->id,
                    'order_id' => null, 'cumulative_after' => (int) $member->cumulative_amount, 'note' => 'Kích hoạt thủ công',
                ]);
            }
            $this->setRawAttributes($member->getAttributes(), true);

            return $level;
        });
    }

    /** Link giới thiệu của thành viên (trang chủ kèm ?ref=). */
    public function referralLink(): string
    {
        return url('/').'?ref='.$this->referral_code;
    }

    /** Tên rút gọn để người khác thấy (người giới thiệu, F1 / F2): "Nguyễn Văn An" → "Nguyễn V. An". */
    public function shortName(): string
    {
        $words = preg_split('/\s+/u', trim($this->full_name), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) <= 2) {
            return implode(' ', $words);
        }
        $middle = array_map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)).'.', array_slice($words, 1, -1));

        return implode(' ', [$words[0], ...$middle, end($words)]);
    }

    /** Id mọi thành viên ở tuyến dưới (F1, F2, F3…, mọi tầng) — để chặn vòng khi sửa người giới thiệu. */
    public function downlineIds(): array
    {
        $ids = [];
        $level = [$this->id];
        while ($level !== []) {
            $level = self::whereIn('referrer_id', $level)->whereNotIn('id', $ids)->pluck('id')->all();
            $ids = [...$ids, ...$level];
        }

        return $ids;
    }

    /** Sinh mã giới thiệu mới chưa ai dùng (7 ký tự, chữ in hoa + số dễ đọc). */
    public static function generateReferralCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 7; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    /** Chuẩn hóa số di động: bỏ khoảng trắng / dấu chấm / gạch, +84 → 0. Không phải số di động VN → null. */
    public static function normalizePhone(mixed $phone): ?string
    {
        if (! is_string($phone)) {
            return null;
        }
        $phone = preg_replace('/[\s.\-()]/', '', $phone);
        $phone = preg_replace('/^(\+?84)/', '0', $phone);

        return preg_match('/^0[35789]\d{8}$/', $phone) ? $phone : null;
    }

    /** Thành viên theo mã giới thiệu (không phân biệt hoa thường). */
    public static function findByReferralCode(?string $code): ?self
    {
        $code = Str::upper(trim((string) $code));

        return $code === '' ? null : self::where('referral_code', $code)->first();
    }
}
