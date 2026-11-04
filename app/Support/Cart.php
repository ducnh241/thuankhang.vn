<?php

namespace App\Support;

use App\Models\Product;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * Giỏ hàng lưu trong session (S4): [product_id => số lượng]. Khách chưa đăng nhập vẫn thêm được; bắt đăng nhập khi
 * thanh toán. Giá luôn lấy giá hiện tại của sản phẩm; sản phẩm không còn bán (ẩn / xóa / danh mục ẩn) tự bỏ khỏi giỏ.
 */
class Cart
{
    public const SESSION_KEY = 'cart';

    public const MAX_QTY = 99;

    public const MAX_LINES = 50;

    public function __construct(private Session $session, private Catalog $catalog) {}

    /** @return array<int, int> */
    public function raw(): array
    {
        $items = $this->session->get(self::SESSION_KEY, []);

        return is_array($items) ? array_filter(array_map('intval', $items), fn ($qty) => $qty > 0) : [];
    }

    public function add(int $productId, int $qty = 1): void
    {
        $items = $this->raw();
        if (! isset($items[$productId]) && count($items) >= self::MAX_LINES) {
            return;
        }
        $items[$productId] = min(self::MAX_QTY, ($items[$productId] ?? 0) + max(1, $qty));
        $this->session->put(self::SESSION_KEY, $items);
    }

    public function set(int $productId, int $qty): void
    {
        $items = $this->raw();
        if (! isset($items[$productId])) {
            return;
        }
        if ($qty <= 0) {
            $this->remove($productId);

            return;
        }
        $items[$productId] = min(self::MAX_QTY, $qty);
        $this->session->put(self::SESSION_KEY, $items);
    }

    public function remove(int $productId): void
    {
        $items = $this->raw();
        unset($items[$productId]);
        $this->session->put(self::SESSION_KEY, $items);
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /** Tổng số lượng (biểu tượng giỏ ở đầu trang). */
    public function count(): int
    {
        return array_sum($this->raw());
    }

    /**
     * Các dòng còn bán được, theo thứ tự thêm vào: [product, qty, line_amount]. Sản phẩm không còn bán bị bỏ khỏi giỏ;
     * $removed nhận tên các sản phẩm đó để báo cho khách.
     *
     * @return Collection<int, array{product: Product, qty: int, line_amount: int}>
     */
    public function lines(?array &$removed = null): Collection
    {
        $removed = [];
        $items = $this->raw();
        if ($items === []) {
            return collect();
        }
        $products = $this->catalog->products()->whereKey(array_keys($items))->with('cover')->get()->keyBy('id');
        $missing = array_diff(array_keys($items), $products->keys()->all());
        if ($missing !== []) {
            $removed = Product::withTrashed()->whereKey($missing)->pluck('name')->all();
            $this->session->put(self::SESSION_KEY, array_diff_key($items, array_flip($missing)));
        }

        return collect($items)->filter(fn ($qty, $id) => $products->has($id))
            ->map(fn ($qty, $id) => ['product' => $products[$id], 'qty' => $qty, 'line_amount' => $products[$id]->price * $qty])
            ->values();
    }

    public static function subtotal(Collection $lines): int
    {
        return (int) $lines->sum('line_amount');
    }
}
