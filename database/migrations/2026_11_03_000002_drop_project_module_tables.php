<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gỡ các module của ProCMS cũ không dùng cho site affiliate: dự án, tag, nhắc gia hạn, hosting, khách hàng, thanh toán
 * (+ báo cáo doanh thu dựa trên thanh toán). Migration tạo bảng gốc đã xóa, nên cài mới thì các lệnh dưới không làm gì.
 * Không khôi phục được (down rỗng): sao lưu DB trước khi chạy trên môi trường có dữ liệu.
 */
return new class extends Migration
{
    private const TABLES = ['project_audit_logs', 'payments', 'hosting_project', 'project_domains', 'project_tag', 'hostings', 'projects', 'tags', 'customers'];

    /** Module quyền đã gỡ khỏi config/permission.php (cả quyền con module/…). */
    private const PERMISSIONS = ['project', 'customer', 'payment', 'report', 'tag', 'hosting', 'renewal', 'project_category'];

    public function up(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();

        // Danh mục dự án (categories.type = 1): con trước cha nhờ parent_id nullOnDelete
        DB::table('categories')->where('type', 1)->delete();

        DB::table('settings')->whereIn('key', ['renewal_template_hosting', 'renewal_template_full'])->delete();

        // Bỏ key quyền đã gỡ khỏi nhóm (key thừa làm sai phép so "quyền không vượt mình" khi quản lý user / nhóm)
        foreach (DB::table('groups')->get(['id', 'permission_data']) as $group) {
            $keys = json_decode((string) $group->permission_data, true) ?? [];
            $kept = array_values(array_filter($keys, fn ($key) => ! in_array(explode('/', (string) $key)[0], self::PERMISSIONS, true)));
            if ($kept !== $keys) {
                DB::table('groups')->where('id', $group->id)->update(['permission_data' => json_encode($kept)]);
            }
        }
    }

    public function down(): void
    {
        // Không khôi phục: code của các module đã gỡ
    }
};
