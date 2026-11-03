<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Category extends Model
{
    use HasFactory;

    public const TYPE_POST = 0;

    public const TYPE_EVENT = 2;

    public const TYPE_STORE = 3;

    /** Danh mục sản phẩm của shop affiliate (đặc tả Step 2 / A6). Giá trị 1 bỏ trống: từng là danh mục dự án (đã gỡ). */
    public const TYPE_PRODUCT = 4;

    /**
     * Các loại danh mục quản lý ở trang Danh mục (/backend/category?type={kind}; route thêm / sửa / xóa có {kind}).
     *   type        giá trị cột categories.type
     *   used_by     danh mục dùng cho gì (câu gợi ý dưới ô chọn loại); example: tên ví dụ trong ô nhập
     *   path        đoạn đầu đường dẫn trang danh mục trên website (/tin-tuc/{slug}) — dùng cho link menu; website chưa
     *               có trang danh mục, đổi ở đây khi làm
     *   permission  module quyền (kèm /add, /edit, /delete)
     *   usage       bảng đang dùng danh mục: hiện cột đếm và chặn xóa khi còn bản ghi (kể cả đã xóa mềm); null = chưa có
     */
    public const KINDS = [
        // Đứng đầu: mở trang Danh mục không chọn loại thì vào danh mục sản phẩm. Quyền chung với sản phẩm (A6 "Sản phẩm
        // & danh mục"); còn sản phẩm (kể cả đã xóa mềm) thì không xóa được danh mục.
        'product' => [
            'type' => self::TYPE_PRODUCT,
            'label' => 'Sản phẩm',
            'title' => 'Danh mục sản phẩm',
            'description' => 'Phân loại sản phẩm trên cửa hàng: lọc theo danh mục ở trang chủ / tìm kiếm; có thể lồng nhau (cha – con).',
            'used_by' => 'sản phẩm',
            'example' => 'VD: Thực phẩm chức năng',
            'path' => 'danh-muc',
            'permission' => 'product',
            'usage' => ['table' => 'products', 'column' => 'category_id', 'label' => 'Sản phẩm', 'unit' => 'sản phẩm', 'route' => 'backend.products.index', 'can' => 'product'],
        ],
        'news' => [
            'type' => self::TYPE_POST,
            'label' => 'Tin tức',
            'title' => 'Danh mục tin tức',
            'description' => 'Chuyên mục cho bài viết tin tức trên website; có thể lồng nhau (cha – con).',
            'used_by' => 'bài viết tin tức',
            'example' => 'VD: Công nghệ',
            'path' => 'tin-tuc',
            'permission' => 'news_category',
            'usage' => null,
        ],
    ];

    protected $fillable = ['parent_id', 'type', 'name', 'slug', 'description', 'priority', 'status'];

    protected $attributes = [
        'priority' => 0,
        'status' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => 'integer',
            'priority' => 'integer',
            'status' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // Menu website cache link của danh mục được trỏ tới: đổi slug / ẩn / xóa danh mục → làm mới cache menu
        $refresh = fn () => Menu::forgetAllCache();
        static::saved($refresh);
        static::deleted($refresh);
    }

    /** Kind (khóa Category::KINDS) của danh mục, null nếu loại không có trang quản lý. */
    public function kind(): ?string
    {
        return collect(self::KINDS)->search(fn ($k) => $k['type'] === $this->type) ?: null;
    }

    /** Đường dẫn trang danh mục trên website (link menu trỏ tới danh mục). */
    public function url(): ?string
    {
        $kind = $this->kind();

        return $kind ? '/'.self::KINDS[$kind]['path'].'/'.$this->slug : null;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    /**
     * Danh mục theo thứ tự cây (cha rồi tới con), mỗi phần tử có thêm `depth`.
     * Sắp theo priority rồi tên trong cùng một cấp.
     *
     * @return Collection<int, Category>
     */
    public static function tree(int $type): Collection
    {
        $all = self::where('type', $type)->orderBy('priority')->orderBy('name')->get();
        $byParent = $all->groupBy(fn ($c) => $c->parent_id ?? 0);
        $result = collect();

        $walk = function (int $parentId, int $depth, array $seen) use (&$walk, $byParent, $result) {
            foreach ($byParent[$parentId] ?? [] as $category) {
                if (in_array($category->id, $seen, true)) {
                    continue; // phòng dữ liệu lỗi có vòng lặp cha–con
                }
                $category->depth = $depth;
                $result->push($category);
                $walk($category->id, $depth + 1, [...$seen, $category->id]);
            }
        };

        $walk(0, 0, []);

        // Danh mục không đi tới được từ gốc (cha không tồn tại, hoặc dữ liệu lỗi có vòng lặp A ↔ B):
        // vẫn hiện ở cấp gốc để còn mở ra sửa được, không để "biến mất"
        foreach ($all as $category) {
            if (! $result->contains('id', $category->id)) {
                $category->depth = 0;
                $result->push($category);
                $walk($category->id, 1, [...$result->pluck('id')->all()]);
            }
        }

        return $result;
    }

    /** id của danh mục này và mọi danh mục con cháu. */
    public static function descendantIds(int $id, int $type): array
    {
        $children = self::where('type', $type)->get(['id', 'parent_id'])->groupBy('parent_id');
        $ids = [$id];

        for ($i = 0; $i < count($ids); $i++) {
            foreach ($children[$ids[$i]] ?? [] as $child) {
                if (! in_array($child->id, $ids, true)) {
                    $ids[] = $child->id;
                }
            }
        }

        return $ids;
    }

    /** Tên kèm ký hiệu cấp để dùng trong <select>: "Cha", "— Con", "— — Cháu". */
    public function indentedName(): string
    {
        return str_repeat('— ', $this->depth ?? 0).$this->name;
    }
}
