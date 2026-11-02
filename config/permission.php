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
    'project' => [
        'label' => 'Dự án',
        'section' => 'Kinh doanh',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'customer' => [
        'label' => 'Khách hàng',
        'section' => 'Kinh doanh',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'payment' => [
        'label' => 'Thanh toán',
        'section' => 'Kinh doanh',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'report' => [
        'label' => 'Báo cáo doanh thu',
        'section' => 'Kinh doanh',
    ],
    'tag' => [
        'label' => 'Tag dự án',
        'section' => 'Danh mục',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'hosting' => [
        'label' => 'Hosting',
        'section' => 'Danh mục',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
    ],
    'renewal' => [
        'label' => 'Nhắc gia hạn',
        'section' => 'Kinh doanh',
        'items' => [
            'renew' => 'Ghi nhận gia hạn / hủy gia hạn',
            'template' => 'Sửa mẫu thông báo',
        ],
    ],
    'project_category' => [
        'label' => 'Danh mục dự án',
        'section' => 'Danh mục',
        'items' => [
            'add' => 'Thêm',
            'edit' => 'Sửa',
            'delete' => 'Xóa',
        ],
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
];
