<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Order;
use App\Models\ProfitShareEntry;
use App\Models\ProfitShareGroup;
use App\Models\ProfitShareLine;
use App\Models\ProfitSharePeriod;
use App\Models\User;
use App\Services\ProfitShare;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Đồng chia (quyền profit_share; duyệt: profit_share/approve — mặc định Kế toán):
 *   - index: các tháng (mới nhất trước) — trạng thái, hạn duyệt, quỹ / đã chia (tháng chưa duyệt: tạm tính trực tiếp).
 *   - show: bảng chia chi tiết của một tháng để đối soát — từng nhóm: đơn vào gói trong tháng (ai, ngày nào, bao nhiêu),
 *     cách tính quỹ, danh sách xếp hạng (top được chia / hàng chờ), người bị khóa, người đã nhận đủ từ trước. Tháng đã duyệt
 *     hiện ảnh chụp lúc duyệt; chưa duyệt thì tính trực tiếp.
 *   - approve: chỉ từ ngày 1 đến hết ngày 5 tháng sau; cộng ví tái tiêu dùng (App\Services\ProfitShare::approve).
 */
class ProfitShareController extends Controller
{
    public function index(): View
    {
        $approved = ProfitSharePeriod::with('approver:id,name')->get()->keyBy('period');
        $rows = ProfitShare::periods()->map(function (string $period) use ($approved) {
            $record = $approved[$period] ?? null;
            $live = $record ? null : ProfitShare::calculate($period);

            return [
                'period' => $period, 'record' => $record, 'window' => ProfitShare::approveWindow($period),
                'order_total' => $record->order_total ?? $live['order_total'], 'pool_total' => $record->pool_total ?? $live['pool_total'],
                'distributed_total' => $record->distributed_total ?? $live['distributed_total'], 'paid_count' => $record->paid_count ?? $live['paid_count'],
                'state' => self::state($period, $record),
            ];
        });

        return view('backend.profit_share.index', ['rows' => $rows]);
    }

    public function show(string $period): View
    {
        abort_if(ProfitShare::range($period) === null, 404);
        $record = ProfitSharePeriod::with(['approver:id,name', 'groups.lines.member:id,full_name,phone,referral_code,status', 'groups.lines.entry.order:id,code,goods_amount,paid_at',
            'groups.lines.walletTransaction:id,profit_share_line_id,balance_after'])->where('period', $period)->first();

        $data = $record ? null : ProfitShare::calculate($period);

        return view('backend.profit_share.show', [
            'period' => $period,
            'record' => $record,
            'fingerprint' => $data['fingerprint'] ?? null,
            'sheet' => $record ? self::fromRecord($record) : self::fromLive($data),
            'window' => ProfitShare::approveWindow($period),
            'state' => self::state($period, $record),
            'history' => $record ? AuditLog::where('auditable_type', ProfitSharePeriod::class)->where('auditable_id', $record->id)->latest('id')->get() : collect(),
            'approvers' => self::approvers(),
        ]);
    }

    public function approve(Request $request, string $period): RedirectResponse
    {
        abort_if(ProfitShare::range($period) === null, 404);
        $fingerprint = (string) $request->validate(['fingerprint' => ['required', 'string', 'size:64']])['fingerprint'];

        $result = ProfitShare::approve($period, $fingerprint, $request->user('web'));
        if (is_string($result)) {
            return back()->with('error', $result);
        }

        return back()->with('success', 'Đã duyệt đồng chia tháng '.self::label($period).': cộng '.Money::format($result->distributed_total)
            .' đ vào ví tái tiêu dùng cho '.$result->paid_count.' lượt người.');
    }

    /**
     * Bảng chia dạng chung cho giao diện (tháng chưa duyệt: tính trực tiếp). Mỗi nhóm: thông số + orders, paid, waiting,
     * skipped, exited — mỗi dòng người: member, rank, joined_at, order (mã / tiền), cap, before, amount, after, exits, note.
     */
    private static function fromLive(array $data): array
    {
        $row = fn (array $r) => [
            'member' => $r['member'], 'rank' => $r['rank'] ?? null, 'joined_at' => $r['joined_at'], 'order' => $r['order'],
            'cap' => $r['cap'], 'before' => $r['received_before'], 'amount' => $r['amount'] ?? 0,
            'after' => $r['received_before'] + ($r['amount'] ?? 0), 'exits' => $r['exits'] ?? false, 'note' => $r['note'] ?? null,
            'status' => $r['status'] ?? null, 'balance_after' => null,
        ];

        return [
            ...collect($data)->only(['fee_percent', 'share_percent', 'slots', 'min_amount', 'order_total', 'pool_total', 'distributed_total', 'paid_count'])->all(),
            'groups' => collect($data['groups'])->map(fn ($g) => [
                'id' => $g['level']->id, 'name' => $g['level']->name, 'min' => $g['min'], 'max' => $g['max'], 'cap' => $g['cap'],
                'orders' => $g['orders'], 'order_count' => $g['orders']->count(), 'order_total' => $g['order_total'], 'base' => $g['base'],
                'pool' => $g['pool'], 'member_count' => $g['member_count'], 'paid_count' => $g['paid_count'], 'per_head' => $g['per_head'],
                'distributed' => $g['distributed'], 'remainder' => $g['remainder'],
                'paid' => $g['rows']->where('status', ProfitShareLine::STATUS_PAID)->map($row)->values(),
                'waiting' => $g['rows']->where('status', ProfitShareLine::STATUS_WAITING)->map($row)->values(),
                'skipped' => $g['skipped']->map($row)->values(),
                'exited' => $g['exited']->map($row)->values(),
            ])->all(),
        ];
    }

