<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\CategoryRequest;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Danh mục mọi loại (dự án, tin tức… — Category::KINDS) trên một trang, theo categories.html của template:
 * /backend/category?type=project&id=3 — cây danh mục của loại đang chọn bên trái (kéo thả để sắp xếp / lồng cấp),
 * form sửa danh mục đang chọn bên phải (&new: thêm mới). Quyền theo từng loại: Gate category[/add|/edit|/delete]
 * với tham số kind (xem AppServiceProvider); trang chỉ hiện các loại người dùng được xem.
 */
class CategoryController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $kinds = collect(Category::KINDS)->filter(fn ($k, $key) => $user->can('category', $key));
        abort_if($kinds->isEmpty(), 403);

        $kind = is_string($request->query('type')) && $kinds->has($request->query('type')) ? $request->query('type') : $kinds->keys()->first();
        $config = Category::KINDS[$kind];
        $categories = Category::tree($config['type']);

        // Danh mục đang mở: ?id=… (không có / không thuộc loại này → danh mục đầu tiên); ?new (có quyền thêm) hoặc chưa
        // có danh mục → thêm mới
        $selected = null;
        if (! $request->has('new') || ! $user->can('category/add', $kind)) {
            $id = $request->query('id');
            $selected = (is_string($id) && ctype_digit($id) ? $categories->firstWhere('id', (int) $id) : null) ?? $categories->first();
        }
        $category = $selected ?? new Category(['type' => $config['type']]);
        $excluded = $selected ? Category::descendantIds($selected->id, $config['type']) : [];

        return view('backend.categories.index', [
            'kinds' => $kinds,
            'kind' => $kind,
            'config' => $config,
            'categories' => $categories,
            'category' => $category,
            // Không cho chọn chính nó / con cháu làm cha
            'parents' => $categories->reject(fn ($c) => in_array($c->id, $excluded, true)),
            'usageCounts' => $this->usageCounts($config),
            'blocked' => $selected ? $this->blockedReason($config, $selected) : null,
        ]);
    }

    public function store(CategoryRequest $request, string $kind): RedirectResponse
    {
        $config = $this->config($kind);
        $data = $request->categoryData();
        // Danh mục mới đứng cuối các danh mục cùng cấp
        $category = Category::create([...$data, 'type' => $config['type'], 'priority' => $this->endOf($config['type'], $data['parent_id'])]);

        return redirect()->route('backend.categories.index', ['type' => $kind, 'id' => $category->id])->with('success', 'Đã tạo danh mục.');
    }

    public function update(CategoryRequest $request, string $kind, Category $category): RedirectResponse
    {
        $this->config($kind, $category);
        $data = $request->categoryData();
        // Đổi cha: xuống cuối các danh mục cùng cấp mới
        if ($data['parent_id'] !== $category->parent_id) {
            $data['priority'] = $this->endOf($category->type, $data['parent_id']);
        }
        $category->update($data);

        return redirect()->route('backend.categories.index', ['type' => $kind, 'id' => $category->id])->with('success', 'Đã lưu danh mục.');
    }

    /**
     * Kéo thả trên cây: đặt danh mục trước / sau một danh mục khác, hoặc vào trong (thành con cuối). Đánh số lại thứ
     * tự (priority 1, 2, 3…) các danh mục cùng cấp theo thứ tự đang hiện.
     */
    public function move(Request $request, string $kind, Category $category): JsonResponse
    {
        $config = $this->config($kind, $category);
        $data = $request->validate([
            'target_id' => ['required', 'integer', Rule::exists('categories', 'id')->where('type', $config['type'])],
            'zone' => ['required', Rule::in(['before', 'after', 'inside'])],
        ], ['target_id.exists' => 'Không tìm thấy danh mục đích.']);
        if (in_array((int) $data['target_id'], Category::descendantIds($category->id, $config['type']), true)) {
            return response()->json(['message' => 'Không thể chuyển danh mục vào chính nó hoặc danh mục con của nó.'], 422);
        }

        $target = Category::find($data['target_id']);
        $parentId = $data['zone'] === 'inside' ? $target->id : $target->parent_id;
        DB::transaction(function () use ($category, $target, $parentId, $data) {
            $siblings = Category::where('type', $category->type)->where('parent_id', $parentId)->whereKeyNot($category->id)
                ->orderBy('priority')->orderBy('name')->get();
            $at = $data['zone'] === 'inside' ? $siblings->count() : $siblings->search(fn ($c) => $c->id === $target->id) + ($data['zone'] === 'after' ? 1 : 0);
            $siblings->splice($at, 0, [$category]);
            foreach ($siblings->values() as $i => $sibling) {
                $sibling->fill(['priority' => $i + 1, 'parent_id' => $parentId])->save(); // chỉ ghi khi đổi
            }
        });
        session()->flash('success', "Đã chuyển “{$category->name}”.");

        return response()->json(['ok' => true]);
    }

    public function destroy(string $kind, Category $category): RedirectResponse
    {
        $config = $this->config($kind, $category);

        if ($reason = $this->blockedReason($config, $category)) {
            return back()->with('error', "Không xóa được \"{$category->name}\": {$reason}.");
        }

        $category->delete();

        return redirect()->route('backend.categories.index', ['type' => $kind])->with('success', "Đã xóa danh mục {$category->name}.");
    }

    /** URL cũ (trang danh sách / thêm / sửa riêng của từng loại) → trang gộp. */
    public function legacy(string $kind, ?Category $category = null): RedirectResponse
    {
        $this->config($kind, $category);
        $query = ['type' => $kind];
        if ($category) {
            $query['id'] = $category->id;
        } elseif (request()->routeIs('backend.categories.create')) {
            $query['new'] = 1;
        }

        return redirect()->route('backend.categories.index', $query);
    }

    /**
     * Cấu hình của loại; route chỉ nhận kind có trong Category::KINDS.
     * Danh mục lấy theo id qua route binding: chặn sửa/xóa danh mục loại khác bằng cách đổi URL.
     */
    private function config(string $kind, ?Category $category = null): array
    {
        $config = Category::KINDS[$kind];
        abort_if($category && $category->type !== $config['type'], 404);

        return $config;
    }

    /** Thứ tự để đứng cuối các danh mục cùng cha. */
    private function endOf(int $type, ?int $parentId): int
    {
        return (int) Category::where('type', $type)->where('parent_id', $parentId)->max('priority') + 1;
    }

    /** Số bản ghi (chưa xóa) gắn trực tiếp vào từng danh mục, theo bảng usage của loại. */
    private function usageCounts(array $config): array
    {
        if (! $usage = $config['usage']) {
            return [];
        }

        return DB::table($usage['table'])->whereNull('deleted_at')->whereNotNull($usage['column'])
            ->select($usage['column'], DB::raw('COUNT(*) as cnt'))->groupBy($usage['column'])->pluck('cnt', $usage['column'])->all();
    }

    /** Lý do không xóa được (còn bản ghi dùng — kể cả đã xóa mềm — hoặc còn danh mục con), null nếu xóa được. */
    private function blockedReason(array $config, Category $category): ?string
    {
        if ($usage = $config['usage']) {
            $count = DB::table($usage['table'])->where($usage['column'], $category->id)->count();
            if ($count > 0) {
                return "còn {$count} {$usage['unit']} thuộc danh mục này";
            }
        }

        if ($category->children()->exists()) {
            return 'còn danh mục con';
        }

        return null;
    }
}
