<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\PageRequest;
use App\Models\Menu;
use App\Models\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Trang nội dung website (/page/{slug}): danh sách (tab đang hiện / đang ẩn, tìm theo tiêu đề / đường dẫn), trang thêm /
 * sửa (nội dung soạn bằng trình soạn thảo, ảnh đại diện, SEO). Trang đang được menu trỏ tới thì không xóa được.
 */
class PageController extends Controller
{
    public const TABS = ['' => 'Tất cả', 'visible' => 'Đang hiện', 'hidden' => 'Đang ẩn'];

    public function index(Request $request): View|RedirectResponse
    {
        $tab = $this->queryString($request, 'tab');
        $filter = ['q' => $this->queryString($request, 'q'), 'tab' => array_key_exists($tab, self::TABS) ? $tab : ''];

        $base = Page::query()->when($filter['q'] !== '', function (Builder $query) use ($filter) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
            $query->where(fn ($q) => $q->whereRaw("title LIKE ? ESCAPE '!'", [$like])->orWhereRaw("slug LIKE ? ESCAPE '!'", [$like]));
        });

        // Số trang từng tab, theo từ khóa đang tìm
        $byStatus = (clone $base)->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status');
        $counts = ['' => (int) $byStatus->sum(), 'visible' => (int) ($byStatus[1] ?? 0), 'hidden' => (int) ($byStatus[0] ?? 0)];

        $pages = (clone $base)
            ->when($filter['tab'] !== '', fn ($q) => $q->where('status', $filter['tab'] === 'visible'))
            ->withCount('menuItems')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Trang vượt quá số trang (vd vừa xóa trang cuối của trang cuối) → về trang cuối còn dữ liệu
        if ($pages->isEmpty() && $pages->currentPage() > 1) {
            return redirect($pages->url($pages->lastPage()));
        }

        return view('backend.pages.index', compact('pages', 'filter', 'counts'));
    }

    public function create(): View
    {
        return view('backend.pages.form', ['page' => new Page]);
    }

    public function store(PageRequest $request): RedirectResponse
    {
        $data = $request->pageData();
        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store(Page::IMAGE_DIRECTORY, 'public');
        }
        $page = Page::create($data);

        return ($request->user()->can('page/edit')
            ? redirect()->route('backend.pages.edit', $page)
            : redirect()->route('backend.pages.index'))->with('success', "Đã tạo trang {$page->title}.");
    }

    public function edit(Page $page): View
    {
        return view('backend.pages.form', ['page' => $page->load('menuItems.menu')]);
    }

    public function update(PageRequest $request, Page $page): RedirectResponse
    {
        $data = $request->pageData();
        if ($request->boolean('remove_image') || $request->hasFile('image')) {
            $this->deleteImage($page->image);
            $data['image'] = $request->hasFile('image') ? $request->file('image')->store(Page::IMAGE_DIRECTORY, 'public') : null;
        }
        $page->update($data);

        return redirect()->route('backend.pages.edit', $page)->with('success', 'Đã lưu trang.');
    }

    public function destroy(Page $page): RedirectResponse
    {
        $items = $page->menuItems()->with('menu')->get();
        if ($items->isNotEmpty()) {
            $where = $items->map(fn ($i) => (Menu::POSITIONS[$i->menu?->handle][0] ?? 'Menu').' › '.$i->label)->unique()->implode(', ');

            return back()->with('error', "Không xóa được \"{$page->title}\": đang dùng trong menu ({$where}). Hãy đổi liên kết các mục menu đó trước.");
        }

        $this->deleteImage($page->image);
        $page->delete();

        // Xóa từ trang sửa của chính trang này: trang đó không còn → về danh sách; xóa từ danh sách thì giữ trang
        $back = url()->previous();
        $toList = $back === route('backend.pages.edit', $page) || ! str_starts_with($back, route('backend.pages.index'));

        return ($toList ? redirect()->route('backend.pages.index') : back())->with('success', "Đã xóa trang {$page->title}.");
    }

    private function deleteImage(?string $path): void
    {
        if ($path && str_starts_with($path, Page::IMAGE_DIRECTORY.'/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
