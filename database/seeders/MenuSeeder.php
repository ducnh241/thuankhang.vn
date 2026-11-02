<?php

namespace Database\Seeders;

use App\Models\Menu;
use Illuminate\Database\Seeder;

/** Menu chính của trang chủ (handle "main") với các mục vốn được viết cứng trong trang giới thiệu. */
class MenuSeeder extends Seeder
{
    public function run(): void
    {
        $menu = Menu::firstOrCreate(['handle' => 'main'], ['name' => 'Menu chính']);
        if ($menu->items()->exists()) {
            return;
        }

        $menu->saveTree([
            ['label' => 'Giới thiệu', 'url' => '#about', 'children' => []],
            ['label' => 'Chuyên môn', 'url' => '#expertise', 'children' => []],
            ['label' => 'Hành trình', 'url' => '#journey', 'children' => []],
            ['label' => 'Chạy bộ', 'url' => '#running', 'children' => []],
            ['label' => 'Liên hệ', 'url' => '#contact', 'children' => []],
        ]);
    }
}
