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
        $cart->add($product->id, (int) ($data['qty'] ?? 1));

        return $request->boolean('buy_now')
            ? redirect()->route('shop.checkout')
            : back()->with('success', "Đã thêm “{$product->name}” vào giỏ hàng.");
    }

    public function update(Request $request, Cart $cart, int $product): RedirectResponse
    {
        $data = $request->validate(['qty' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QTY]]);
        $cart->set($product, (int) $data['qty']);

        return redirect()->route('shop.cart');
    }

    public function destroy(Cart $cart, int $product): RedirectResponse
    {
        $cart->remove($product);

        return redirect()->route('shop.cart')->with('success', 'Đã xóa sản phẩm khỏi giỏ.');
    }
}
