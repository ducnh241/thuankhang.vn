<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\StoreUserRequest;
use App\Http\Requests\Backend\UpdateUserRequest;
use App\Models\Group;
use App\Models\User;
use App\Support\AvatarUploader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserController extends Controller
{
    /** Các kiểu sắp xếp cho phép: key => [cột, chiều] */
    private const SORTS = [
        'date:desc' => ['id', 'desc'],
        'date:asc' => ['id', 'asc'],
        'name:asc' => ['name', 'asc'],
        'name:desc' => ['name', 'desc'],
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $q = $this->queryString($request, 'q');
        $status = in_array($request->query('status'), ['active', 'locked', 'root'], true) ? $request->query('status') : 'all';
        $sort = array_key_exists($this->queryString($request, 'sort'), self::SORTS) ? $this->queryString($request, 'sort') : 'date:desc';
        [$column, $direction] = self::SORTS[$sort];
        $groups = Group::orderBy('name')->get();
        // Lọc nhóm: id nhóm có thật hoặc "none" (chưa có nhóm)
        $group = $this->queryString($request, 'group');
        $group = $group === 'none' || (ctype_digit($group) && $groups->contains('id', (int) $group)) ? $group : '';

        // Escape wildcard của LIKE ("_", "%") để tìm đúng nghĩa đen; ESCAPE '!' chạy được cả MySQL lẫn SQLite
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';

        $base = User::query()->visible()
            ->when($q !== '', fn ($query) => $query->where(function ($query) use ($like) {
                foreach (['name', 'email', 'phone'] as $column) {
                    $query->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }
            }))
            ->when($group === 'none', fn ($query) => $query->whereNull('group_id'))
            ->when($group !== '' && $group !== 'none', fn ($query) => $query->where('group_id', (int) $group));

        // Số lượng cho từng tab trạng thái (tính trên kết quả tìm kiếm + lọc nhóm)
        $counts = [
            'all' => (clone $base)->count(),
            'active' => (clone $base)->where('status', true)->count(),
            'locked' => (clone $base)->where('status', false)->count(),
            'root' => (clone $base)->where('is_super_admin', true)->count(),
        ];

        $users = (clone $base)
            ->with('group')
            ->when($status === 'active', fn ($query) => $query->where('status', true))
            ->when($status === 'locked', fn ($query) => $query->where('status', false))
            ->when($status === 'root', fn ($query) => $query->where('is_super_admin', true))
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate(20)
            ->withQueryString();

        // Trang vượt quá số trang (vd vừa xóa người cuối của trang cuối) → về trang cuối
        if ($users->isEmpty() && $users->currentPage() > 1) {
            return redirect($users->url($users->lastPage()));
        }

        return view('backend.users.index', compact('users', 'q', 'status', 'sort', 'counts', 'groups', 'group'));
    }

    public function create(): View
    {
        return view('backend.users.form', [
            'user' => new User(['status' => true]),
            'groups' => $this->assignableGroups(),
        ]);
    }

    public function store(StoreUserRequest $request, AvatarUploader $avatars): RedirectResponse
    {
        $data = $request->validated();
        $data['is_super_admin'] = $this->resolveSuperAdminFlag($request, null);

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $avatars->store($request->file('avatar'));
        }

        $user = User::create($data);

        return redirect()->route('backend.users.edit', $user)->with('success', 'Đã tạo người dùng.');
    }

    public function edit(User $user): View
    {
        $this->ensureCanManage($user);

        return view('backend.users.form', [
            'user' => $user,
            'groups' => $this->assignableGroups(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user, AvatarUploader $avatars): RedirectResponse
    {
        $this->ensureCanManage($user);

        $data = $request->validated();
        $data['is_super_admin'] = $this->resolveSuperAdminFlag($request, $user);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        // Không tự khoá chính mình
        if ($user->is($request->user())) {
            $data['status'] = true;
        }

        if ($request->boolean('remove_avatar')) {
            $avatars->delete($user->avatar);
            $data['avatar'] = null;
        }

        if ($request->hasFile('avatar')) {
            $avatars->delete($user->avatar);
            $data['avatar'] = $avatars->store($request->file('avatar'));
        }

        $user->update($data);

        return redirect()->route('backend.users.edit', $user)->with('success', 'Đã cập nhật người dùng.');
    }

    public function destroy(Request $request, User $user, AvatarUploader $avatars): RedirectResponse
    {
        $this->ensureCanManage($user);

        if ($user->is($request->user())) {
            return back()->with('error', 'Không thể xóa chính mình.');
        }

        if ($user->isLastSuperAdmin()) {
            return back()->with('error', 'Không thể xóa Root admin cuối cùng.');
        }

        $avatars->delete($user->avatar);
        $user->delete();

        // Xóa từ trang sửa của chính người này: trang đó không còn → về danh sách; xóa từ danh sách thì giữ trang / bộ lọc
        $back = url()->previous();
        $toList = $back === route('backend.users.edit', $user) || ! str_starts_with($back, route('backend.users.index'));

        return ($toList ? redirect()->route('backend.users.index') : back())->with('success', "Đã xóa người dùng {$user->name}.");
    }

    /** Các nhóm user hiện tại được phép gán (không mạnh hơn quyền của mình). */
    protected function assignableGroups()
    {
        return Group::orderBy('name')->get()->filter(fn (Group $g) => auth()->user()->canAssignGroup($g))->values();
    }

    /** Không quản lý được Root admin (nếu mình không phải Root) hoặc người có quyền vượt mình. */
    protected function ensureCanManage(User $target): void
    {
        abort_unless(auth()->user()->canManage($target), 403, 'Bạn không được quản lý tài khoản có quyền cao hơn mình.');
    }

    /**
     * Cờ Root admin: chỉ Root admin được đặt; không tự bỏ cờ của mình;
     * không bỏ cờ của Root admin cuối cùng.
     */
    protected function resolveSuperAdminFlag(Request $request, ?User $target): bool
    {
        $actor = $request->user();

        if (! $actor->isSuperAdmin()) {
            return $target?->is_super_admin ?? false;
        }

        if ($target && ($target->is($actor) || $target->isLastSuperAdmin())) {
            return true;
        }

        return $request->boolean('is_super_admin');
    }

}
