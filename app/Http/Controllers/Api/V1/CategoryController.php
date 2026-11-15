<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * API danh mục sản phẩm (Category::TYPE_PRODUCT). Quyền: product (xem), product/add, product/edit, product/delete.
 * Xóa bị chặn khi còn sản phẩm (kể cả đã xóa mềm) hoặc còn danh mục con.
 */
class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('product');
        $counts = Product::query()->selectRaw('category_id, COUNT(*) total')->groupBy('category_id')->pluck('total', 'category_id');

        return response()->json(['data' => Category::tree(Category::TYPE_PRODUCT)->map(fn (Category $c) => $this->toArray($c, $counts))->values()]);
    }

    public function show(Category $category): JsonResponse
    {
        Gate::authorize('product');
        $this->ensureProductCategory($category);

        return response()->json(['data' => $this->toArray($category)]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('product/add');
        $data = $this->validated($request);
        $category = Category::create([...$data, 'type' => Category::TYPE_PRODUCT,
            'priority' => (int) Category::where('type', Category::TYPE_PRODUCT)->where('parent_id', $data['parent_id'])->max('priority') + 1]);

        return response()->json(['data' => $this->toArray($category->refresh())], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        Gate::authorize('product/edit');
        $this->ensureProductCategory($category);
        $data = $this->validated($request, $category);
        $category->update($data);

        return response()->json(['data' => $this->toArray($category->refresh())]);
    }

    public function destroy(Category $category): JsonResponse
    {
        Gate::authorize('product/delete');
        $this->ensureProductCategory($category);
        $products = Product::withTrashed()->where('category_id', $category->id)->count();
        if ($products > 0 || $category->children()->exists()) {
            return response()->json(['message' => "Không xóa được “{$category->name}”: ".($products > 0 ? "còn {$products} sản phẩm thuộc danh mục này" : 'còn danh mục con').'.'], 409);
        }
        $category->delete();

        return response()->json(['message' => "Đã xóa danh mục {$category->name}."]);
    }

    private function validated(Request $request, ?Category $current = null): array
    {
        $creating = $current === null;
        if ($request->has('name') && ! $request->filled('slug') && $creating) {
            $request->merge(['slug' => Str::slug((string) $request->input('name'))]);
        } elseif ($request->has('slug')) {
            $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);
        }
        $rules = [
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'slug' => [$creating ? 'required' : 'sometimes', 'string', 'max:255', 'alpha_dash:ascii',
                Rule::unique('categories', 'slug')->where('type', Category::TYPE_PRODUCT)->ignore($current)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists('categories', 'id')->where('type', Category::TYPE_PRODUCT)],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'status' => ['sometimes', 'boolean'],
        ];
        $data = $request->validate($rules, ['slug.unique' => 'Đường dẫn danh mục đã có danh mục khác dùng.']);
        if (array_key_exists('parent_id', $data) || $creating) {
            $data['parent_id'] = isset($data['parent_id']) ? (int) $data['parent_id'] : null;
            if ($current && $data['parent_id'] && in_array($data['parent_id'], [$current->id, ...Category::descendantIds($current->id, Category::TYPE_PRODUCT)], true)) {
                abort(response()->json(['message' => 'Danh mục cha không thể là chính nó hoặc danh mục con của nó.', 'errors' => ['parent_id' => ['Danh mục cha không hợp lệ.']]], 422));
            }
        }

        return $data;
    }

    private function ensureProductCategory(Category $category): void
    {
        abort_unless($category->type === Category::TYPE_PRODUCT, 404);
    }

    private function toArray(Category $c, $counts = null): array
    {
        return [
            'id' => $c->id, 'name' => $c->name, 'slug' => $c->slug, 'parent_id' => $c->parent_id, 'description' => $c->description,
            'status' => (bool) $c->status, 'url' => url($c->url()),
            'product_count' => $counts ? (int) ($counts[$c->id] ?? 0) : Product::where('category_id', $c->id)->count(),
        ];
    }
}
