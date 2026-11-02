<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\GroupRequest;
use App\Models\Group;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class GroupController extends Controller
{
    /**
     * Trang nhóm quyền theo template user-groups.html: danh sách nhóm bên trái, nhóm đang chọn bên phải.
     * Mở trang (hoặc ?id=N) thì chọn sẵn nhóm đầu / nhóm N: sửa được thì hiện form sửa, không thì chỉ xem.
     */
    public function index(Request $request): View
    {
        $groups = $this->groups();
        $id = $this->queryString($request, 'id');
        $group = (ctype_digit($id) ? $groups->firstWhere('id', (int) $id) : null) ?? $groups->first();
        $editable = $group && $request->user()->can('group/edit') && $request->user()->canAssignGroup($group);

        return $this->page($groups, $group ?? new Group, $group ? ($editable ? 'edit' : 'view') : 'empty');
    }

    public function create(): View
    {
        return $this->page($this->groups(), new Group, 'create');
    }

    public function store(GroupRequest $request): RedirectResponse
    {
        $group = Group::create([
            'name' => $request->validated('name'),
            'permission_data' => $this->grantablePermissions($request),
        ]);

        return redirect()->route('backend.groups.edit', $group)->with('success', 'Đã tạo nhóm quyền.');
    }

    public function edit(Group $group): View
    {
        $this->ensureCanManage($group);

        return $this->page($this->groups(), $group, 'edit');
    }

    public function update(GroupRequest $request, Group $group): RedirectResponse
    {
        $this->ensureCanManage($group);

        $group->update([
            'name' => $request->validated('name'),
            'permission_data' => $this->grantablePermissions($request),
        ]);

        return redirect()->route('backend.groups.edit', $group)->with('success', 'Đã cập nhật nhóm quyền.');
    }

    public function destroy(Group $group): RedirectResponse
    {
        $this->ensureCanManage($group);

        if ($group->users()->visible()->exists()) {
            return back()->with('error', 'Nhóm đang có người dùng, hãy chuyển họ sang nhóm khác trước.');
        }

        $group->delete();

        return redirect()->route('backend.groups.index')->with('success', "Đã xóa nhóm quyền {$group->name}.");
    }

    private function groups()
    {
        // Số người của nhóm không tính tài khoản ẩn
        return Group::withCount(['users' => fn ($query) => $query->visible()])->orderBy('name')->get();
    }

    /** $mode: create | edit | view (không có quyền sửa nhóm này) | empty (chưa có nhóm nào) */
    private function page($groups, Group $group, string $mode): View
    {
        if ($group->exists && $group->users_count === null) {
            $group->loadCount(['users' => fn ($query) => $query->visible()]);
        }

        return view('backend.groups.index', [
            'groups' => $groups,
            'group' => $group,
            'mode' => $mode,
            'permissions' => config('permission'),
        ]);
    }

    /** Không sửa/xóa được nhóm có quyền vượt quyền của mình (Root thì được tất cả). */
    protected function ensureCanManage(Group $group): void
    {
        abort_unless(auth()->user()->canAssignGroup($group), 403, 'Bạn không được sửa nhóm có quyền cao hơn mình.');
    }

    /** Quyền lưu vào nhóm: người thường chỉ cấp được những quyền chính họ đang có. */
    protected function grantablePermissions(GroupRequest $request): array
    {
        $input = Group::normalizePermissionInput($request->input('permission'));

        return $request->user()->isSuperAdmin()
            ? $input
            : array_values(array_intersect($input, $request->user()->permissionKeys()));
    }
}
