<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
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
