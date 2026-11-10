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

    /** Thêm vào giỏ; "Mua ngay" (buy_now=1) chuyển thẳng sang thanh toán. */
    public function store(Request $request, Cart $cart, Catalog $catalog): RedirectResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'qty' => ['nullable', 'integer', 'min:1', 'max:'.Cart::MAX_QTY],
        ]);
        $product = $catalog->products()->whereKey($data['product_id'])->first();
        if (! $product) {
            return back()->with('error', 'Sản phẩm không còn bán.');
        }
        $qty = (int) ($data['qty'] ?? 1);
        if ($product->isOutOfStock()) {
            return back()->with('error', "“{$product->name}” đã hết hàng.");
        }
        $inCart = $cart->raw()[$product->id] ?? 0;
        if ($product->tracksStock() && $inCart + $qty > $product->stock) {
            return back()->with('error', "“{$product->name}” chỉ còn {$product->stock} sản phẩm".($inCart ? " (giỏ đã có {$inCart})." : '.'));
        }
        $cart->add($product->id, $qty);

        return $request->boolean('buy_now')
            ? redirect()->route('shop.checkout')
            : back()->with('success', "Đã thêm “{$product->name}” vào giỏ hàng.");
    }

    /** Sửa số lượng; vượt tồn kho thì đặt bằng số còn lại và báo. */
    public function update(Request $request, Cart $cart, Catalog $catalog, int $product): RedirectResponse
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QTY]]);
        $qty = (int) $data['qty'];
        $item = $catalog->products()->whereKey($product)->first(['id', 'name', 'stock']);
        if ($item?->tracksStock() && $qty > $item->stock) {
            $cart->set($product, $item->stock);

            return redirect()->route('shop.cart')->with('error', $item->stock > 0 ? "“{$item->name}” chỉ còn {$item->stock} sản phẩm." : "“{$item->name}” đã hết hàng.");
        }
        $cart->set($product, $qty);

        return redirect()->route('shop.cart');
    }

    public function destroy(Cart $cart, int $product): RedirectResponse
    {
        $cart->remove($product);

        return redirect()->route('shop.cart')->with('success', 'Đã xóa sản phẩm khỏi giỏ.');
    }
}