    /** Bảng chia đã duyệt (ảnh chụp đã lưu), cùng dạng với fromLive. Đơn vào gói của tháng lấy lại theo khoảng tiền đã lưu. */
    private static function fromRecord(ProfitSharePeriod $record): array
    {
        [$start, $end] = ProfitShare::range($record->period);
        $row = fn (ProfitShareLine $l) => [
            'member' => $l->member, 'rank' => $l->rank, 'joined_at' => $l->entry->joined_at, 'order' => $l->entry->order,
            'cap' => $l->entry->cap_amount, 'before' => $l->received_before, 'amount' => $l->amount, 'after' => $l->received_before + $l->amount,
            'exits' => $l->exits, 'note' => $l->note, 'status' => $l->status, 'balance_after' => $l->walletTransaction?->balance_after,
        ];

        return [
            'fee_percent' => $record->fee_percent, 'share_percent' => $record->share_percent, 'slots' => $record->slots,
            'min_amount' => $record->groups->min('min_amount'), 'order_total' => $record->order_total, 'pool_total' => $record->pool_total,
            'distributed_total' => $record->distributed_total, 'paid_count' => $record->paid_count,
            'groups' => $record->groups->map(function (ProfitShareGroup $g) use ($row, $start, $end, $record) {
                $orders = Order::with('member:id,full_name,phone,referral_code,status')
                    ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
                    ->where('paid_at', '>=', $start)->where('paid_at', '<', $end)->where('goods_amount', '>=', $g->min_amount)
                    ->when($g->max_amount !== null, fn ($q) => $q->where('goods_amount', '<', $g->max_amount))
                    ->orderBy('paid_at')->orderBy('id')->get(['id', 'code', 'member_id', 'goods_amount', 'paid_at', 'payment_method']);
                $seatOrders = $g->lines->map(fn ($l) => $l->entry->order_id)->all();
                $lines = $g->lines->sortBy(fn ($l) => [$l->rank === null ? PHP_INT_MAX : $l->rank, $l->id]);

                return [
                    'id' => $g->level_id, 'name' => $g->level_name, 'min' => $g->min_amount, 'max' => $g->max_amount, 'cap' => $g->min_amount,
                    'orders' => $orders->map(fn (Order $o) => ['order' => $o, 'member' => $o->member, 'new_seat' => in_array($o->id, $seatOrders, true)]),
                    'order_count' => $g->order_count, 'order_total' => $g->order_total, 'base' => $g->base_amount, 'pool' => $g->pool,
                    'member_count' => $g->member_count, 'paid_count' => $g->paid_count, 'per_head' => $g->per_head,
                    'distributed' => $g->distributed, 'remainder' => $g->pool - $g->distributed,
                    'paid' => $lines->where('status', ProfitShareLine::STATUS_PAID)->map($row)->values(),
                    'waiting' => $lines->where('status', ProfitShareLine::STATUS_WAITING)->map($row)->values(),
                    'skipped' => $lines->where('status', ProfitShareLine::STATUS_SKIPPED)->map($row)->values(),
                    // Đã nhận đủ và ra khỏi nhóm ở các tháng TRƯỚC tháng này
                    'exited' => ProfitShareEntry::with(['member:id,full_name,phone,referral_code,status', 'order:id,code,goods_amount,paid_at'])
                        ->where('level_id', $g->level_id)->whereNotNull('exited_period')->where('exited_period', '<', $record->period)->orderBy('joined_at')->get()
                        ->map(fn (ProfitShareEntry $e) => [
                            'member' => $e->member, 'rank' => null, 'joined_at' => $e->joined_at, 'order' => $e->order, 'cap' => $e->cap_amount,
                            'before' => $e->received_amount, 'amount' => 0, 'after' => $e->received_amount, 'exits' => false,
                            'note' => 'Nhận đủ tháng '.self::label($e->exited_period), 'status' => null, 'balance_after' => null,
                        ]),
                ];
            })->all(),
        ];
    }

    /**
     * Người được duyệt đồng chia: tài khoản quản trị đang hoạt động thuộc nhóm quyền có "profit_share/approve" (Root admin
     * luôn duyệt được, không liệt kê). Hiện trên trang để người chỉ có quyền xem biết cần báo ai duyệt.
     *
     * @return Collection<int, User>
     */
    public static function approvers(): Collection
    {
        $groups = Group::all()->filter(fn (Group $g) => $g->hasPermission('profit_share/approve'))->pluck('id');

        return User::visible()->whereIn('group_id', $groups)->where('status', true)->where('is_super_admin', false)
            ->with('group:id,name')->orderBy('name')->get(['id', 'name', 'group_id']);
    }

    /** current (tháng đang chạy) | open (trong hạn duyệt) | waiting (chưa tới hạn — không xảy ra với tháng đã qua) | missed | approved */
    public static function state(string $period, ?ProfitSharePeriod $record): string
    {
        if ($record) {
            return 'approved';
        }
        [, $end] = ProfitShare::range($period);
        [, $until] = ProfitShare::approveWindow($period);

        return match (true) {
            now()->lt($end) => 'current',
            now()->lte($until) => 'open',
            default => 'missed',
        };
    }

    public static function label(string $period): string
    {
        return substr($period, 5, 2).'/'.substr($period, 0, 4);
    }

    /** Nhãn trạng thái dòng chia. */
    public static function lineLabel(string $status, int $amount): string
    {
        return match (true) {
            $status === ProfitShareLine::STATUS_WAITING => 'Hàng chờ',
            $status === ProfitShareLine::STATUS_SKIPPED => 'Không được chia',
            $amount === 0 => 'Không có tiền chia',
            default => 'Được chia',
        };
    }
}
