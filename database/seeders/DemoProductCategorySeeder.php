<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Danh mục sản phẩm demo cho môi trường dev / staging (không gọi từ DatabaseSeeder):
 *   php artisan db:seed --class=DemoProductCategorySeeder
 * Chạy lại không tạo trùng (theo slug).
 */
class DemoProductCategorySeeder extends Seeder
{
    /** [tên, slug, mô tả] */
    public const CATEGORIES = [
        ['Thực phẩm chức năng', 'thuc-pham-chuc-nang', 'Vitamin, khoáng chất và sản phẩm hỗ trợ sức khỏe.'],
        ['Mỹ phẩm & làm đẹp', 'my-pham-lam-dep', 'Chăm sóc da, trang điểm và dưỡng tóc.'],
        ['Chăm sóc cá nhân', 'cham-soc-ca-nhan', 'Vệ sinh cá nhân, chăm sóc răng miệng và cơ thể.'],
        ['Đồ uống dinh dưỡng', 'do-uong-dinh-duong', 'Sữa, trà và thức uống bổ sung dinh dưỡng.'],
        ['Gia dụng', 'gia-dung', 'Đồ dùng gia đình và thiết bị nhà bếp.'],
    ];

    public function run(): void
    {
        foreach (self::CATEGORIES as $i => [$name, $slug, $description]) {
            Category::firstOrCreate(
                ['type' => Category::TYPE_PRODUCT, 'slug' => $slug],
                ['name' => $name, 'description' => $description, 'priority' => $i + 1, 'status' => true],
            );
        }
    }
}
