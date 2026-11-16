<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cây nhị phân của thành viên (song song với cây giới thiệu referrer_id): parent_id = người đứng ngay trên trong cây nhị
 * phân, position = nhánh trái (L) / phải (R) của người đó. Mỗi người có tối đa một con ở mỗi nhánh. Ông tổng (thành viên
 * đầu tiên) không có parent. Vị trí gán một lần khi đăng ký, không đổi về sau.
 * Thành viên đã có từ trước: chạy `php artisan binary:place-members` để xếp theo thứ tự ngày đăng ký.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('referrer_id')->constrained('members')->restrictOnDelete()
                ->comment('Người đứng ngay trên trong cây nhị phân');
            $table->char('position', 1)->nullable()->after('parent_id')->comment('L = nhánh trái, R = nhánh phải của parent_id');
            $table->timestamp('placed_at')->nullable()->after('position')->comment('Thời điểm xếp vào cây nhị phân');
            $table->unique(['parent_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropUnique(['parent_id', 'position']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['position', 'placed_at']);
        });
    }
};
