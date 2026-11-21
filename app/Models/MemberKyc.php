<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Hồ sơ KYC của thành viên (Step 9): họ tên + số CCCD + ngày sinh, tài khoản ngân hàng nhận tiền, ảnh 2 mặt CCCD.
 * Thành viên gửi ở /tai-khoan/kyc → "Chờ duyệt"; quản trị duyệt ("Đã duyệt") hoặc từ chối kèm lý do ("Không được duyệt",
 * thành viên sửa và gửi lại — đè lên hồ sơ cũ). Quy tắc: 1 CCCD = 1 tài khoản = 1 số tài khoản ngân hàng.
 *
 * Dữ liệu nhạy cảm: số CCCD mã hóa trong DB (cast encrypted) + bản băm HMAC để tra trùng; ảnh CCCD mã hóa bằng APP_KEY,
 * lưu ở disk riêng tư (storage/app/private/kyc), chỉ đọc được qua controller có kiểm tra quyền. Không ghi số CCCD /
 * ảnh vào nhật ký.
 */
class MemberKyc extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => 'Chờ duyệt',
        self::STATUS_APPROVED => 'Đã duyệt',
        self::STATUS_REJECTED => 'Không được duyệt',
    ];

    /** Màu tag Ant Design theo trạng thái (trang quản trị). */
    public const STATUS_COLORS = [
        self::STATUS_PENDING => 'gold',
        self::STATUS_APPROVED => 'green',
        self::STATUS_REJECTED => 'red',
    ];

    public const SIDES = ['front' => 'Mặt trước', 'back' => 'Mặt sau'];

    public const DISK = 'local';

    public const DIRECTORY = 'kyc';

    /** Dung lượng tối đa mỗi ảnh (KB); thực tế còn bị giới hạn bởi upload_max_filesize của PHP — xem maxKilobytes(). */
    public const MAX_KILOBYTES = 5120;

    public const IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp'];

    protected $fillable = [
        'member_id', 'status', 'full_name', 'id_number', 'id_number_hash', 'birth_date', 'bank_code', 'bank_account_number',
        'bank_account_name', 'front_image', 'back_image', 'reject_reason', 'submitted_at', 'reviewed_at', 'reviewed_by',
    ];

    protected $attributes = ['status' => self::STATUS_PENDING];

    protected $hidden = ['id_number', 'id_number_hash', 'front_image', 'back_image'];

    protected function casts(): array
    {
        return [
            'id_number' => 'encrypted',
            'birth_date' => 'date',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function bankName(): string
    {
        return config('banks')[$this->bank_code] ?? $this->bank_code;
    }

    /** Số CCCD che bớt, chỉ còn 4 số cuối (trang của thành viên, danh sách quản trị). */
    public function maskedIdNumber(): string
    {
        return '••••••••'.substr((string) $this->id_number, -4);
    }

    /** Số tài khoản che bớt, chỉ còn 4 số cuối (trang của thành viên). */
    public function maskedAccountNumber(): string
    {
        $number = (string) $this->bank_account_number;

        return str_repeat('•', max(0, strlen($number) - 4)).substr($number, -4);
    }

    /** Hồ sơ còn hiệu lực (chờ duyệt / đã duyệt) của thành viên KHÁC — hồ sơ bị từ chối không giữ chỗ CCCD / STK. */
    public function scopeHeldByOthers(Builder $query, int $memberId): void
    {
        $query->where('member_id', '!=', $memberId)->whereIn('status', [self::STATUS_PENDING, self::STATUS_APPROVED]);
    }

    /** Bản băm số CCCD để tra trùng mà không phải giải mã từng dòng. */
    public static function hashIdNumber(string $idNumber): string
    {
        return hash_hmac('sha256', $idNumber, (string) config('app.key'));
    }

    /** Tên để so khớp "tên chủ tài khoản = họ tên trên CCCD": bỏ dấu, in hoa, gọn khoảng trắng. */
    public static function normalizeName(?string $name): string
    {
        return Str::upper(Str::squish(Str::ascii((string) $name)));
    }

    /** Giới hạn mỗi ảnh (KB) đang có hiệu lực: mức của ứng dụng, hoặc mức PHP nếu thấp hơn. */
    public static function maxKilobytes(): int
    {
        return min(self::MAX_KILOBYTES, FileUpload::maxKilobytes());
    }

    /**
     * Lưu một ảnh đã mã hóa vào disk riêng tư, trả về đường dẫn "{thư mục}/{id chủ}/{ngẫu nhiên}.enc". Thư mục mặc định
     * "kyc" (ảnh CCCD, mã QR nhận tiền — id thành viên); ảnh bill chuyển khoản dùng thư mục "bills" (id đơn hàng).
     */
    public static function storeImage(UploadedFile $file, int $ownerId, string $directory = self::DIRECTORY): string
    {
        $path = $directory.'/'.$ownerId.'/'.Str::random(40).'.enc';
        Storage::disk(self::DISK)->put($path, Crypt::encryptString((string) file_get_contents($file->getRealPath())));

        return $path;
    }

    /** Nội dung ảnh (đã giải mã) + kiểu MIME; null nếu file mất / không giải mã được. */
    public function image(string $side): ?array
    {
        return self::readImage($side === 'front' ? $this->front_image : ($side === 'back' ? $this->back_image : null));
    }

    /** Đọc một ảnh đã lưu bằng storeImage(): nội dung đã giải mã + kiểu MIME; null nếu không có / hỏng. */
    public static function readImage(?string $path): ?array
    {
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }
        try {
            $contents = Crypt::decryptString((string) Storage::disk(self::DISK)->get($path));
        } catch (Throwable) {
            return null;
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: '';

        return in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) ? ['contents' => $contents, 'mime' => $mime] : null;
    }

    /** Xóa file ảnh của hồ sơ (khi gửi lại hồ sơ mới). */
    public static function deleteImages(?string ...$paths): void
    {
        foreach (array_filter($paths) as $path) {
            if (str_starts_with($path, self::DIRECTORY.'/') || str_starts_with($path, Order::BILL_DIRECTORY.'/') || str_starts_with($path, WalletTopup::BILL_DIRECTORY.'/')) {
                Storage::disk(self::DISK)->delete($path);
            }
        }
    }
}
