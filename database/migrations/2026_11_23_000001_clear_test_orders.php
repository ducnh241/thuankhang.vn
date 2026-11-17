<?php

use Illuminate\Database\Migrations\Migration;

/**
 * ĐÃ VÔ HIỆU HÓA (17/11/2026) — migration này KHÔNG làm gì cả.
 *
 * Bản đầu của file từng xóa sạch toàn bộ đơn hàng (để bỏ đơn thử trước khi nhận đơn thật). Hệ thống live giờ đã có đơn
 * thật nên việc xóa đơn bị cấm hẳn: nội dung đã được gỡ, chỉ giữ lại file rỗng để `php artisan migrate` trên mọi môi
 * trường (kể cả nơi chưa từng chạy migration này) ghi nhận tên migration mà không đụng tới dữ liệu.
 *
 * Không khôi phục lại logic xóa đơn vào đây. Đơn đã thanh toán trước khi có engine hoa hồng được tính bù bằng lệnh
 * `php artisan commissions:backfill` (không xóa, không sửa đơn).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Cố ý để trống — xem ghi chú ở trên.
    }

    public function down(): void
    {
        // Không có gì để hoàn tác.
    }
};
