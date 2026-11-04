<?php

/*
|--------------------------------------------------------------------------
| Menu sidebar backend
|--------------------------------------------------------------------------
|
| Mỗi group có 'label' và danh sách 'items'. Mỗi item:
|   'label'    tên hiển thị
|   'icon'     tên icon Ant Design trong sprite (resources/views/backend/partials/sprite.blade.php),
|              vd 'fund' → <use href="#i-fund">
|   'route'    tên route (item thường)
|   'params'   tham số route (tuỳ chọn); item chỉ active khi URL hiện tại có đúng các tham số này
|   'active'   pattern cho request()->routeIs() (mặc định = route)
|   'can'      Gate cần có để hiện (tuỳ chọn); mảng = có một trong các Gate là được
|   'children' danh sách item con (label, route, active, can) → thành menu cha thu/mở được;
|              cha tự ẩn nếu không còn con nào user được xem
|
*/

return [
    /*
     | Tài khoản ẩn (hỗ trợ kỹ thuật): không hiện ở bất kỳ đâu trong trang quản trị — danh sách / số đếm người dùng,
     | số người của nhóm, lịch sử thay đổi (ghi "Hệ thống") — và không ai mở / sửa / xóa được kể cả gõ URL (404).
     | Bản thân tài khoản này vẫn đăng nhập và làm mọi việc bình thường (sửa thông tin của mình ở trang Tài khoản).
     | Đổi bằng BACKEND_HIDDEN_USERS trong .env (danh sách id, cách nhau dấu phẩy; để trống = không ẩn ai).
     */
    'hidden_users' => array_values(array_filter(array_map('intval', explode(',', (string) env('BACKEND_HIDDEN_USERS', '1'))))),

    'menu' => [
        [
            'label' => 'OVERVIEW',
            'items' => [
                [
                    'label' => 'Dashboard',
                    'route' => 'backend.dashboard',
                    'icon' => 'dashboard',
                ],
                [
                    // Một trang cho mọi loại danh mục (Category::KINDS): hiện khi được xem ít nhất một loại
                    'label' => 'Categories',
                    'route' => 'backend.categories.index',
                    'active' => 'backend.categories.*',
                    'can' => ['product', 'news_category'],
                    'icon' => 'folder',
                ],
            ],
        ],
        [
            'label' => 'SHOP',
            'items' => [
                [
                    'label' => 'Products',
                    'route' => 'backend.products.index',
                    'active' => 'backend.products.*',
                    'can' => 'product',
                    'icon' => 'shop',
                ],
                [
                    'label' => 'Pickup points',
                    'route' => 'backend.pickup-points.index',
                    'active' => 'backend.pickup-points.*',
                    'can' => 'pickup_point',
                    'icon' => 'environment',
                ],
            ],
        ],
        [
            'label' => 'AFFILIATE',
            'items' => [
                [
                    'label' => 'Members',
                    'route' => 'backend.members.index',
                    'active' => 'backend.members.*',
                    'can' => 'member',
                    'icon' => 'contacts',
                ],
                [
                    'label' => 'Configuration',
                    'icon' => 'fund',
                    'children' => [
                        ['label' => 'Levels', 'route' => 'backend.affiliate.levels.index', 'can' => 'affiliate_level'],
                        ['label' => 'Commission rates', 'route' => 'backend.affiliate.commission.index', 'can' => 'affiliate_commission'],
                        ['label' => 'KPI tiers', 'route' => 'backend.affiliate.kpi.index', 'can' => 'affiliate_kpi'],
                        ['label' => 'Parameters', 'route' => 'backend.affiliate.settings.edit', 'can' => 'affiliate_setting'],
                    ],
                ],
            ],
        ],
        [
            'label' => 'WEBSITE',
            'items' => [
                [
                    'label' => 'Pages',
                    'route' => 'backend.pages.index',
                    'active' => 'backend.pages.*',
                    'can' => 'page',
                    'icon' => 'file-text',
                ],
                [
                    'label' => 'Menus',
                    'route' => 'backend.menus.index',
                    'active' => 'backend.menus.*',
                    'can' => 'menu',
                    'icon' => 'container',
                ],
            ],
        ],
        [
            'label' => 'SYSTEM',
            'items' => [
                [
                    'label' => 'Users',
                    'icon' => 'team',
                    'children' => [
                        [
                            'label' => 'Users',
                            'route' => 'backend.users.index',
                            'active' => 'backend.users.*',
                            'can' => 'user',
                        ],
                        [
                            'label' => 'Groups',
                            'route' => 'backend.groups.index',
                            'active' => 'backend.groups.*',
                            'can' => 'group',
                        ],
                    ],
                ],
                [
                    'label' => 'Settings',
                    'route' => 'backend.settings.edit',
                    'active' => 'backend.settings.*',
                    'can' => 'setting',
                    'icon' => 'setting',
                ],
                [
                    'label' => 'Audit log',
                    'route' => 'backend.audit-logs.index',
                    'active' => 'backend.audit-logs.*',
                    'can' => 'audit_log',
                    'icon' => 'history',
                ],
            ],
        ],
    ],
];
