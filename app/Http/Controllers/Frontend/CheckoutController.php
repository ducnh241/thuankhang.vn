<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\CheckoutRequest;
use App\Models\Member;
use App\Models\Order;
use App\Models\PickupPoint;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WalletTransaction;
use App\Services\OrderPayment;
use App\Services\StaffNotifier;
use App\Services\Wallet;
use App\Support\AffiliateSettings;
use App\Support\Cart;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Luồng mua hàng: Thêm vào giỏ / Mua ngay → trang Thanh toán (người nhận + hình thức nhận) → bấm "Thanh toán" → trang đơn
 * hiện thông tin chuyển khoản đã cấu hình + mã QR → khách chuyển khoản rồi bấm "Tôi đã chuyển khoản" (hoặc hủy đơn).
 * Có đơn mới thì báo kế toán qua Telegram / email (StaffNotifier, nếu đã cấu hình).
 *
 * Checkout (S5, Step 5): bắt buộc đăng nhập thành viên. Chọn giao tận nơi (phí ship COD: khách trả đơn vị vận chuyển
 * khi nhận, tùy lúc lên đơn — không hiện giá, không cộng vào đơn) hoặc nhận tại VP tỉnh (miễn phí). Đơn luôn shipping_fee = 0. Tạo đơn "Chờ thanh toán", mã = tiền tố (Tham số chung) + 8 ký tự, hạn thanh toán theo Tham số chung; giá lấy giá hiện
 * tại của sản phẩm và lưu snapshot vào order_items. Mỗi thành viên tối đa Order::MAX_OPEN_UNPAID đơn chưa thanh toán.
 *
 * Thanh toán bằng ví tái tiêu dùng (21/11/2026): chỉ chọn được khi số dư ví ≥ tổng đơn (không trả nửa ví nửa chuyển khoản).
 * Cùng transaction tạo đơn: trừ ví (Wallet::payOrder) rồi OrderPayment::confirm() với mã giao dịch "VI-<mã đơn>" → đơn sang
 * thẳng "Đã thanh toán", không cần kế toán; tính như đơn thường (kích hoạt, doanh số / lên cấp, hoa hồng F1 / F2). Đơn trả
 * bằng ví không bị giới hạn số đơn chưa thanh toán (đơn thanh toán ngay).
 */
