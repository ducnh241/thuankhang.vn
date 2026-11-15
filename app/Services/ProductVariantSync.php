<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Product;

/**
 * Đồng bộ biến thể của sản phẩm theo danh sách mới (admin + API): dòng có id thuộc sản phẩm → sửa, không id → thêm,
 * biến thể cũ không còn trong danh sách → xóa (đơn cũ giữ tên qua snapshot). Ghi một dòng nhật ký khi có thay đổi.
 * Mỗi dòng: [id|null, name, sku, price, stock, image_id, active, sort_order].
 */
class ProductVariantSync
{
    public static function sync(Product $product, array $rows): void
    {
        $fields = ['name', 'sku', 'price', 'stock', 'image_id', 'active'];
        $before = $product->variants()->get()->map(fn ($v) => $v->only($fields))->values()->all();
        $keep = array_filter(array_column($rows, 'id'));
        $product->variants()->whereNotIn('id', $keep ?: [0])->delete();
        foreach ($rows as $row) {
            $data = collect($row)->except('id')->all();
            $row['id'] ? $product->variants()->whereKey($row['id'])->first()?->update($data) : $product->variants()->create($data);
        }
        $after = $product->variants()->get()->map(fn ($v) => $v->only($fields))->values()->all();
        if ($before !== $after) {
            AuditLog::record('updated', $product, ['old' => ['variants' => $before], 'new' => ['variants' => $after]], "Cập nhật biến thể sản phẩm {$product->name}");
        }
    }
}
