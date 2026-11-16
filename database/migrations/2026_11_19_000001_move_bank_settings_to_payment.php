<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tài khoản nhận tiền chuyển từ Tham số chung của affiliate (khóa aff_bank_*) sang tab "Thanh toán" của Cài đặt (khóa
 * pay_*): đổi tên khóa trong bảng settings, giữ nguyên giá trị đang lưu.
 */
return new class extends Migration
{
    private const KEYS = [
        'aff_bank_code' => 'pay_bank_code',
        'aff_bank_account' => 'pay_bank_account',
        'aff_bank_account_name' => 'pay_account_name',
    ];

    public function up(): void
    {
        foreach (self::KEYS as $old => $new) {
            if (! DB::table('settings')->where('key', $new)->exists()) {
                DB::table('settings')->where('key', $old)->update(['key' => $new]);
            }
            DB::table('settings')->where('key', $old)->delete();
        }
    }

    public function down(): void
    {
        foreach (self::KEYS as $old => $new) {
            DB::table('settings')->where('key', $new)->update(['key' => $old]);
        }
    }
};
