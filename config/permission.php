<?php

/*
|--------------------------------------------------------------------------
| Cây quyền backend
|--------------------------------------------------------------------------
|
| Mỗi module: 'label', (tuỳ chọn) 'items' là các quyền con, 'section' là nhóm dòng trong bảng quyền ở trang
| Nhóm quyền. backend_access (đăng nhập trang quản trị) hiện riêng thành công tắc trên bảng.
| Key quyền được sinh thành Gate: 'post', 'post/add', 'post/edit', ...
| Root admin (is_super_admin) luôn pass mọi Gate (xem AppServiceProvider).
|
*/

return [
    'backend_access' => [
        'label' => 'Truy cập backend',
    ],
    'news_category' => [
        'label' => 'Danh mục tin tức',
        'section' => 'Danh mục',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'page' => [
        'label' => 'Trang',
        'section' => 'Website',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'menu' => [
        'label' => 'Menu website',
        'section' => 'Website',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],

    /*
     | Affiliate Phase 1 (đặc tả mục 6.1, màn hình A2–A14). Khai báo sẵn từ Step 0 để chốt ma trận vai trò
     | (database/seeders/RoleSeeder.php); route / menu của từng màn hình gắn Gate tương ứng ở step triển khai màn hình đó.
     */
    'affiliate_level' => [
        'label' => 'Cấu hình cấp bậc',
        'section' => 'Affiliate – Cấu hình',
        'items' => [
            'edit' => 'Sửa',
        ],
    ],
    'affiliate_commission' => [
        'label' => 'Ma trận hoa hồng',
        'section' => 'Affiliate – Cấu hình',
        'items' => [
            'edit' => 'Sửa',
        ],
    ],
    'affiliate_kpi' => [
        'label' => 'Cấu hình KPI',
        'section' => 'Affiliate – Cấu hình',
        'items' => [
            'edit' => 'Sửa',
        ],
    ],
    'affiliate_setting' => [
        'label' => 'Tham số chung',
        'section' => 'Affiliate – Cấu hình',
        'items' => [
            'edit' => 'Sửa',
        ],
    ],
    'product' => [
        'label' => 'Sản phẩm & danh mục',
        'section' => 'Bán hàng',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'pickup_point' => [
        'label' => 'VP tỉnh / điểm nhận hàng',
        'section' => 'Bán hàng',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'order' => [
        'label' => 'Đơn hàng',
        'section' => 'Bán hàng',
        'items' => [
            'status' => 'Cập nhật Đang xử lý / Hoàn tất',
            'cancel' => 'Huỷ đơn chưa thanh toán',
        ],
    ],
    'member' => [
        'label' => 'Thành viên',
        'section' => 'Thành viên',
        'items' => [
            'lock' => 'Khoá / mở tài khoản',
            'referrer' => 'Sửa người giới thiệu',
        ],
    ],
    'kyc' => [
        'label' => 'KYC',
        'section' => 'Thành viên',
        'items' => [
            'view_document' => 'Xem ảnh CCCD',
            'review' => 'Duyệt / từ chối',
        ],
    ],
    'bank_transaction' => [
        'label' => 'Giao dịch ngân hàng',
        'section' => 'Tài chính',
        'items' => [
            'match' => 'Gán tay vào đơn',
        ],
    ],
    'commission' => [
        'label' => 'Sổ hoa hồng',
        'section' => 'Tài chính',
        'items' => [
            'export' => 'Xuất Excel',
        ],
    ],
    'withdrawal' => [
        'label' => 'Rút tiền',
        'section' => 'Tài chính',
        'items' => [
            'approve' => 'Duyệt / từ chối',
            'paid' => 'Đánh dấu đã chuyển',
            'export' => 'Xuất danh sách chuyển khoản',
        ],
    ],

    'user' => [
        'label' => 'Người dùng',
        'section' => 'Hệ thống',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'group' => [
        'label' => 'Nhóm quyền',
        'section' => 'Hệ thống',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'setting' => [
        'label' => 'Cài đặt website',
        'section' => 'Hệ thống',
        'items' => [
            'edit' => 'Sửa',
        ],
    ],
    'audit_log' => [
        'label' => 'Nhật ký hệ thống',
        'section' => 'Hệ thống',
    ],
];
