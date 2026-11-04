<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Order;
use App\Models\PickupPoint;
use App\Support\AffiliateSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Đơn hàng (A8): danh sách (tab trạng thái, tìm mã đơn / tên / SĐT, lọc điểm nhận hàng, hình thức, thời gian), chi tiết,
 * xác nhận đã thanh toán / chưa nhận được tiền (order/confirm_payment — Kế toán), chuyển Đã thanh toán → Đang giao → Hoàn tất
 * (order/status), hủy đơn chưa thanh toán có lý do (order/cancel).
 * Mỗi thao tác ghi một dòng nhật ký (Order nằm trong AuditLog::MANUAL_SUBJECTS — không qua observer).
 */
class OrderController extends Controller
{
    public const PERIODS = ['today' => 'Hôm nay', '7d' => '7 ngày qua', '30d' => '30 ngày qua', 'month' => 'Tháng này'];

    public function index(Request $request): View|RedirectResponse
    {
        $points = PickupPoint::withTrashed()->orderBy('province')->orderBy('name')->get(['id', 'name', 'province', 'deleted_at']);
        $tab = $this->queryString($request, 'tab');
        $pointId = $this->queryString($request, 'pickup_point_id');
        $delivery = $this->queryString($request, 'delivery');
        $period = $this->queryString($request, 'period');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, Order::STATUSES) ? $tab : '',
            'pickup_point_id' => ctype_digit($pointId) && $points->contains('id', (int) $pointId) ? $pointId : '',
            'delivery' => array_key_exists($delivery, Order::DELIVERIES) ? $delivery : '',
            'period' => array_key_exists($period, self::PERIODS) ? $period : '',
        ];

        $base = Order::query()
            ->when($filter['q'] !== '', function (Builder $query) use ($filter) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
                $phone = Member::normalizePhone($filter['q']);
                $query->where(fn ($q) => $q->whereRaw("code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("payment_reference LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("recipient_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("recipient_phone LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('member', fn ($m) => $m->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like])
                        ->when($phone, fn ($m) => $m->orWhere('phone', $phone)))
                    ->when($phone, fn ($q) => $q->orWhere('recipient_phone', $phone)));
            })
            ->when($filter['pickup_point_id'] !== '', fn ($q) => $q->where('pickup_point_id', (int) $filter['pickup_point_id']))
            ->when($filter['delivery'] !== '', fn ($q) => $q->where('delivery_type', $filter['delivery']))
            ->when($filter['period'] !== '', fn ($q) => $q->where('created_at', '>=', match ($filter['period']) {
                'today' => today(), '7d' => today()->subDays(6), '30d' => today()->subDays(29), 'month' => today()->startOfMonth(),
            }));

        $counts = ['' => (clone $base)->count()];
        foreach (array_keys(Order::STATUSES) as $status) {
            $counts[$status] = (clone $base)->whereDisplayStatus($status)->count();
        }

        $orders = (clone $base)
            ->when($filter['tab'] !== '', fn ($q) => $q->whereDisplayStatus($filter['tab']))
            ->with(['member:id,full_name,phone', 'pickupPoint:id,name'])
            ->withCount('items')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        if ($orders->isEmpty() && $orders->currentPage() > 1) {
            return redirect($orders->url($orders->lastPage()));
        }

        return view('backend.orders.index', compact('orders', 'filter', 'counts', 'points'));
    }

    public function show(Order $order): View
    {
        $order->load(['member.level', 'items.product', 'pickupPoint', 'confirmer']);

        return view('backend.orders.show', [
            'order' => $order,
            'history' => AuditLog::where('auditable_type', Order::class)->where('auditable_id', $order->id)->latest('id')->limit(30)->get(),
        ]);
    }

    /**
     * Xác nhận đã thanh toán sau khi đối chiếu sao kê: bắt buộc mã giao dịch ngân hàng (duy nhất toàn hệ thống) và số tiền thực
     * nhận (không thấp hơn tổng đơn). Đơn đã hủy (tiền về muộn) được khôi phục, bắt buộc ghi chú.
     * TODO Step 7–8: kích hoạt tài khoản, cộng doanh số / lên cấp, ghi hoa hồng F1 / F2 trong cùng transaction.
     */
    public function confirmPayment(Request $request, Order $order): RedirectResponse
    {
        $request->merge([
            'paid_amount' => is_string($request->input('paid_amount')) ? Money::parse($request->input('paid_amount')) : $request->input('paid_amount'),
            'payment_reference' => is_string($request->input('payment_reference')) ? strtoupper(trim($request->input('payment_reference'))) : $request->input('payment_reference'),
        ]);
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100', Rule::unique('orders', 'payment_reference')->ignore($order->id)],
            'paid_amount' => ['required', 'integer', 'min:'.$order->total_amount, 'max:100000000000'],
            'payment_note' => [Rule::requiredIf($order->status === Order::STATUS_CANCELLED), 'nullable', 'string', 'max:300'],
        ], [
            'payment_reference.required' => 'Nhập mã giao dịch ngân hàng.',
            'payment_reference.unique' => 'Mã giao dịch này đã dùng để xác nhận đơn khác.',
            'paid_amount.required' => 'Nhập số tiền thực nhận.',
            'paid_amount.integer' => 'Số tiền không hợp lệ.',
            'paid_amount.min' => 'Số tiền thực nhận thấp hơn tổng đơn ('.Money::format($order->total_amount).' đ). Chờ khách chuyển bù hoặc liên hệ khách.',
            'payment_note.required' => 'Đơn đã hủy: ghi chú lý do khôi phục (VD: tiền về sau khi quá hạn).',
        ], ['payment_reference' => 'mã giao dịch', 'paid_amount' => 'số tiền', 'payment_note' => 'ghi chú']);

        $done = DB::transaction(function () use ($order, $data, $request) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->canConfirmPayment()) {
                return false;
            }
            $old = $order->status;
            $reference = $data['payment_reference'];
            $note = isset($data['payment_note']) ? trim($data['payment_note']) : null;
            $order->forceFill([
                'status' => Order::STATUS_PAID, 'paid_at' => now(), 'paid_amount' => (int) $data['paid_amount'],
                'payment_reference' => $reference, 'payment_note' => $note ?: null, 'confirmed_by' => $request->user('web')->id,
            ])->save();
            AuditLog::record('updated', $order,
                ['old' => ['status' => $old], 'new' => ['status' => Order::STATUS_PAID, 'paid_amount' => (int) $data['paid_amount'], 'payment_reference' => $reference]],
                "Xác nhận đã thanh toán đơn {$order->code} — GD {$reference}, ".Money::format($data['paid_amount']).' đ'
                .($old === Order::STATUS_CANCELLED ? ' (khôi phục đơn đã hủy)' : '').($note ? ". Ghi chú: {$note}" : ''));

            return true;
        });

        return $done
            ? back()->with('success', "Đã xác nhận thanh toán đơn {$order->code}.")
            : back()->with('error', 'Đơn đã được thanh toán hoặc đang ở trạng thái không xác nhận được.');
    }

    /** Khách báo đã chuyển nhưng chưa thấy tiền: trả về "Đơn mới", tính lại hạn thanh toán từ bây giờ. */
    public function rejectPayment(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']],
            ['reason.required' => 'Nhập lý do.', 'reason.min' => 'Lý do quá ngắn (ít nhất 5 ký tự).'], ['reason' => 'lý do']);

        $done = DB::transaction(function () use ($order, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if ($order->status !== Order::STATUS_AWAITING) {
                return false;
            }
            $order->forceFill([
                'status' => Order::STATUS_NEW, 'confirm_requested_at' => null,
                'expires_at' => now()->addHours((int) AffiliateSettings::get('payment_expiry_hours')),
            ])->save();
            AuditLog::record('updated', $order, ['old' => ['status' => Order::STATUS_AWAITING], 'new' => ['status' => Order::STATUS_NEW]],
                "Đơn {$order->code}: chưa nhận được tiền, trả về Đơn mới. Lý do: ".trim($data['reason']));

            return true;
        });

        return $done
            ? back()->with('success', "Đã trả đơn {$order->code} về “Đơn mới”, hạn thanh toán tính lại.")
            : back()->with('error', 'Đơn không còn ở trạng thái chờ xác nhận.');
    }

    /** Chuyển sang trạng thái kế tiếp (to phải đúng bước kế tiếp — chống bấm 2 lần / tab cũ). */
    public function status(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['to' => ['required', 'string']]);

        $result = DB::transaction(function () use ($order, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            $next = $order->nextStatus();
            if ($next === null || $next !== $data['to']) {
                return null;
            }
            $old = $order->status;
            $order->forceFill(['status' => $next, $next === Order::STATUS_PROCESSING ? 'processing_at' : 'completed_at' => now()])->save();
            AuditLog::record('updated', $order, ['old' => ['status' => $old], 'new' => ['status' => $next]],
                'Đơn '.$order->code.': '.Order::labelFor($old, $order->delivery_type).' → '.Order::labelFor($next, $order->delivery_type));

            return $next;
        });

        return $result
            ? back()->with('success', 'Đã chuyển đơn '.$order->code.' sang “'.Order::labelFor($result, $order->delivery_type).'”.')
            : back()->with('error', 'Đơn đã đổi trạng thái trước đó. Vui lòng tải lại trang.');
    }

    /** Hủy đơn chưa thanh toán (bắt buộc lý do — hiện cho thành viên ở trang đơn). */
    public function cancel(Request $request, Order $order): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']],
            ['reason.required' => 'Nhập lý do hủy.', 'reason.min' => 'Lý do quá ngắn (ít nhất 5 ký tự).'], ['reason' => 'lý do']);

        $done = DB::transaction(function () use ($order, $data) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->isCancellable()) {
                return false;
            }
            $old = $order->status;
            $reason = trim($data['reason']);
            $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_source' => Order::CANCEL_ADMIN, 'cancel_reason' => $reason])->save();
            AuditLog::record('updated', $order, ['old' => ['status' => $old], 'new' => ['status' => Order::STATUS_CANCELLED]],
                'Hủy đơn '.$order->code.'. Lý do: '.$reason);

            return true;
        });

        return $done
            ? back()->with('success', 'Đã hủy đơn '.$order->code.'.')
            : back()->with('error', 'Chỉ hủy được đơn chưa thanh toán.');
    }
}
