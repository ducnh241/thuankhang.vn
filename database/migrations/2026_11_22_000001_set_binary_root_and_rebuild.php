<?php

use App\Services\BinaryTree;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chuyển dữ liệu thật sang cây nhị phân:
 *   1. Đặt ông tổng là thành viên "Nguyen văn trung" (mã giới thiệu U4SNA69) — nếu thành viên này tồn tại và chưa có cấu
 *      hình ông tổng (Tham số chung → root_member_code). Tài khoản Nguyễn Hữu Đức (3VBJ366) chỉ là tài khoản thử: không có
 *      người giới thiệu nên là gốc của một cây riêng, nằm ngoài cây của ông tổng.
 *   2. Xóa mọi vị trí đã xếp (nếu có) và xếp lại toàn bộ thành viên theo thứ tự đăng ký, đúng quy tắc của BinaryTree.
 * Chạy một lần khi triển khai, TRƯỚC khi cây được dùng thật (vị trí từ đó là cố định).
 */
return new class extends Migration
{
    private const ROOT_CODE = 'U4SNA69';

    private const SETTING = 'aff_root_member_code';

    public function up(): void
    {
        $configured = DB::table('settings')->where('key', self::SETTING)->whereNotNull('value')->where('value', '!=', '')->exists();
        if (! $configured && DB::table('members')->where('referral_code', self::ROOT_CODE)->whereNull('referrer_id')->exists()) {
            DB::table('settings')->updateOrInsert(['key' => self::SETTING], ['value' => self::ROOT_CODE, 'created_at' => now(), 'updated_at' => now()]);
        }

        BinaryTree::rebuild();
    }

    public function down(): void
    {
        // Không hoàn tác: vị trí cũ (xếp theo ông tổng sai) không cần giữ lại.
    }
};
