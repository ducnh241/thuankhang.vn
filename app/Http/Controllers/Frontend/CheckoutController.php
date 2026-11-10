<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\CheckoutRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Support\AffiliateSettings;
use App\Support\Cart;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Checkout (S5, Step 5): bắt buộc đăng nhập thành viên. Chọn giao tận nơi (khách trả phí ship — Tham số chung) hoặc nhận
 * tại VP tỉnh (ship = 0). Tạo đơn "Chờ thanh toán", mã = tiền tố (Tham số chung) + 8 ký tự, hạn thanh toán theo Tham số chung; giá lấy giá hiện
 * tại của sản phẩm và lưu snapshot vào order_items.
 */
class CheckoutController extends Controller
{
    public function show(Request $request, Cart $cart): View|RedirectResponse
    {
        $lines = $cart->lines($removed);
        if ($lines->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', 'Giỏ hàng đang trống.');
        }
        if ($removed) {
            session()->now('error', 'Đã bỏ khỏi giỏ sản phẩm không còn bán: '.implode(', ', $removed).'.');
        }
        $points = PickupPoint::available()->get(['id', 'name', 'province', 'address', 'phone', 'opening_hours']);

        return view('frontend.checkout', [
            'member' => $request->user('member'),
            'lines' => $lines,
            'subtotal' => Cart::subtotal($lines),
            'shippingFee' => (int) AffiliateSettings::get('shipping_fee'),
            'points' => $points,
            'pointProvinces' => collect(PickupPoint::provinces())->only($points->pluck('province')->unique()->all()),
        ]);
    }

    public function store(CheckoutRequest $request, Cart $cart): RedirectResponse
    {
        $lines = $cart->lines($removed);
        if ($removed || $lines->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', $removed
                ? 'Một số sản phẩm vừa ngừng bán và đã được bỏ khỏi giỏ: '.implode(', ', $removed).'. Vui lòng kiểm tra lại.'
                : 'Giỏ hàng đang trống.');
        }

        $ship = $request->validated('delivery_type') === Order::DELIVERY_SHIP;
        $goods = Cart::subtotal($lines);
        $shipping = $ship ? (int) AffiliateSettings::get('shipping_fee') : 0;

        $order = DB::transaction(function () use ($request, $lines, $ship, $goods, $shipping) {
            // Tồn kho: khóa các sản phẩm có theo dõi tồn rồi mới kiểm tra (hai khách đặt cùng lúc không bán quá số còn)
            $stocks = Product::whereKey($lines->pluck('product.id'))->whereNotNull('stock')->lockForUpdate()->pluck('stock', 'id');
            $short = $lines->filter(fn ($line) => $stocks->has($line['product']->id) && $line['qty'] > $stocks[$line['product']->id]);
            if ($short->isNotEmpty()) {
                return $short->map(fn ($line) => $line['product']->name.' (còn '.max(0, $stocks[$line['product']->id]).')')->implode(', ');
            }

            $order = Order::create([
                'code' => Order::generateCode(),
                'member_id' => $request->user('member')->id,
                'goods_amount' => $goods,
                'vat_amount' => (int) $lines->sum(fn ($line) => OrderItem::vatOf($line['line_amount'], $line['product']->vat_rate)),
                'shipping_fee' => $shipping,
                'total_amount' => $goods + $shipping,
                'delivery_type' => $request->validated('delivery_type'),
                'pickup_point_id' => $ship ? null : (int) $request->validated('pickup_point_id'),
                'recipient_name' => $request->validated('recipient_name'),
                'recipient_phone' => $request->validated('recipient_phone'),
                'province' => $ship ? $request->validated('province') : null,
                'address' => $ship ? $request->validated('address') : null,
                'note' => $request->validated('note') ?: null,
                'expires_at' => now()->addHours((int) AffiliateSettings::get('payment_expiry_hours')),
            ]);
            foreach ($lines as $line) {
                $product = $line['product'];
                $order->items()->create([
                    'product_id' => $product->id, 'product_name' => $product->name, 'sku' => $product->sku, 'qty' => $line['qty'],
                    'unit_price' => $product->price, 'vat_rate' => $product->vat_rate, 'line_amount' => $line['line_amount'],
                    'vat_amount' => OrderItem::vatOf($line['line_amount'], $product->vat_rate),
                ]);
            }
            $order->load('items')->takeStock();

            return $order;
        });
        if (is_string($order)) {
            return redirect()->route('shop.cart')->with('error', 'Không đủ hàng: '.$order.'. Vui lòng giảm số lượng.');
        }
        $cart->clear();

        return redirect()->route('member.orders.show', $order)->with('success', "Đã đặt đơn {$order->code}. Vui lòng chuyển khoản để hoàn tất.");
    }
}
