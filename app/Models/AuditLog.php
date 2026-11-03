<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;
use LogicException;

/**
 * Nhật ký hệ thống (bảng audit_logs): mọi thao tác trong trang quản trị + đăng nhập / đăng xuất.
 * Chỉ ghi thêm: không sửa, không xóa được qua model.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Tạo mới',
        'updated' => 'Cập nhật',
        'deleted' => 'Xóa',
        'restored' => 'Khôi phục',
        'bulk_deleted' => 'Xóa hàng loạt',
        'login' => 'Đăng nhập',
        'logout' => 'Đăng xuất',
        'login_failed' => 'Đăng nhập sai',
    ];

    /** Loại đối tượng hiển thị trên màn hình nhật ký (class => tên). Class chưa khai báo hiện tên class rút gọn. */
    public const SUBJECTS = [
        User::class => 'Người dùng',
        Group::class => 'Nhóm quyền',
        Setting::class => 'Cài đặt',
        Category::class => 'Danh mục',
        Page::class => 'Trang',
        Menu::class => 'Menu',
        MenuItem::class => 'Mục menu',
    ];

    protected $fillable = [
        'user_id', 'user_name', 'action', 'auditable_type', 'auditable_id', 'description', 'changes',
        'ip_address', 'user_agent', 'url', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Nhật ký hệ thống chỉ được ghi thêm, không được sửa.'));
        static::deleting(fn () => throw new LogicException('Nhật ký hệ thống chỉ được ghi thêm, không được xóa.'));
    }

    /**
     * Ghi một dòng nhật ký. $subject: model bị tác động, hoặc tên class khi tác động nhiều dòng cùng lúc.
     * $actor mặc định là user đang đăng nhập; chạy console / job thì ghi "system".
     */
    public static function record(string $action, Model|string|null $subject = null, ?array $changes = null, ?string $description = null, ?User $actor = null): self
    {
        $actor ??= Auth::user();
        $http = ! app()->runningInConsole();

        return static::create([
            'user_id' => $actor?->getKey(),
            'user_name' => $actor?->name ?? 'system',
            'action' => $action,
            'auditable_type' => is_string($subject) ? $subject : ($subject ? $subject::class : null),
            'auditable_id' => $subject instanceof Model ? $subject->getKey() : null,
            'description' => $description !== null ? Str::limit($description, 497) : null,
            'changes' => $changes,
            'ip_address' => $http ? Request::ip() : null,
            'user_agent' => $http ? Str::limit((string) Request::userAgent(), 497) : null,
            'url' => $http ? Str::limit(Request::method().' '.Request::path(), 497) : null,
            'created_at' => now(),
        ]);
    }

    /** Ghi log cho thao tác xóa hàng loạt bằng query (không qua event của model). */
    public static function recordBulkDelete(string $type, array $ids, ?string $description = null): ?self
    {
        $ids = array_values(array_map('intval', $ids));

        return $ids === [] ? null : static::record('bulk_deleted', $type, ['old' => ['ids' => $ids], 'new' => null], $description);
    }

    /** Người thực hiện để hiện ra giao diện: tài khoản ẩn và việc chạy tự động đều ghi "Hệ thống". */
    public function actorName(): string
    {
        if ($this->action === 'login_failed') {
            return 'Khách';
        }

        if (in_array((int) $this->user_id, User::hiddenIds(), true) || in_array($this->user_name, [null, '', 'system'], true)) {
            return 'Hệ thống';
        }

        return $this->user_name;
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    public function subjectLabel(): ?string
    {
        if (! $this->auditable_type) {
            return null;
        }

        return self::SUBJECTS[$this->auditable_type] ?? class_basename($this->auditable_type);
    }
}
