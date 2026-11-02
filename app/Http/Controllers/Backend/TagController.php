<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\TagRequest;
use App\Models\Tag;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TagController extends Controller
{
    /** Danh sách tag (ít, không cần tìm / lọc / sắp xếp): theo tên A–Z, kèm số dự án đang gắn. */
    public function index(): View|RedirectResponse
    {
        $tags = Tag::query()->withCount('projects')->orderBy('name')->orderBy('id')->paginate(50);

        // Trang vượt quá số trang (vd vừa xóa hết tag của trang cuối) → về trang cuối
        if ($tags->isEmpty() && $tags->currentPage() > 1) {
            return redirect($tags->url($tags->lastPage()));
        }

        return view('backend.tags.index', compact('tags'));
    }

    public function store(TagRequest $request): RedirectResponse
    {
        try {
            $tag = Tag::create($request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateSlug('new');
        }

        return back(fallback: route('backend.tags.index'))->with('success', "Đã tạo tag {$tag->name}.");
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        try {
            $tag->update($request->validated());
        } catch (UniqueConstraintViolationException) {
            return $this->duplicateSlug($tag->id);
        }

        return back(fallback: route('backend.tags.index'))->with('success', "Đã cập nhật tag {$tag->name}.");
    }

    /** Xóa tag chỉ gỡ nhãn khỏi các dự án, không ảnh hưởng dự án. */
    /** Tag đang gắn với dự án thì không xóa được (gỡ tag khỏi các dự án trước). */
    public function destroy(Tag $tag): RedirectResponse
    {
        if ($used = $tag->projects()->count()) {
            return back(fallback: route('backend.tags.index'))
                ->with('error', "Không xóa được tag {$tag->name}: đang gắn với {$used} dự án. Gỡ tag khỏi các dự án trước.");
        }

        $tag->delete();

        return back(fallback: route('backend.tags.index'))->with('success', "Đã xóa tag {$tag->name}.");
    }

    /** Xóa nhiều tag: chỉ xóa tag chưa gắn với dự án nào, tag đang dùng thì bỏ qua và báo lại. */
    public function bulkDestroy(Request $request): RedirectResponse
    {
        $ids = array_filter((array) $request->input('ids', []), 'is_numeric');
        $skipped = Tag::whereIn('id', $ids)->has('projects')->count();
        $count = Tag::whereIn('id', $ids)->doesntHave('projects')->delete();
        $back = back(fallback: route('backend.tags.index'));

        return match (true) {
            $count > 0 => $back->with('success', "Đã xóa {$count} tag.".($skipped ? " Bỏ qua {$skipped} tag đang gắn với dự án." : '')),
            $skipped > 0 => $back->with('error', "Không xóa được: {$skipped} tag đang gắn với dự án. Gỡ tag khỏi các dự án trước."),
            default => $back->with('error', 'Chưa chọn tag nào.'),
        };
    }

    /** Hai người lưu cùng slug cùng lúc: cả hai qua bước kiểm tra, DB chặn người sau → báo lỗi thay vì trang 500. */
    private function duplicateSlug(int|string $dialog): RedirectResponse
    {
        return back(fallback: route('backend.tags.index'))->withInput()->with('tag_dialog', $dialog)
            ->withErrors(['slug' => 'Đã có tag dùng slug này (tên trùng hoặc gần giống một tag khác).'], 'tag');
    }
}
