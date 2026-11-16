<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Thành viên website (khách mua hàng / cộng tác viên). Đăng nhập bằng số điện thoại qua guard "member" — hoàn toàn
 * tách khỏi tài khoản quản trị (App\Models\User). referrer = người giới thiệu (F1 của mình), gắn khi đăng ký.
 */
#[Hidden(['password', 'remember_token'])]
class Member extends Authenticatable
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_LOCKED = 'locked';

    /** Ký tự mã giới thiệu: bỏ 0 / O / 1 / I / L cho khỏi đọc nhầm. */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    protected $fillable = ['phone', 'email', 'full_name', 'password', 'referral_code', 'referrer_id', 'level_id', 'status'];

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'cumulative_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'cumulative_amount' => 'integer',
            'activated_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
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
