<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nhánh thành viên TỰ CHỌN lúc đăng ký (18/11/2026): members.sponsor_side = L (hiện "Nhánh A") | R (hiện "Nhánh B") — nhánh
 * của người giới thiệu mà thành viên muốn vào. NULL = thành viên cũ (đăng ký trước khi có lựa chọn, đã được xếp tự động)
 * hoặc không có người giới thiệu. Chỉ thêm cột; không đổi vị trí của ai đã xếp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->char('sponsor_side', 1)->nullable()->after('position')->comment('Nhánh của người giới thiệu do thành viên chọn lúc đăng ký: L = Nhánh A, R = Nhánh B');
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn('sponsor_side'));
    }
};
