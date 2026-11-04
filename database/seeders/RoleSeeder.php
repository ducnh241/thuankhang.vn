<?php

namespace Database\Seeders;

use App\Models\Group;
use Illuminate\Database\Seeder;

/**
 * 4 vai trò mặc định của Affiliate Phase 1 (đặc tả mục 6.1 / Step 0) dưới dạng Nhóm quyền.
 * Chỉ tạo nhóm chưa có: chạy lại không ghi đè quyền admin đã chỉnh trong trang Nhóm quyền.
 */
class RoleSeeder extends Seeder
{
    public const ROLES = [
        // A2–A5, A14 + mọi màn hình khác. Quyền khai báo thêm về sau phải tick thêm cho nhóm này (hoặc dùng Root admin).
        'Super admin' => '*',

        // A6 Sản phẩm, A7 VP tỉnh, A8 Đơn hàng, A10 Thành viên, A11 KYC
        'Vận hành' => [
            'backend_access',
            'product', 'product/add', 'product/edit', 'product/delete',
            'pickup_point', 'pickup_point/add', 'pickup_point/edit', 'pickup_point/delete',
            'order', 'order/status', 'order/cancel',
            'member', 'member/lock', 'member/activate', 'member/referrer',
            'kyc', 'kyc/view_document', 'kyc/review',
        ],

        // A9 Giao dịch ngân hàng (chỉ Kế toán được gán tay — mục 9.2), A12 Sổ hoa hồng, A13 Rút tiền; xem đơn để đối soát
        'Kế toán' => [
            'backend_access',
            'order',
            'bank_transaction', 'bank_transaction/match',
            'commission', 'commission/export',
            'withdrawal', 'withdrawal/approve', 'withdrawal/paid', 'withdrawal/export',
        ],

        // A8 Đơn hàng, A10 Thành viên (xem, khoá / mở; sửa người giới thiệu để Vận hành)
        'CSKH' => [
            'backend_access',
            'order', 'order/status', 'order/cancel',
            'member', 'member/lock',
        ],
    ];

    public function run(): void
    {
        $all = Group::allPermissionKeys();

        foreach (self::ROLES as $name => $keys) {
            Group::firstOrCreate(['name' => $name], [
                'permission_data' => $keys === '*' ? $all : array_values(array_intersect($all, $keys)),
            ]);
        }
    }
}
