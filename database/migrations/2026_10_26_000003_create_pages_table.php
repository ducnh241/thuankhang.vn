<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trang nội dung tĩnh của website (Giới thiệu, Liên hệ, Chính sách…), đường dẫn /page/{slug}. DB cũ có bảng pages nhưng
 * không có dòng nào nên không import. menu_items.page_id (có sẵn, chưa có khóa ngoại) giờ trỏ tới bảng này: xóa trang
 * thì mục menu mất link (ứng dụng chặn xóa trang đang dùng trong menu, khóa ngoại chỉ để chắc chắn).
 *
 * Chạy lại được: trên live (MariaDB) lần chạy đầu đã tạo bảng pages bằng engine mặc định của server rồi lỗi ở bước khóa
 * ngoại ("errno: 150 Foreign key constraint is incorrectly formed" — menu_items là InnoDB, pages không phải). Nên: chỉ
 * tạo bảng khi chưa có; bảng còn sót lại thì chuyển sang InnoDB và thêm chỉ mục còn thiếu (MyISAM giới hạn khóa 1000
 * byte nên chỉ mục slug có thể chưa tạo được); khóa ngoại chỉ thêm khi chưa có.
 */
return new class extends Migration
{
    public function up(): void
    {
        $leftover = Schema::hasTable('pages');
        if (! $leftover) {
            Schema::create('pages', function (Blueprint $table) {
                $table->engine = 'InnoDB';
                $table->id();
                $table->string('title', 255);
                $table->string('slug', 255)->unique()->comment('Đường dẫn /page/{slug}');
                $table->text('excerpt')->nullable()->comment('Mô tả ngắn');
                $table->longText('content')->nullable()->comment('HTML từ trình soạn thảo');
                $table->string('image', 500)->nullable()->comment('Ảnh đại diện trên disk public (pages/…)');
                $table->boolean('status')->default(true)->index()->comment('Hiển thị trên website');
                $table->string('meta_title', 255)->nullable();
                $table->string('meta_description', 500)->nullable();
                $table->timestamps();
            });
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $engine = DB::scalar('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', ['pages']);
            if (strcasecmp((string) $engine, 'InnoDB') !== 0) {
                DB::statement('ALTER TABLE `pages` ENGINE = InnoDB');
            }
        }

        if ($leftover) {
            $indexes = collect(Schema::getIndexes('pages'));
            $has = fn (string $column, bool $unique) => $indexes->contains(fn (array $i) => $i['columns'] === [$column] && (! $unique || $i['unique']));
            Schema::table('pages', function (Blueprint $table) use ($has) {
                if (! $has('slug', true)) {
                    $table->unique('slug');
                }
                if (! $has('status', false)) {
                    $table->index('status');
                }
            });
        }

        $hasForeignKey = collect(Schema::getForeignKeys('menu_items'))->contains(fn (array $key) => $key['columns'] === ['page_id']);
        if (! $hasForeignKey) {
            Schema::table('menu_items', function (Blueprint $table) {
                $table->foreign('page_id')->references('id')->on('pages')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropForeign(['page_id']);
        });
        Schema::dropIfExists('pages');
    }
};
