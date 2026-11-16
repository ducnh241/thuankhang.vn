<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\BinaryTree;
use Illuminate\Console\Command;

/**
 * Xếp thành viên vào cây nhị phân theo thứ tự ngày đăng ký (dùng một lần sau khi triển khai cho thành viên đã có từ trước;
 * thành viên mới tự được xếp khi đăng ký). --rebuild: xóa hết vị trí rồi xếp lại từ đầu — CHỈ dùng trước khi vận hành
 * thật hoặc khi đổi quy tắc, vì vị trí vốn là cố định.
 */
class PlaceBinaryMembers extends Command
{
    protected $signature = 'binary:place-members {--rebuild : Xóa toàn bộ vị trí rồi xếp lại từ đầu}';

    protected $description = 'Xếp thành viên chưa có chỗ vào cây nhị phân theo thứ tự đăng ký';

    public function handle(): int
    {
        if ($this->option('rebuild') && ! $this->confirm('Xóa toàn bộ vị trí trong cây nhị phân và xếp lại từ đầu?', false)) {
            return self::SUCCESS;
        }
        $count = $this->option('rebuild') ? BinaryTree::rebuild() : BinaryTree::placeUnplaced();
        $root = BinaryTree::root();
        $this->info($count ? "Đã xếp {$count} thành viên vào cây nhị phân." : 'Không có thành viên nào cần xếp.');
        if ($root) {
            $this->line("Ông tổng: {$root->full_name} ({$root->referral_code}). Tổng thành viên: ".Member::count().'.');
        }

        return self::SUCCESS;
    }
}
