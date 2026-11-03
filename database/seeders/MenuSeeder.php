<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

/**
 * Menu chính của cửa hàng (handle "main") lúc mới cài: Trang chủ + mỗi danh mục sản phẩm gốc đang hiện một mục.
 * Thanh danh mục trên website đã lấy thẳng từ cây danh mục; menu chính dành cho link thêm (Giới thiệu, Liên hệ…).
 */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menu = Menu::firstOrCreate(['handle' => 'main'], ['name' => 'Menu chính']);
        if ($menu->items()->exists()) {
            return;
        }

        $menu->saveTree([
            ['label' => 'Trang chủ', 'url' => '/', 'children' => []],
            ['label' => 'Tìm sản phẩm', 'url' => '/tim-kiem', 'children' => []],
        ]);
    }
}
