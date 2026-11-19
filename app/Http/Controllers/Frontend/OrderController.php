<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Services\StaffNotifier;
use App\Support\VietQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Đơn hàng của tôi (S7): danh sách, chi tiết (mã QR + thông tin chuyển khoản khi đơn mới còn hạn), "Tôi đã chuyển khoản"
 * (bắt buộc tải ảnh bill chuyển khoản → Chờ xác nhận thanh toán, dừng đếm hạn), xem lại ảnh bill, tự hủy đơn mới. Chỉ thao tác trên đơn của chính mình.
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
            'order' => $order->load(['items.product.cover', 'items.variant.image', 'pickupPoint']),
            'bank' => $bank = VietQr::bank(),
            // Ảnh mã QR công ty tải lên (ưu tiên), không có thì mã VietQR tự tạo theo đơn
            'qr' => $order->isAwaitingPayment() ? ($bank['qr_image'] ?? VietQr::imageUrl($order, $bank)) : null,
            'qrIsStatic' => (bool) $bank['qr_image'],
        ]);
    }

    /**
     * Khách báo đã chuyển khoản: BẮT BUỘC kèm ảnh bill chuyển khoản (lưu mã hóa, kế toán xem ở trang đơn trong quản trị); đơn
     * vào hàng chờ Kế toán đối chiếu, không còn tự hủy khi quá hạn. Báo lại sau khi bị trả về "Đơn mới" thì ảnh mới thay ảnh cũ.
     */
    public function confirmTransfer(Request $request, Order $order): RedirectResponse
    {
        $this->authorizeOwner($request, $order);
        [$rules, $messages] = Order::billRules();
        $request->validate(['bill' => $rules], $messages, ['bill' => 'ảnh bill']);

        $stored = null;
        try {
            $done = DB::transaction(function () use ($order, $request, &$stored) {
                $order = Order::lockForUpdate()->findOrFail($order->id);
                if (! $order->isAwaitingPayment()) {
                    return false;
                }
                $old = $order->payment_bill;
                $stored = MemberKyc::storeImage($request->file('bill'), $order->id, Order::BILL_DIRECTORY);
                $order->forceFill(['status' => Order::STATUS_AWAITING, 'confirm_requested_at' => now(), 'payment_bill' => $stored])->save();
                AuditLog::record('updated', $order, ['old' => ['status' => Order::STATUS_NEW], 'new' => ['status' => Order::STATUS_AWAITING]],
                    "Đơn {$order->code}: thành viên báo đã chuyển khoản, kèm ảnh bill");

                return ['old' => $old];
            });
        } catch (Throwable $e) {
            MemberKyc::deleteImages($stored);
            throw $e;
        }

        if ($done) {
            MemberKyc::deleteImages($done['old']); // ảnh bill của lần báo trước (đơn bị trả về "Đơn mới")
            StaffNotifier::orderTransferred($order->refresh()->load('pickupPoint')); // báo kế toán vào xác nhận thanh toán
        }

        return back()->with($done ? 'success' : 'error', $done
            ? 'Cảm ơn bạn! Chúng tôi sẽ đối chiếu và xác nhận thanh toán sớm nhất.'
            : 'Đơn không còn ở trạng thái chờ thanh toán.');
    }

    /** Ảnh bill chuyển khoản của đơn — chỉ chủ đơn xem lại được. */
    public function bill(Request $request, Order $order): Response
    {
        $this->authorizeOwner($request, $order);
        $image = $order->billImage();
        abort_if($image === null, 404);

        return response($image['contents'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
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
            $order->load('items')->releaseStock();
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
