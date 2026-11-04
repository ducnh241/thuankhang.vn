<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Order;
use App\Support\VietQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Đơn hàng của tôi (S7): danh sách, chi tiết (mã QR + thông tin chuyển khoản khi đơn mới còn hạn), "Tôi đã chuyển khoản"
 * (→ Chờ xác nhận thanh toán, dừng đếm hạn), tự hủy đơn mới. Chỉ thao tác trên đơn của chính mình.
 */
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
        $this->authorizeOwner($request, $order);

        return view('frontend.orders.show', [
            'order' => $order->load(['items.product.cover', 'pickupPoint']),
            'bank' => VietQr::bank(),
            'qr' => $order->isAwaitingPayment() ? VietQr::imageUrl($order) : null,
        ]);
    }

    /** Khách báo đã chuyển khoản: đơn vào hàng chờ Kế toán đối chiếu, không còn tự hủy khi quá hạn. */
    public function confirmTransfer(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwner($request, $order);
        $done = DB::transaction(function () use ($order) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->isAwaitingPayment()) {
                return false;
            }
            $order->forceFill(['status' => Order::STATUS_AWAITING, 'confirm_requested_at' => now()])->save();
            AuditLog::record('updated', $order, ['old' => ['status' => Order::STATUS_NEW], 'new' => ['status' => Order::STATUS_AWAITING]],
                "Đơn {$order->code}: thành viên báo đã chuyển khoản");

            return true;
        });

        return back()->with($done ? 'success' : 'error', $done
            ? 'Cảm ơn bạn! Chúng tôi sẽ đối chiếu và xác nhận thanh toán sớm nhất.'
            : 'Đơn không còn ở trạng thái chờ thanh toán.');
    }

    /** Khách tự hủy đơn mới (chưa báo chuyển khoản). */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwner($request, $order);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null;
        $done = DB::transaction(function () use ($order, $reason) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->isAwaitingPayment()) {
                return false;
            }
            $text = Order::CANCEL_SOURCES[Order::CANCEL_CUSTOMER].($reason ? ': '.trim($reason) : '');
            $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_source' => Order::CANCEL_CUSTOMER, 'cancel_reason' => $text])->save();
            AuditLog::record('updated', $order, ['old' => ['status' => Order::STATUS_NEW], 'new' => ['status' => Order::STATUS_CANCELLED]], "Đơn {$order->code}: {$text}");

            return true;
        });

        return back()->with($done ? 'success' : 'error', $done ? "Đã hủy đơn {$order->code}." : 'Chỉ hủy được đơn chưa thanh toán và chưa báo chuyển khoản.');
    }

    private function authorizeOwner(Request $request, Order $order): void
    {
        abort_unless($order->member_id === $request->user('member')->id, 404);
    }
}
