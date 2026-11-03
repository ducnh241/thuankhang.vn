<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\MenuRequest;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\Page;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Menu website, giao diện như trang Danh mục: /backend/menu?position=main&id=3 — ô chọn vị trí (cố định trong code,
 * Menu::POSITIONS) + cây mục bên trái (kéo thả để sắp xếp / lồng cấp), form mục đang chọn bên phải (&new: thêm mới).
 * Mỗi thay đổi xóa cache menu của website.
 */
class MenuController extends Controller
{
    public function index(Request $request): View
    {
        $position = $this->positionOf($request->query('position'));
        $menu = Menu::where('handle', $position)->first();
        $items = $menu ? $menu->flatTree() : collect();

        // Mục đang mở: ?id=… (không thuộc menu này → mục đầu tiên); ?new (có quyền thêm) hoặc menu trống → thêm mới
        $selected = null;
        if (! $request->has('new') || ! $request->user()->can('menu/add')) {
            $id = $request->query('id');
            $selected = (is_string($id) && ctype_digit($id) ? $items->firstWhere('id', (int) $id) : null) ?? $items->first();
        }
        $item = $selected ?? new MenuItem;
        if (! $selected && $request->query('parent') !== null) {
            $item->parent_id = $items->firstWhere('id', (int) $request->query('parent'))?->id; // "Thêm mục con"
        }

        // Ô chọn cha: bỏ chính nó / con cháu, và các mục mà đặt vào sẽ vượt quá số cấp cho phép
        $excluded = $selected ? MenuRequest::subtreeIds($items, $selected->id) : [];
        $height = $selected ? MenuRequest::height($items, $selected->id) : 0;
        $parents = $items->reject(fn ($i) => in_array($i->id, $excluded, true) || $i->depth + 1 + $height >= Menu::MAX_DEPTH);

        return view('backend.menus.index', [
            'position' => $position,
            'positions' => collect(Menu::POSITIONS)->map(fn ($p, $key) => [
                ...$p, 'count' => MenuItem::whereHas('menu', fn ($q) => $q->where('handle', $key))->count(),
            ]),
            'items' => $items,
            'item' => $item,
            'parents' => $parents,
            'categories' => collect(Category::KINDS)->map(fn ($k) => ['title' => $k['title'], 'list' => Category::tree($k['type'])]),
            'pages' => Page::orderBy('title')->get(['id', 'title', 'status']),
            'childCount' => $selected ? count($excluded) - 1 : 0,
        ]);
    }

    public function store(MenuRequest $request, string $position): RedirectResponse
    {
        $menu = Menu::forPosition($position);
        $data = $request->itemData();
        $item = $menu->items()->create([...$data, 'position' => $this->endOf($menu->id, $data['parent_id'])]);
        Menu::forgetCache($menu->handle);

        return redirect()->route('backend.menus.index', ['position' => $position, 'id' => $item->id])->with('success', 'Đã thêm mục menu.');
    }

    public function update(MenuRequest $request, MenuItem $item): RedirectResponse
    {
        $data = $request->itemData();
        // Đổi cha: xuống cuối các mục cùng cấp mới
        if ($data['parent_id'] !== $item->parent_id) {
            $data['position'] = $this->endOf($item->menu_id, $data['parent_id']);
        }
        $item->update($data);
        Menu::forgetCache($item->menu->handle);

        return redirect()->route('backend.menus.index', ['position' => $item->menu->handle, 'id' => $item->id])->with('success', 'Đã lưu mục menu.');
    }

    /**
     * Kéo thả trên cây: đặt mục trước / sau một mục khác, hoặc vào trong (thành con cuối). Đánh số lại thứ tự các mục
     * cùng cấp theo thứ tự đang hiện.
     */
    public function move(Request $request, MenuItem $item): JsonResponse
    {
        $data = $request->validate([
            'target_id' => ['required', 'integer', Rule::exists('menu_items', 'id')->where('menu_id', $item->menu_id)],
            'zone' => ['required', Rule::in(['before', 'after', 'inside'])],
        ], ['target_id.exists' => 'Không tìm thấy mục đích.']);

        $tree = $item->menu->flatTree();
        if (in_array((int) $data['target_id'], MenuRequest::subtreeIds($tree, $item->id), true)) {
            return response()->json(['message' => 'Không thể chuyển mục vào chính nó hoặc mục con của nó.'], 422);
        }
        $target = $tree->firstWhere('id', (int) $data['target_id']);
        $depth = $data['zone'] === 'inside' ? $target->depth + 1 : $target->depth;
        if ($depth + MenuRequest::height($tree, $item->id) >= Menu::MAX_DEPTH) {
            return response()->json(['message' => 'Menu chỉ lồng tối đa '.Menu::MAX_DEPTH.' cấp.'], 422);
        }

        $parentId = $data['zone'] === 'inside' ? $target->id : $target->parent_id;
        DB::transaction(function () use ($item, $target, $parentId, $data) {
            $siblings = MenuItem::where('menu_id', $item->menu_id)->where('parent_id', $parentId)->whereKeyNot($item->id)
                ->orderBy('position')->orderBy('id')->get();
            $at = $data['zone'] === 'inside' ? $siblings->count() : $siblings->search(fn ($i) => $i->id === $target->id) + ($data['zone'] === 'after' ? 1 : 0);
            $siblings->splice($at, 0, [$item]);
            foreach ($siblings->values() as $i => $sibling) {
                $sibling->fill(['position' => $i, 'parent_id' => $parentId])->save(); // chỉ ghi khi đổi
            }
        });
        Menu::forgetCache($item->menu->handle);
        session()->flash('success', "Đã chuyển “{$item->label}”.");

        return response()->json(['ok' => true]);
    }

    /** Xóa mục cùng các mục con (khóa ngoại parent_id cascade). */
    public function destroy(MenuItem $item): RedirectResponse
    {
        $handle = $item->menu->handle;
        $ids = MenuRequest::subtreeIds($item->menu->flatTree(), $item->id);
        DB::transaction(function () use ($ids, $item) {
            // Gỡ cha–con trước rồi xóa cả nhánh (MySQL cascade trên khóa ngoại tự tham chiếu có thể bỏ sót dòng)
            MenuItem::whereIn('id', $ids)->update(['parent_id' => null]);
            MenuItem::whereIn('id', $ids)->delete();
            // Xóa bằng query (không qua event của model) → tự ghi nhật ký
            AuditLog::recordBulkDelete(MenuItem::class, $ids, "Xóa mục menu “{$item->label}” và các mục con");
        });
        Menu::forgetCache($handle);
        $more = count($ids) - 1;

        return redirect()->route('backend.menus.index', ['position' => $this->positionOf($handle)])
            ->with('success', "Đã xóa mục {$item->label}".($more ? " và {$more} mục con" : '').'.');
    }

    /** URL cũ (/menu/create, /menu/{id}/edit) → trang mới. */
    public function legacy(?Menu $menu = null): RedirectResponse
    {
        return redirect()->route('backend.menus.index', ['position' => $this->positionOf($menu?->handle)]);
    }

    private function positionOf(mixed $position): string
    {
        return is_string($position) && isset(Menu::POSITIONS[$position]) ? $position : 'main';
    }

    /** Thứ tự để đứng cuối các mục cùng cha. */
    private function endOf(int $menuId, ?int $parentId): int
    {
        $max = MenuItem::where('menu_id', $menuId)->where('parent_id', $parentId)->max('position');

        return $max === null ? 0 : $max + 1;
    }
}
