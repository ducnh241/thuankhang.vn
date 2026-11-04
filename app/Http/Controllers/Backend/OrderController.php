<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Order;
use App\Models\PickupPoint;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Đơn hàng (A8): danh sách (tab trạng thái, tìm mã đơn / tên / SĐT, lọc điểm nhận hàng, hình thức, thời gian), chi tiết,
 * chuyển Đã thanh toán → Đang xử lý → Hoàn tất (order/status), hủy đơn chưa thanh toán có lý do (order/cancel).
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
        $order->load(['member.level', 'items.product', 'pickupPoint']);

        return view('backend.orders.show', [
            'order' => $order,
            'history' => AuditLog::where('auditable_type', Order::class)->where('auditable_id', $order->id)->latest('id')->limit(30)->get(),
        ]);
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
                'Đơn '.$order->code.': '.Order::STATUSES[$old].' → '.Order::STATUSES[$next]);

            return $next;
        });

        return $result
            ? back()->with('success', 'Đã chuyển đơn '.$order->code.' sang “'.Order::STATUSES[$result].'”.')
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
            $order->forceFill(['status' => Order::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            AuditLog::record('updated', $order, ['old' => ['status' => $old], 'new' => ['status' => Order::STATUS_CANCELLED]],
                'Hủy đơn '.$order->code.'. Lý do: '.$reason);

            return true;
        });

        return $done
            ? back()->with('success', 'Đã hủy đơn '.$order->code.'.')
            : back()->with('error', 'Chỉ hủy được đơn chưa thanh toán.');
    }
}
