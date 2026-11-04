<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Support\AffiliateSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Đơn hàng của tôi (S7): danh sách, chi tiết (thông tin chuyển khoản khi còn hạn). Chỉ xem đơn của chính mình. */
class OrderController extends Controller
{
    public function index(Request $request): View
    {
        return view('frontend.orders.index', [
            'orders' => $request->user('member')->orders()->withCount('items')->latest('id')->paginate(10),
        ]);
    }

    public function show(Request $request, Order $order): View
    {
        abort_unless($order->member_id === $request->user('member')->id, 404);

        return view('frontend.orders.show', [
            'order' => $order->load(['items.product.cover', 'pickupPoint']),
            'bank' => [
                'code' => AffiliateSettings::get('bank_code'),
                'account' => AffiliateSettings::get('bank_account'),
                'name' => AffiliateSettings::get('bank_account_name'),
            ],
        ]);
    }
}