class CheckoutController extends Controller
{
    public function show(Request $request, Cart $cart): View|RedirectResponse
    {
        $member = $request->user('member');
        $lines = $cart->lines($removed);
        if ($lines->isEmpty()) {
            return redirect()->route('shop.cart')->with('error', 'Giỏ hàng đang trống.');
        }
        $subtotal = Cart::subtotal($lines);
        $reconsume = $member->walletBalance(WalletTransaction::WALLET_RECONSUME);
        // Đủ đơn chưa thanh toán: chỉ còn trả bằng ví được (đơn thanh toán ngay) — ví không đủ thì như trước
        $transferBlocked = Order::openLimitReached($member->id);
        if ($transferBlocked && $reconsume < $subtotal) {
            return $this->limitReached();
        }
        if ($removed) {
            session()->now('error', 'Đã bỏ khỏi giỏ sản phẩm không còn bán: '.implode(', ', $removed).'.');
        }
        $points = PickupPoint::available()->get(['id', 'name', 'province', 'address', 'phone', 'opening_hours']);

        return view('frontend.checkout', [
            'member' => $member,
            'lines' => $lines,
            'subtotal' => $subtotal,
            'reconsume' => $reconsume,
            'transferBlocked' => $transferBlocked,
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
        $byWallet = $request->validated('payment_method') === Order::PAYMENT_WALLET;
        $goods = Cart::subtotal($lines);

        $order = DB::transaction(function () use ($request, $lines, $ship, $goods, $byWallet) {
            // Khóa dòng thành viên rồi mới đếm / xem số dư: gửi nhiều yêu cầu cùng lúc cũng không vượt giới hạn đơn chưa
            // thanh toán, không trừ ví hai lần
            $memberId = $request->user('member')->id;
            $member = $byWallet
                ? Wallet::lock(...Wallet::buyerChainIds($memberId))->get($memberId)
                : Member::whereKey($memberId)->lockForUpdate()->first();
            if (! $byWallet && Order::openLimitReached($member->id)) {
                return false;
            }
            if ($byWallet && ($balance = $member->walletBalance(WalletTransaction::WALLET_RECONSUME)) < $goods) {
                return ['wallet' => $balance];
            }

            // Tồn kho: khóa các dòng có theo dõi tồn (biến thể đã chọn, hoặc sản phẩm không biến thể) rồi mới kiểm tra —
            // hai khách đặt cùng lúc không bán quá số còn
            $plain = $lines->whereNull('variant');
            $productStock = Product::whereKey($plain->pluck('product.id'))->whereNotNull('stock')->lockForUpdate()->pluck('stock', 'id');
            $variantStock = ProductVariant::whereKey($lines->pluck('variant.id')->filter())->whereNotNull('stock')->lockForUpdate()->pluck('stock', 'id');
            $left = fn ($line) => $line['variant'] ? $variantStock[$line['variant']->id] ?? null : $productStock[$line['product']->id] ?? null;
            $short = $lines->filter(fn ($line) => $left($line) !== null && $line['qty'] > $left($line));
            if ($short->isNotEmpty()) {
                return $short->map(fn ($line) => $line['product']->name.($line['variant'] ? ' — '.$line['variant']->name : '').' (còn '.max(0, $left($line)).')')->implode(', ');
            }

            $order = Order::create([
                'code' => Order::generateCode(),
                'member_id' => $request->user('member')->id,
                'goods_amount' => $goods,
                // Giao tận nơi: phí ship COD — khách trả đơn vị vận chuyển khi nhận, tùy lúc lên đơn; không thu qua chuyển khoản
                'shipping_fee' => 0,
                'total_amount' => $goods,
                'payment_method' => $byWallet ? Order::PAYMENT_WALLET : Order::PAYMENT_TRANSFER,
                'delivery_type' => $request->validated('delivery_type'),
                'pickup_point_id' => $ship ? null : (int) $request->validated('pickup_point_id'),
                'recipient_name' => $request->validated('recipient_name'),
                'recipient_phone' => $request->validated('recipient_phone'),
                'province' => $ship ? $request->validated('province') : null,
                'address' => $ship ? $request->validated('address') : null,
                'note' => $request->validated('note') ?: null,
                'expires_at' => $byWallet ? null : now()->addHours((int) AffiliateSettings::get('payment_expiry_hours')),
            ]);
            foreach ($lines as $line) {
                $product = $line['product'];
                $variant = $line['variant'];
                $order->items()->create([
                    'product_id' => $product->id, 'variant_id' => $variant?->id, 'product_name' => $product->name, 'variant_name' => $variant?->name,
                    'sku' => $variant?->sku ?? $product->sku, 'qty' => $line['qty'],
                    'unit_price' => $line['unit_price'], 'line_amount' => $line['line_amount'],
                ]);
            }
            $order->load('items')->takeStock();

            if ($byWallet) {
                Wallet::payOrder($member, $order);
                OrderPayment::confirm($order, [
                    'payment_reference' => Order::WALLET_REFERENCE_PREFIX.$order->code, 'paid_amount' => $goods,
                    'payment_note' => 'Thanh toán bằng ví tái tiêu dùng',
                ], null);
                $order->refresh();
            }

            return $order;
        }, 3); // khóa chéo (deadlock) hiếm gặp: MySQL hủy một bên — chạy lại cả giao dịch
        if ($order === false) {
            return $this->limitReached();
        }
        if (is_string($order)) {
            return redirect()->route('shop.cart')->with('error', 'Không đủ hàng: '.$order.'. Vui lòng giảm số lượng.');
        }
        if (is_array($order)) {
            return back()->withInput()->withErrors(['payment_method' => 'Số dư ví tái tiêu dùng ('.Money::format($order['wallet'])
                .'đ) không đủ để thanh toán đơn '.Money::format($goods).'đ. Nạp thêm tiền vào ví hoặc chọn chuyển khoản.']);
        }
        $cart->clear();
        StaffNotifier::orderCreated($order); // báo kế toán có đơn mới (gửi sau khi trả trang, lỗi không ảnh hưởng đơn)

        if ($byWallet) {
            return redirect()->route('member.orders.show', $order)->with('success', "Đã thanh toán đơn {$order->code} bằng ví tái tiêu dùng (−"
                .Money::format($goods).'đ). Số dư ví còn '.Money::format($request->user('member')->walletBalance(WalletTransaction::WALLET_RECONSUME)).'đ.');
        }

        return redirect()->route('member.orders.show', $order)->with('success', "Đã tạo đơn {$order->code}. Chuyển khoản theo thông tin bên dưới, rồi bấm “Tôi đã chuyển khoản” để hoàn tất.");
    }

    private function limitReached(): RedirectResponse
    {
        return redirect()->route('member.orders.index')->with('error', 'Bạn đang có '.Order::MAX_OPEN_UNPAID
            .' đơn chưa thanh toán. Vui lòng thanh toán hoặc hủy bớt đơn cũ trước khi đặt đơn mới.');
    }
}
