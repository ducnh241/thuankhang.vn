<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

#[Fillable(['name', 'email', 'phone', 'avatar', 'password', 'is_super_admin', 'status', 'group_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** Giá trị mặc định khi tạo model mới (khớp default của migration). */
    protected $attributes = [
        'is_super_admin' => false,
        'status' => true,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'status' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** Root admin: có toàn bộ quyền, không cần nhóm. */
    public function isSuperAdmin(): bool
    {
        return (bool) $this->is_super_admin;
    }

    /** Id các tài khoản ẩn (config/backend.php 'hidden_users'). */
    public static function hiddenIds(): array
    {
        return config('backend.hidden_users', []);
    }

    /** Tài khoản ẩn: không hiện, không ai quản lý được trong trang quản trị. */
    public function isHidden(): bool
    {
        return in_array((int) $this->getKey(), static::hiddenIds(), true);
    }

    /** Chỉ những tài khoản được hiện trong trang quản trị (bỏ tài khoản ẩn). */
    public function scopeVisible(Builder $query): void
    {
        $query->whereNotIn($this->qualifyColumn('id'), static::hiddenIds());
    }

    /**
     * Còn Root admin nào khác (đang hiện) ngoài user này không — để không bao giờ mất hết Root admin.
     * Không tính tài khoản ẩn: có nó hay không thì giao diện vẫn y như nhau.
     */
    public function isLastSuperAdmin(): bool
    {
        return $this->isSuperAdmin()
            && ! static::visible()->where('is_super_admin', true)->whereKeyNot($this->getKey())->exists();
    }

    public function isActive(): bool
    {
        return $this->status;
    }

    /** Kiểm tra quyền theo group. Root admin luôn có mọi quyền. */
    public function hasPermission(string $key): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return $this->group?->hasPermission($key) ?? false;
    }

    /** Toàn bộ key quyền user đang có. Root admin = mọi key trong config/permission.php. */
    public function permissionKeys(): array
    {
        return $this->isSuperAdmin() ? Group::allPermissionKeys() : ($this->group?->permission_data ?? []);
    }

    /** User có đủ mọi quyền trong danh sách không (dùng để chặn leo thang quyền). */
    public function coversPermissions(array $keys): bool
    {
        return $this->isSuperAdmin() || array_diff($keys, $this->permissionKeys()) === [];
    }

    /**
     * Được quản lý (sửa, đổi mật khẩu, xóa) user khác không:
     * Root quản lý tất cả; người thường không đụng được Root và người có quyền vượt mình.
     */
    public function canManage(User $target): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return ! $target->isSuperAdmin() && $this->coversPermissions($target->permissionKeys());
    }

    /** Được gán nhóm này cho user (hoặc sửa nhóm này) không: nhóm không được mạnh hơn mình. */
    public function canAssignGroup(?Group $group): bool
    {
        return $group === null || $this->coversPermissions($group->permission_data ?? []);
    }

    public function canAccessBackend(): bool
    {
        return $this->isActive() && $this->hasPermission('backend_access');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url($this->avatar) : null;
    }

    /** Chữ cái đầu của 2 từ đầu tên (như template: "Nguyễn Đức" → "NĐ"), dùng cho avatar khi chưa có ảnh. */
    public function initials(): string
    {
        $words = array_slice(preg_split('/\s+/u', trim((string) $this->name), -1, PREG_SPLIT_NO_EMPTY), 0, 2);

        return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), $words))) ?: '?';
    }
}
