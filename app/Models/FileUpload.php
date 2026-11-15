<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * File tải lên ở màn Quản lý file (/backend/file). Chỉ nhận các loại trong TYPES — không nhận SVG / HTML / mã chạy được
 * vì file nằm cùng tên miền với website. Đuôi file lưu trên đĩa lấy theo nội dung file, không theo tên người dùng gửi.
 */
class FileUpload extends Model
{
    use HasFactory;

    public const DIRECTORY = 'uploads';

    /** Dung lượng tối đa mỗi file (KB); thực tế còn bị giới hạn bởi upload_max_filesize của PHP — xem maxKilobytes(). */
    public const MAX_KILOBYTES = 10240;

    /** Số file tối đa mỗi lần tải. */
    public const MAX_FILES = 10;

    /** Nhóm loại file (tab của danh sách) => các đuôi được nhận. */
    public const TYPES = [
        'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],
        'document' => ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip'],
    ];

    protected $fillable = ['user_id', 'name', 'path', 'extension', 'mime', 'size'];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return list<string> */
    public static function extensions(): array
    {
        return array_merge(...array_values(self::TYPES));
    }

    /** Giới hạn mỗi file (KB) đang có hiệu lực: mức của ứng dụng, hoặc mức PHP nếu thấp hơn. */
    public static function maxKilobytes(): int
    {
        $php = self::iniBytes((string) ini_get('upload_max_filesize'));

        return $php > 0 ? min(self::MAX_KILOBYTES, intdiv($php, 1024)) : self::MAX_KILOBYTES;
    }

    /** Tổng dung lượng một lần gửi (byte) mà PHP nhận; 0 = không giới hạn. */
    public static function maxPostBytes(): int
    {
        return self::iniBytes((string) ini_get('post_max_size'));
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->path);
    }

    public function isImage(): bool
    {
        return in_array($this->extension, self::TYPES['image'], true);
    }

    /** Dung lượng dễ đọc: 532 KB, 1,4 MB. */
    public function sizeLabel(): string
    {
        return self::formatSize($this->size);
    }

    public static function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        if ($bytes < 1024 ** 2) {
            return number_format($bytes / 1024, 0, ',', '.').' KB';
        }

        return rtrim(rtrim(number_format($bytes / 1024 ** 2, 1, ',', '.'), '0'), ',').' MB';
    }

    /** Xóa file trên đĩa (chỉ trong thư mục uploads/) rồi xóa dòng. */
    public function remove(): void
    {
        if (str_starts_with($this->path, self::DIRECTORY.'/')) {
            Storage::disk('public')->delete($this->path);
        }
        $this->delete();
    }
}
