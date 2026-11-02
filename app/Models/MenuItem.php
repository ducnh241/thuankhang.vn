<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một mục menu. Link lấy từ một trong ba nguồn (form chọn một): trang (page_id), danh mục (category_id) hoặc đường
 * dẫn gõ tay (url).
 */
class MenuItem extends Model
{
    /** Nguồn link: khóa => tên. */
    public const LINK_TYPES = ['url' => 'Đường dẫn', 'category' => 'Danh mục', 'page' => 'Trang'];

    protected $fillable = ['menu_id', 'parent_id', 'label', 'url', 'page_id', 'category_id', 'position', 'is_visible'];

    protected $attributes = [
        'is_visible' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'parent_id');
    }

    public function linkType(): string
    {
        return match (true) {
            $this->page_id !== null => 'page',
            $this->category_id !== null => 'category',
            default => 'url',
        };
    }

    /** Link trên website: danh mục / trang → đường dẫn của danh mục / trang; còn lại → url gõ tay. */
    public function href(): ?string
    {
        return match ($this->linkType()) {
            'category' => $this->category?->url(),
            'page' => $this->page?->url(),
            default => $this->url,
        };
    }

    /** Hiện trên website: mục bật hiển thị và (nếu trỏ tới danh mục / trang) danh mục / trang đó đang hiển thị. */
    public function isShown(): bool
    {
        return $this->is_visible
            && ($this->category_id === null || $this->category?->status === true)
            && ($this->page_id === null || $this->page?->status === true);
    }
}
