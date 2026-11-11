<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * Giỏ hàng lưu trong session (S4): [khóa dòng => số lượng]. Khóa dòng = "productId" (sản phẩm không biến thể) hoặc
 * "productId:variantId". Khách chưa đăng nhập vẫn thêm được; bắt đăng nhập khi thanh toán. Giá luôn lấy giá hiện tại;
 * sản phẩm / biến thể không còn bán (ẩn / xóa / tắt / danh mục ẩn, hoặc sản phẩm nay đã có biến thể) tự bỏ khỏi giỏ.
 */
class Cart
{
    public const SESSION_KEY = 'cart';

    public const MAX_QTY = 99;

    public const MAX_LINES = 50;

    public function __construct(private Session $session, private Catalog $catalog) {}

    public static function key(int $productId, ?int $variantId = null): string
    {
        return $variantId ? "{$productId}:{$variantId}" : (string) $productId;
    }

    /** @return array{0: int, 1: ?int} [productId, variantId] */
    public static function parseKey(int|string $key): array
    {
        [$product, $variant] = array_pad(explode(':', (string) $key, 2), 2, null);

        return [(int) $product, $variant !== null ? (int) $variant : null];
    }

    /** @return array<int|string, int> */
    public function raw(): array
    {
        $items = $this->session->get(self::SESSION_KEY, []);
        if (! is_array($items)) {
            return [];
        }

        return array_filter(array_map('intval', $items), fn ($qty, $key) => $qty > 0 && preg_match('/^\d+(:\d+)?$/', (string) $key), ARRAY_FILTER_USE_BOTH);
    }

    public function qtyOf(string $key): int
    {
        return $this->raw()[$key] ?? 0;
    }

    public function add(int $productId, ?int $variantId, int $qty = 1): void
    {
        $key = self::key($productId, $variantId);
        $items = $this->raw();
        if (! isset($items[$key]) && count($items) >= self::MAX_LINES) {
            return;
        }
        $items[$key] = min(self::MAX_QTY, ($items[$key] ?? 0) + max(1, $qty));
        $this->session->put(self::SESSION_KEY, $items);
    }

    public function set(string $key, int $qty): void
    {
        $items = $this->raw();
        if (! isset($items[$key])) {
            return;
        }
        if ($qty <= 0) {
            $this->remove($key);

            return;
        }
        $items[$key] = min(self::MAX_QTY, $qty);
        $this->session->put(self::SESSION_KEY, $items);
    }

    public function remove(string $key): void
    {
        $items = $this->raw();
        unset($items[$key]);
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
     * Các dòng còn bán được, theo thứ tự thêm vào: [key, product, variant, qty, unit_price, line_amount]. Dòng không còn bán
     * bị bỏ khỏi giỏ; $removed nhận tên các dòng đó để báo cho khách.
     *
     * @return Collection<int, array{key: string, product: Product, variant: ?ProductVariant, qty: int, unit_price: int, line_amount: int}>
     */
    public function lines(?array &$removed = null): Collection
    {
        $removed = [];
        $items = $this->raw();
        if ($items === []) {
            return collect();
        }
        $parsed = collect($items)->mapWithKeys(fn ($qty, $key) => [(string) $key => [...self::parseKey($key), $qty]]);
        $products = $this->catalog->products()->whereKey($parsed->pluck(0)->unique()->all())->with(['cover', 'activeVariants'])->get()->keyBy('id');

        $lines = collect();
        $dropped = [];
        foreach ($parsed as $key => [$productId, $variantId, $qty]) {
            $product = $products[$productId] ?? null;
            $variant = $product && $variantId ? $product->activeVariants->firstWhere('id', $variantId) : null;
            // Còn bán: sản phẩm có, và (không biến thể + giỏ không chọn biến thể) hoặc (biến thể chọn còn bật)
            $valid = $product && ($variantId ? $variant !== null : ! $product->hasVariants());
            if (! $valid) {
                $dropped[$key] = $productId;

                continue;
            }
            $unit = $variant ? $variant->effectivePrice($product) : $product->price;
            $lines->push(['key' => (string) $key, 'product' => $product, 'variant' => $variant, 'qty' => $qty, 'unit_price' => $unit, 'line_amount' => $unit * $qty]);
        }
        if ($dropped !== []) {
            $removed = Product::withTrashed()->whereKey(array_unique($dropped))->pluck('name')->all();
            $this->session->put(self::SESSION_KEY, array_diff_key($items, $dropped));
        }

        return $lines;
    }

    public static function subtotal(Collection $lines): int
    {
        return (int) $lines->sum('line_amount');
    }
}
