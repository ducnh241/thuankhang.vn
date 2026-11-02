<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created' => 'Tạo mới',
        'updated' => 'Cập nhật',
        'deleted' => 'Xóa',
        'restored' => 'Khôi phục',
    ];

    protected $fillable = ['auditable_type', 'auditable_id', 'project_id', 'action', 'changes', 'user_id', 'user_name', 'ip_address', 'created_at'];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Người thực hiện để hiện ra giao diện: tài khoản ẩn và việc chạy tự động đều ghi "Hệ thống". */
    public function actorName(): string
    {
        if (in_array((int) $this->user_id, User::hiddenIds(), true) || in_array($this->user_name, [null, '', 'system'], true)) {
            return 'Hệ thống';
        }

        return $this->user_name;
    }

    public function actionLabel(): string
    {
        return self::ACTIONS[$this->action] ?? $this->action;
    }

    /** Loại đối tượng bị thay đổi, hiển thị ngắn gọn. */
    public function subjectLabel(): string
    {
        return match ($this->auditable_type) {
            Project::class => 'Dự án',
            Payment::class => 'Thanh toán',
            Customer::class => 'Khách hàng',
            Hosting::class => 'Hosting',
            default => class_basename($this->auditable_type),
        };
    }
}
