<?php

use App\Models\AuditLog;
use App\Models\Group;
use Illuminate\Database\Migrations\Migration;

/**
 * Đồng chia (25/11/2026): ngoài Kế toán (xem + duyệt), Giám đốc và Quản lý phòng ban được XEM bảng chia, không được duyệt.
 * Tạo hai nhóm quyền nếu chưa có (chỉ đăng nhập quản trị + xem đồng chia); nhóm đã có thì chỉ thêm quyền xem. Không bao giờ
 * thêm quyền duyệt ở đây — duyệt (cộng ví) chỉ dành cho nhóm được tick "Duyệt bảng chia" trong trang Nhóm quyền.
 */
return new class extends Migration
{
    public const VIEWERS = ['Giám đốc', 'Quản lý phòng ban'];

    public function up(): void
    {
        // Cài mới (chưa có nhóm quyền nào): RoleSeeder tạo đủ các vai trò, kể cả hai nhóm này
        if (! Group::exists()) {
            return;
        }
        foreach (self::VIEWERS as $name) {
            $group = Group::firstWhere('name', $name);
            if (! $group) {
                $group = Group::create(['name' => $name, 'permission_data' => ['backend_access', 'profit_share']]);
                AuditLog::record('created', $group, null, "Tạo nhóm quyền {$name}: xem bảng đồng chia (không duyệt)");

                continue;
            }
            $keys = $group->permission_data ?? [];
            if (! in_array('profit_share', $keys, true)) {
                $group->forceFill(['permission_data' => array_values(array_unique([...$keys, 'backend_access', 'profit_share']))])->saveQuietly();
                AuditLog::record('updated', $group, null, "Thêm quyền xem bảng đồng chia cho nhóm {$name} (không duyệt)");
            }
        }
    }

    public function down(): void
    {
        // Không xóa nhóm: có thể đã gán người dùng
    }
};
