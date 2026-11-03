<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Menu;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Cây danh mục sản phẩm cho website: chỉ danh mục đang hiện (danh mục con của danh mục ẩn cũng ẩn theo). Nạp một lần
 * mỗi request (AppServiceProvider giữ một bản trên request). Kèm dữ liệu khung website và các kiểu sắp xếp danh sách.
 */
class Catalog
{
    public const SORTS = ['' => 'Nổi bật', 'newest' => 'Mới nhất', 'price_asc' => 'Giá thấp đến cao', 'price_desc' => 'Giá cao đến thấp'];

    private ?Collection $visible = null;

    private ?array $layout = null;

    /** Dữ liệu chung của khung website (đầu / chân trang). */
    public function layoutData(): array
    {
        return $this->layout ??= [
            'site' => SiteSettings::all(),
            'mainMenu' => Menu::tree('main') ?? [],
            'footerMenu' => Menu::tree('footer') ?? [],
            'shopCategories' => $this->roots(),
        ];
    }

    /** Danh mục đang hiện theo thứ tự cây (có depth). */
    public function visible(): Collection
    {
        if ($this->visible !== null) {
            return $this->visible;
        }
        $hidden = [];
        $this->visible = Category::tree(Category::TYPE_PRODUCT)->filter(function (Category $category) use (&$hidden) {
            if (! $category->status || in_array($category->parent_id, $hidden, true)) {
                $hidden[] = $category->id;

                return false;
            }

            return true;
        })->values();

        return $this->visible;
    }

    /** Sản phẩm hiện trên website: đang bán và thuộc danh mục đang hiện (cả cây cha). */
    public function products(): Builder
    {
        return Product::query()->where('status', true)->whereIn('category_id', $this->visible()->pluck('id'));
    }

    public function roots(): Collection
    {
        return $this->visible()->whereNull('parent_id')->values();
    }

    /** Id danh mục và mọi danh mục con cháu đang hiện. */
    public function subtreeIds(int $rootId): array
    {
        $ids = [$rootId];
        $byParent = $this->visible()->groupBy(fn ($c) => $c->parent_id ?? 0);
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($byParent[$ids[$i]] ?? [] as $child) {
                $ids[] = $child->id;
            }
        }

        return array_values(array_unique($ids));
    }

    /** Đường dẫn từ danh mục gốc tới danh mục này (breadcrumb). */
    public function trail(?Category $category): Collection
    {
        $trail = collect();
        $byId = $this->visible()->keyBy('id');
        $current = $category ? $byId[$category->id] ?? null : null;
        while ($current && ! $trail->contains('id', $current->id)) {
            $trail->prepend($current);
            $current = $current->parent_id ? $byId[$current->parent_id] ?? null : null;
        }

        return $trail;
    }

    public function sort(string $sort): string
    {
        return array_key_exists($sort, self::SORTS) ? $sort : '';
    }

    public function sorted(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'newest' => $query->orderByDesc('id'),
            'price_asc' => $query->orderBy('price')->orderByDesc('id'),
            'price_desc' => $query->orderByDesc('price')->orderByDesc('id'),
            default => $query->orderByDesc('is_featured')->orderBy('sort_order')->orderByDesc('id'),
        };
    }
}
