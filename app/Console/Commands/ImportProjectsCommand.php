<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chép dữ liệu module dự án từ DB cũ (connection "legacy", chỉ SELECT) sang DB hiện tại.
 * Giữ nguyên id để quan hệ không lệch; chạy lại được nhiều lần (upsert theo khoá chính).
 */
#[Signature('import:projects {--fresh : Xóa sạch dữ liệu module dự án ở DB hiện tại trước khi import} {--force : Không hỏi lại khi DB đích đã có dữ liệu}')]
#[Description('Import danh mục dự án, khách hàng, dự án, tag, hosting, thanh toán, lịch sử thay đổi từ DB cũ')]
class ImportProjectsCommand extends Command
{
    /** Thứ tự import (bảng cha trước) => khoá chính dùng để upsert */
    private const TABLES = [
        'categories' => ['id'],
        'customers' => ['id'],
        'tags' => ['id'],
        'hostings' => ['id'],
        'projects' => ['id'],
        'project_tag' => ['project_id', 'tag_id'],
        'hosting_project' => ['hosting_id', 'project_id'],
        'payments' => ['id'],
        'project_audit_logs' => ['id'],
    ];

    /** categories là bảng dùng chung (bài viết, store…): chỉ chép danh mục dự án */
    private const PROJECT_CATEGORY_TYPE = 1;

    public function handle(): int
    {
        $source = DB::connection('legacy');
        $target = DB::connection();

        if ($source->getDatabaseName() === $target->getDatabaseName()) {
            $this->error('DB nguồn và đích trùng nhau ('.$target->getDatabaseName().'), dừng lại.');

            return self::FAILURE;
        }

        $this->info("Nguồn: {$source->getDatabaseName()} (chỉ đọc) → Đích: {$target->getDatabaseName()}");

        // DB đích đã có dữ liệu: import lại sẽ GHI ĐÈ những gì đã sửa trên trang mới bằng dữ liệu cũ
        $existing = $target->table('projects')->count();
        if ($existing > 0 && ! $this->option('force')) {
            $what = $this->option('fresh')
                ? 'XÓA SẠCH rồi chép lại'
                : 'ghi đè các bản ghi trùng id bằng dữ liệu cũ — thay đổi làm trên trang mới sẽ mất, và nếu cả hai bên đều tạo bản ghi mới thì một id có thể là hai dự án khác nhau';
            if (! $this->confirm("DB đích đang có {$existing} dự án. Tiếp tục sẽ {$what}. Chắc chắn?", false)) {
                $this->warn('Đã hủy. Dùng --force để bỏ qua câu hỏi này.');

                return self::FAILURE;
            }
        }

        Schema::disableForeignKeyConstraints();

        try {
            // Một giao dịch: lỗi giữa chừng thì DB đích quay về như trước (dùng DELETE vì TRUNCATE tự commit)
            $rows = $target->transaction(function () use ($target) {
                if ($this->option('fresh')) {
                    foreach (array_reverse(array_keys(self::TABLES)) as $table) {
                        $target->table($table)->when($table === 'categories', fn ($q) => $q->where('type', self::PROJECT_CATEGORY_TYPE))->delete();
                    }
                }

                $rows = [];
                foreach (self::TABLES as $table => $keys) {
                    $rows[$table] = $this->copy($table, $keys);
                }

                // Mẫu thông báo gia hạn (bảng settings cũ: skey/svalue)
                $templates = DB::connection('legacy')->table('settings')
                    ->whereIn('skey', ['renewal_template_hosting', 'renewal_template_full'])->pluck('svalue', 'skey');
                foreach ($templates as $key => $value) {
                    $target->table('settings')->updateOrInsert(['key' => $key], ['value' => $value, 'updated_at' => now(), 'created_at' => now()]);
                }
                $this->line('  settings (mẫu gia hạn): '.$templates->count());

                return $rows;
            });
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        // Khách hàng cũ mang ngày tạo = ngày nhập dữ liệu → đổi thành ngày bắt đầu dự án đầu tiên
        $this->call('customers:fix-created-at');

        $this->newLine();
        $this->table(['Bảng', 'Nguồn', 'Đã chép', 'Đích hiện có'], collect($rows)->map(fn ($n, $table) => [
            $table,
            $source->table($table)->when($table === 'categories', fn ($q) => $q->where('type', self::PROJECT_CATEGORY_TYPE))->count(),
            $n,
            $target->table($table)->when($table === 'categories', fn ($q) => $q->where('type', self::PROJECT_CATEGORY_TYPE))->count(),
        ])->values()->all());

        return self::SUCCESS;
    }

    /** Chép một bảng theo lô 500 dòng, chỉ những cột có ở cả hai bên. */
    private function copy(string $table, array $keys): int
    {
        $source = DB::connection('legacy');
        $columns = array_values(array_intersect(
            Schema::connection('legacy')->getColumnListing($table),
            Schema::getColumnListing($table),
        ));
        $update = array_values(array_diff($columns, $keys));
        $count = 0;

        $source->table($table)->select($columns)
            ->when($table === 'categories', fn ($q) => $q->where('type', self::PROJECT_CATEGORY_TYPE))
            ->orderBy($keys[0])->orderBy(end($keys))
            ->chunk(500, function ($chunk) use ($table, $keys, $update, &$count) {
                $data = $chunk->map(fn ($row) => $this->transform($table, (array) $row))->all();
                DB::table($table)->upsert($data, $keys, $update ?: $keys);
                $count += count($data);
            });

        $this->line("  {$table}: {$count}");

        return $count;
    }

    /** Khác biệt giữa schema cũ và mới. */
    private function transform(string $table, array $row): array
    {
        if ($table === 'categories') {
            // Bản cũ dùng 0 cho "không có cha", bản mới dùng NULL (khoá ngoại)
            $row['parent_id'] = (int) ($row['parent_id'] ?? 0) ?: null;
            $row['status'] = (bool) ($row['status'] ?? true);
            $row['priority'] = (int) ($row['priority'] ?? 0);
        }

        return $row;
    }
}
