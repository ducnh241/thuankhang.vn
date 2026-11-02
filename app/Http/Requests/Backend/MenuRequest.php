<?php

namespace App\Http\Requests\Backend;

use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Thêm / sửa một mục menu. Menu (vị trí) lấy từ route: {position} khi thêm, menu của {item} khi sửa. Ô "Liên kết"
 * (link — ô nhiều cấp ở trang Menu) gửi: "home" (trang chủ "/"), "url" (tự nhập — lấy ô url, kể cả neo #… trên trang
 * chủ), "category:5" (danh mục), "page:3" (trang); tách thành link_type / url / category_id / page_id để kiểm tra.
 */
class MenuRequest extends FormRequest
{
    /** Scheme được phép trong link; mọi scheme khác (javascript:, data:…) bị từ chối. */
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:100'],
            'parent_id' => ['nullable', 'integer', Rule::exists('menu_items', 'id')->where('menu_id', $this->menuId())],
            'link_type' => ['required', Rule::in(array_keys(MenuItem::LINK_TYPES))],
            // Chỉ kiểm tra ô của nguồn link đã chọn (ô của nguồn khác vẫn gửi lên nhưng bỏ qua)
            'url' => ['exclude_unless:link_type,url', 'bail', 'nullable', 'string', 'max:500', function (string $attribute, mixed $value, \Closure $fail) {
                if (! self::safeUrl($value)) {
                    $fail('Đường dẫn không hợp lệ: chỉ nhận /đường-dẫn, #neo, http(s)://, mailto:, tel:.');
                }
            }],
            'category_id' => ['exclude_unless:link_type,category', 'required', 'integer',
                Rule::exists('categories', 'id')->whereIn('type', array_column(Category::KINDS, 'type'))],
            'page_id' => ['exclude_unless:link_type,page', 'required', 'integer', Rule::exists('pages', 'id')],
            'is_visible' => ['required', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'label' => 'tiêu đề', 'parent_id' => 'mục cha', 'link_type' => 'liên kết', 'url' => 'đường dẫn',
            'category_id' => 'danh mục', 'page_id' => 'trang', 'is_visible' => 'hiển thị',
        ];
    }

    public function messages(): array
    {
        return ['category_id.required' => 'Chọn danh mục.', 'page_id.required' => 'Chọn trang.', 'page_id.exists' => 'Trang đã bị xóa, chọn trang khác.'];
    }

    protected function prepareForValidation(): void
    {
        $link = $this->input('link');
        if (! is_string($link)) {
            return; // không có ô link (gửi thẳng link_type…) hoặc sai kiểu: để rule link_type báo lỗi
        }
        if ($link === 'home') {
            $this->merge(['link_type' => 'url', 'url' => '/']);

            return;
        }
        [$type, $value] = array_pad(explode(':', $link, 2), 2, null);
        $this->merge(['link_type' => $type, ...match ($type) {
            'category' => ['category_id' => $value],
            'page' => ['page_id' => $value],
            default => [],
        }]);
    }

    /** Mục cha: không phải chính nó / con cháu của nó, không vượt quá Menu::MAX_DEPTH cấp. */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->has('parent_id') || ! $this->filled('parent_id')) {
                return;
            }
            $item = $this->route('item');
            $parentId = (int) $this->input('parent_id');
            $menu = Menu::find($this->menuId());
            $tree = $menu ? $menu->flatTree() : collect();
            if ($item && in_array($parentId, self::subtreeIds($tree, $item->id), true)) {
                $validator->errors()->add('parent_id', 'Mục cha không thể là chính nó hoặc mục con của nó.');

                return;
            }
            $height = $item ? self::height($tree, $item->id) : 0;
            if (($tree->firstWhere('id', $parentId)->depth ?? 0) + 1 + $height >= Menu::MAX_DEPTH) {
                $validator->errors()->add('parent_id', 'Menu chỉ lồng tối đa '.Menu::MAX_DEPTH.' cấp.');
            }
        }];
    }

    /** Dữ liệu lưu: chỉ giữ link của nguồn đã chọn. */
    public function itemData(): array
    {
        $data = $this->safe()->only(['label', 'is_visible']);
        $type = $this->validated('link_type');

        return [
            ...$data,
            'label' => trim($data['label']),
            'is_visible' => (bool) $data['is_visible'],
            'parent_id' => $this->filled('parent_id') ? (int) $this->validated('parent_id') : null,
            'url' => $type === 'url' && ($url = trim((string) $this->validated('url'))) !== '' ? $url : null,
            'category_id' => $type === 'category' ? (int) $this->validated('category_id') : null,
            'page_id' => $type === 'page' ? (int) $this->validated('page_id') : null,
        ];
    }

    public static function safeUrl(mixed $url): bool
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/[\s<>"\']/', $url)) {
            return $url === '';
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $m)) {
            return in_array(strtolower($m[1]), self::SAFE_SCHEMES, true);
        }

        return true; // đường dẫn tương đối, "/…" hoặc "#neo"
    }

    /** id của mục và mọi con cháu, theo danh sách phẳng có depth (Menu::flatTree). */
    public static function subtreeIds($tree, int $id): array
    {
        $list = $tree->values();
        $at = $list->search(fn ($i) => $i->id === $id);
        if ($at === false) {
            return [$id];
        }
        $ids = [$id];
        for ($i = $at + 1; $i < $list->count() && $list[$i]->depth > $list[$at]->depth; $i++) {
            $ids[] = $list[$i]->id;
        }

        return $ids;
    }

    /** Số cấp con cháu dưới mục (0 = không có con). */
    public static function height($tree, int $id): int
    {
        $ids = self::subtreeIds($tree, $id);
        $base = $tree->firstWhere('id', $id)->depth ?? 0;

        return $tree->whereIn('id', $ids)->max(fn ($i) => $i->depth - $base) ?? 0;
    }

    private function menuId(): ?int
    {
        if ($item = $this->route('item')) {
            return $item->menu_id;
        }

        return Menu::where('handle', $this->route('position'))->value('id');
    }
}
