<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Khách hàng nhập từ hệ thống cũ đều mang ngày tạo là ngày nhập dữ liệu (11/05/2026), không phải ngày có khách.
 * Đổi ngày tạo của họ thành ngày bắt đầu dự án đầu tiên (kể cả dự án đã xóa — vẫn là lịch sử của khách).
 * Khách không có dự án nào có ngày bắt đầu: giữ nguyên. Ghi thẳng bằng query: không đổi updated_at, không ghi lịch sử.
 * Chạy lại được nhiều lần; import:projects gọi lệnh này sau khi chép dữ liệu.
 */
#[Signature('customers:fix-created-at {--date=2026-05-11 : Ngày nhập dữ liệu cũ (Y-m-d) — chỉ sửa khách có ngày tạo đúng ngày này} {--dry-run : Chỉ liệt kê, không ghi}')]
#[Description('Đổi ngày tạo khách hàng nhập từ hệ thống cũ thành ngày bắt đầu dự án đầu tiên')]
class FixCustomerCreatedAtCommand extends Command
{
    public function handle(): int
    {
        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', (string) $this->option('date'));
        } catch (\Throwable) {
            $this->error('--date phải có dạng Y-m-d.');

            return self::FAILURE;
        }

        // Ngày bắt đầu dự án sớm nhất của từng khách (kể cả dự án đã xóa)
        $firstStart = DB::table('projects')->whereNotNull('start_date')->whereNotNull('customer_id')
            ->groupBy('customer_id')->pluck(DB::raw('MIN(start_date)'), 'customer_id');

        $customers = DB::table('customers')
            ->whereBetween('created_at', [$day->startOfDay()->toDateTimeString(), $day->endOfDay()->toDateTimeString()])
            ->orderBy('id')->get(['id', 'name', 'created_at', 'deleted_at']);

        $updated = 0;
        $kept = [];
        foreach ($customers as $customer) {
            $start = $firstStart[$customer->id] ?? null;
            if (! $start) {
                $kept[] = $customer;
                continue;
            }
            $createdAt = CarbonImmutable::parse($start)->startOfDay()->toDateTimeString();
            if (! $this->option('dry-run')) {
                DB::table('customers')->where('id', $customer->id)->update(['created_at' => $createdAt]);
            }
            $updated++;
        }

        $verb = $this->option('dry-run') ? 'Sẽ đổi' : 'Đã đổi';
        $this->info("{$verb} ngày tạo {$updated}/{$customers->count()} khách hàng có ngày tạo {$day->format('d/m/Y')} thành ngày bắt đầu dự án đầu tiên.");
        $active = array_filter($kept, fn ($c) => $c->deleted_at === null);
        if ($kept) {
            $this->line('Giữ nguyên '.count($kept).' khách không có dự án nào có ngày bắt đầu'
                .($active ? ' (đang dùng: '.implode(', ', array_map(fn ($c) => "#{$c->id} {$c->name}", $active)).')' : '').'.');
        }

        return self::SUCCESS;
    }
}
