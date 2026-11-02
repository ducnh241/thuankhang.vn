<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Trang nội dung tĩnh của website (Giới thiệu, Liên hệ, Chính sách…), đường dẫn /page/{slug}. content là HTML từ trình
 * soạn thảo (người có quyền sửa trang được nhập HTML) — khi website hiển thị trang thì lọc HTML trước khi in ra.
 */
class Page extends Model
{
    use HasFactory;

    /** Đoạn đầu đường dẫn trang trên website. */
    public const PATH = 'page';

    /** Thư mục ảnh đại diện trên disk public. */
    public const IMAGE_DIRECTORY = 'pages';

    protected $fillable = ['title', 'slug', 'excerpt', 'content', 'image', 'status', 'meta_title', 'meta_description'];

    protected $attributes = [
        'status' => true,
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Menu website cache link của trang được trỏ tới: đổi slug / ẩn / xóa trang → làm mới cache menu
        static::saved(function (Page $page) {
            if ($page->wasChanged(['slug', 'status'])) {
                Menu::forgetAllCache();
            }
        });
        static::deleted(fn () => Menu::forgetAllCache());
    }

    /** Mục menu trỏ tới trang này. */
    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class);
    }

    /** Đường dẫn trang trên website (link menu trỏ tới trang). */
    public function url(): string
    {
        return '/'.self::PATH.'/'.$this->slug;
    }

    public function imageUrl(): ?string
    {
        return $this->image ? Storage::disk('public')->url($this->image) : null;
    }
}
