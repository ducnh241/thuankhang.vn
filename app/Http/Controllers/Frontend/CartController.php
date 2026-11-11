<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\Cart;
use App\Support\Catalog;
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

    /** Thêm vào giỏ (sản phẩm có biến thể: bắt buộc variant_id); "Mua ngay" (buy_now=1) chuyển thẳng sang thanh toán. */
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
            : back()->with('success', "Đã thêm “{$name}” vào giỏ hàng.");
    }

    /** Sửa số lượng; vượt tồn kho thì đặt bằng số còn lại và báo. */
    public function update(Request $request, Cart $cart, string $line): RedirectResponse
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QTY]]);
        $qty = (int) $data['qty'];
        $current = $cart->lines()->firstWhere('key', $line);
        $owner = $current ? ($current['variant'] ?? $current['product']) : null;
        if ($owner?->tracksStock() && $qty > $owner->stock) {
            $cart->set($line, $owner->stock);
            $name = $current['product']->name.($current['variant'] ? ' — '.$current['variant']->name : '');

            return redirect()->route('shop.cart')->with('error', $owner->stock > 0 ? "“{$name}” chỉ còn {$owner->stock} sản phẩm." : "“{$name}” đã hết hàng.");
        }
        $cart->set($line, $qty);

        return redirect()->route('shop.cart');
    }

    public function destroy(Cart $cart, string $line): RedirectResponse
    {
        $cart->remove($line);

        return redirect()->route('shop.cart')->with('success', 'Đã xóa sản phẩm khỏi giỏ.');
    }
}
