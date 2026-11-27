<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\Cart;
use App\Support\Catalog;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Giỏ hàng (S4): xem, thêm từ trang sản phẩm, sửa số lượng, xóa dòng. */
class CartController extends Controller
{
    public function index(Cart $cart): View
    {
        $lines = $cart->lines($removed);
        if ($removed) {
            session()->now('error', 'Đã bỏ khỏi giỏ sản phẩm không còn bán: '.implode(', ', $removed).'.');
        }

        return view('frontend.cart', ['lines' => $lines, 'subtotal' => Cart::subtotal($lines)]);
    }

    /** Thêm vào giỏ (sản phẩm có biến thể: bắt buộc variant_id); "Mua ngay" (buy_now=1) chuyển thẳng sang thanh toán;
     *  "Thêm vào giỏ" ở lại trang, thông báo kèm nút sang giỏ hàng / thanh toán (cart_added). */
    public function store(Request $request, Cart $cart, Catalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'variant_id' => ['nullable', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:'.Cart::MAX_QTY],
        ]);
        $product = $catalog->products()->with('activeVariants')->whereKey($data['product_id'])->first();
        if (! $product) {
            return back()->with('error', 'Sản phẩm không còn bán.');
        }
        $qty = (int) ($data['qty'] ?? 1);

        $variant = null;
        if ($product->hasVariants()) {
            $variant = $product->activeVariants->firstWhere('id', (int) ($data['variant_id'] ?? 0));
            if (! $variant) {
                return redirect()->to($product->url())->with('error', 'Vui lòng chọn '.mb_strtolower($product->variant_label ?: 'phân loại').' trước khi thêm vào giỏ.');
            }
        }
        $name = $product->name.($variant ? " — {$variant->name}" : '');
        $stockOwner = $variant ?? $product;
        if ($stockOwner->isOutOfStock()) {
            return back()->with('error', "“{$name}” đã hết hàng.");
        }
        $key = Cart::key($product->id, $variant?->id);
        $inCart = $cart->qtyOf($key);
        if ($stockOwner->tracksStock() && $inCart + $qty > $stockOwner->stock) {
            return back()->with('error', "“{$name}” chỉ còn {$stockOwner->stock} sản phẩm".($inCart ? " (giỏ đã có {$inCart})." : '.'));
        }
        $cart->add($product->id, $variant?->id, $qty);

        return $request->boolean('buy_now')
            ? redirect()->route('shop.checkout')
            : back()->with('success', "Đã thêm “{$name}” vào giỏ hàng.")->with('cart_added', true);
    }

    /**
     * Sửa số lượng; vượt tồn kho thì đặt bằng số còn lại và báo. Nút −/+ ở giỏ hàng và trang thanh toán gọi bằng fetch
     * (Accept: application/json) → trả số liệu mới để cập nhật tại chỗ, không tải lại trang (trang thanh toán giữ nguyên các
     * ô khách đã nhập). Không có JS: form thường, quay về giỏ hàng.
     */
    public function update(Request $request, Cart $cart, string $line): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QTY]]);
        $qty = (int) $data['qty'];
        $current = $cart->lines()->firstWhere('key', $line);
        $owner = $current ? ($current['variant'] ?? $current['product']) : null;
        $error = null;
        if ($owner?->tracksStock() && $qty > $owner->stock) {
            $qty = max(0, (int) $owner->stock);
            $name = $current['product']->name.($current['variant'] ? ' — '.$current['variant']->name : '');
            $error = $owner->stock > 0 ? "“{$name}” chỉ còn {$owner->stock} sản phẩm." : "“{$name}” đã hết hàng.";
        }
        $cart->set($line, $qty);

        if ($request->expectsJson()) {
            $lines = $cart->lines();
            $now = $lines->firstWhere('key', $line);
            $subtotal = Cart::subtotal($lines);

            return response()->json([
                'qty' => $now['qty'] ?? 0,
                'max' => $now ? max(1, ($now['variant'] ?? $now['product'])->maxOrderQty()) : 0, // tồn kho có thể đã đổi
                'line_amount' => $now['line_amount'] ?? 0,
                'line_text' => Money::format($now['line_amount'] ?? 0).'đ',
                'subtotal' => $subtotal,
                'subtotal_text' => Money::format($subtotal).'đ',
                'count' => $cart->count(),
                'error' => $error,
            ]);
        }

        return $error ? redirect()->route('shop.cart')->with('error', $error) : redirect()->route('shop.cart');
    }

    public function destroy(Cart $cart, string $line): RedirectResponse
    {
        $cart->remove($line);

        return redirect()->route('shop.cart')->with('success', 'Đã xóa sản phẩm khỏi giỏ.');
    }
}
