<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Menu website theo vị trí cố định trong code (Menu::POSITIONS, handle = khóa vị trí): không tạo / xóa menu ở trang
 * quản trị, dòng menus của một vị trí được tạo khi thêm mục đầu tiên. Cây mục hiển thị (label, url, children) được
 * cache theo handle; sửa mục menu / danh mục được trỏ tới sẽ xóa cache.
 */
class Menu extends Model
{
    use HasFactory;

    /** Vị trí menu trên website: handle => [tên, dùng ở đâu]. */
    public const POSITIONS = [
        'top' => ['Menu đầu trang', 'thanh nhỏ trên cùng của website'],
        'main' => ['Menu chính', 'thanh điều hướng của trang chủ'],
        'footer' => ['Menu chân trang', 'các link ở chân trang website'],
    ];

    /** Số cấp tối đa. */
    public const MAX_DEPTH = 3;

    protected $fillable = ['name', 'handle'];

    protected function casts(): array
    {
        return [
            // Số đếm từ withCount: một số driver MySQL trả về chuỗi ("0") → ép số nguyên để so sánh === 0 không sai
            'items_count' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(MenuItem::class)->orderBy('position')->orderBy('id');
    }

    /** Menu của vị trí (tạo dòng menus khi chưa có). */
    public static function forPosition(string $position): self
    {
        return self::firstOrCreate(['handle' => $position], ['name' => self::POSITIONS[$position][0] ?? $position]);
    }

    /**
     * Cây mục đang hiển thị của menu theo handle cho frontend; null nếu chưa có menu đó. Mục ẩn (và mục con của nó),
     * mục trỏ tới danh mục đang ẩn không có trong cây.
     *
     * @return list<array{label: string, url: ?string, children: list<array>}>|null
     */
    public static function tree(string $handle): ?array
    {
        return Cache::rememberForever(self::cacheKey($handle), function () use ($handle) {
            $menu = self::where('handle', $handle)->with(['items.category', 'items.page'])->first();

            return $menu?->toTree();
        });
    }

    public static function forgetCache(string $handle): void
    {
        Cache::forget(self::cacheKey($handle));
    }

    /** Xóa cache mọi menu (vd danh mục được trỏ tới đổi slug / bị ẩn / bị xóa). */
    public static function forgetAllCache(): void
    {
        foreach (self::pluck('handle')->merge(array_keys(self::POSITIONS))->unique() as $handle) {
            self::forgetCache($handle);
        }
    }

    private static function cacheKey(string $handle): string
    {
        return 'menu.tree.'.$handle;
    }

    /** @return list<array{label: string, url: ?string, children: list<array>}> */
    public function toTree(): array
    {
        $byParent = $this->items->groupBy(fn (MenuItem $i) => (int) $i->parent_id);
        $build = function (int $parentId) use (&$build, $byParent): array {
            return $byParent->get($parentId, collect())
                ->filter(fn (MenuItem $i) => $i->isShown())
                ->map(fn (MenuItem $i) => [
                    'label' => $i->label,
                    'url' => $i->href(),
                    'children' => $build($i->id),
                ])->values()->all();
        };

        return $build(0);
    }

    /** Thay toàn bộ mục bằng cây mới (label, url, children), giữ thứ tự. Dùng cho seeder. */
    public function saveTree(array $tree): void
    {
        DB::transaction(function () use ($tree) {
            // Gỡ quan hệ cha–con trước rồi mới xóa: MySQL xóa cha thì cascade xóa con ngay trong cùng câu DELETE và bỏ
            // sót dòng khi quét (khóa ngoại tự tham chiếu)
            MenuItem::where('menu_id', $this->id)->whereNotNull('parent_id')->update(['parent_id' => null]);
            MenuItem::where('menu_id', $this->id)->delete();
            $insert = function (array $nodes, ?int $parentId) use (&$insert): void {
                foreach (array_values($nodes) as $position => $node) {
                    $item = $this->items()->create([
                        'parent_id' => $parentId,
                        'label' => $node['label'],
                        'url' => ($node['url'] ?? '') !== '' ? $node['url'] : null,
                        'position' => $position,
                    ]);
                    $insert($node['children'] ?? [], $item->id);
                }
            };
            $insert($tree, null);
        });
        $this->unsetRelation('items');
        self::forgetCache($this->handle);
    }

    /**
     * Mục theo thứ tự cây (cha rồi tới con, theo position), mỗi mục thêm `depth`. Mục không đi tới được từ gốc (dữ liệu
     * lỗi) vẫn hiện ở cấp gốc để còn sửa / xóa được.
     *
     * @return \Illuminate\Support\Collection<int, MenuItem>
     */
    public function flatTree(): \Illuminate\Support\Collection
    {
        $all = $this->items()->with(['category', 'page'])->get();
        $byParent = $all->groupBy(fn (MenuItem $i) => (int) $i->parent_id);
        $result = collect();
        $walk = function (int $parentId, int $depth, array $seen) use (&$walk, $byParent, $result) {
            foreach ($byParent[$parentId] ?? [] as $item) {
                if (in_array($item->id, $seen, true)) {
                    continue;
                }
                $item->depth = $depth;
                $result->push($item);
                $walk($item->id, $depth + 1, [...$seen, $item->id]);
            }
        };
        $walk(0, 0, []);
        foreach ($all as $item) {
            if (! $result->contains('id', $item->id)) {
                $item->depth = 0;
                $result->push($item);
                $walk($item->id, 1, $result->pluck('id')->all());
            }
        }

        return $result;
    }
}
