<?php

namespace App\Services;

use App\Http\Controllers\Backend\DashboardController;
use App\Models\Commission;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PickupPoint;
use App\Models\ProfitSharePeriod;
use App\Models\WalletTransaction;
use App\Support\AffiliateSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Các chỉ số mở rộng của Dashboard (25/11/2026). Mọi khoảng thời gian là [từ, tới) theo kỳ đang chọn (tháng / quý / năm tới
 * hiện tại). Doanh thu = tiền hàng các đơn đã thanh toán, theo paid_at. Truy vấn viết cho cả MySQL và SQLite (test); phần
 * nhóm theo tháng / theo cây làm bằng PHP trên dữ liệu đã lọc.
 */
class DashboardMetrics
{
    public const PAID = [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED];

    /** Số ngày đăng ký mà vẫn chưa kích hoạt thì cần chăm sóc. */
    public const INACTIVE_DAYS = 30;

    private static function paid(CarbonImmutable $from, CarbonImmutable $to)
    {
        return Order::whereIn('status', self::PAID)->where('paid_at', '>=', $from)->where('paid_at', '<', $to);
    }

    /**
     * Doanh thu 12 tháng gần nhất (tính cả tháng này, tới hiện tại): [month Y-m, label m/Y, total, count].
     *
     * @return list<array{month: string, label: string, total: int, count: int}>
     */
    public static function monthlyRevenue(CarbonImmutable $now): array
    {
        $first = $now->startOfMonth()->subMonthsNoOverflow(11);
        $rows = self::paid($first, $now)->get(['paid_at', 'goods_amount'])->groupBy(fn (Order $o) => $o->paid_at->format('Y-m'));

        return collect(range(0, 11))->map(function (int $i) use ($first, $rows) {
            $month = $first->addMonthsNoOverflow($i);
            $orders = $rows[$month->format('Y-m')] ?? collect();

            return ['month' => $month->format('Y-m'), 'label' => $month->format('m/Y'), 'total' => (int) $orders->sum('goods_amount'), 'count' => $orders->count()];
        })->all();
    }

    /**
     * Doanh thu lũy kế kỳ này so với kỳ trước, theo ngày (tháng / quý) hoặc theo tháng (năm), để vẽ biểu đồ hai đường.
     * points: [nhãn trục (rỗng = không in), nhãn tooltip, lũy kế kỳ này (null sau hiện tại), lũy kế kỳ trước (null khi kỳ
     * trước ngắn hơn)]; marks: vị trí các nhãn trục; max: giá trị lớn nhất.
     *
     * @return array{points: list<array{0: string, 1: string, 2: ?int, 3: ?int}>, max: int, current: int, previous: int}
     */
    public static function cumulativeRevenue(string $period, CarbonImmutable $now): array
    {
        [$start, , $prevStart, , $prevEnd] = DashboardController::ranges($period, $now);
        $byMonth = $period === 'nam';
        $end = match ($period) {
            'quy' => $start->addMonthsNoOverflow(3), 'nam' => $start->addYear(), default => $start->addMonthNoOverflow()
        };
        $length = fn (CarbonImmutable $from, CarbonImmutable $to) => $byMonth ? 12 : (int) $from->diffInDays($to);
        $curLength = $length($start, $end);
        $prevLength = $length($prevStart, $prevEnd);
        $bucket = fn (CarbonImmutable $from, $at) => $byMonth ? ($at->year - $from->year) * 12 + $at->month - $from->month : (int) $from->diffInDays(CarbonImmutable::parse($at)->startOfDay());
        $orders = self::paid($prevStart, $now)->get(['paid_at', 'goods_amount']);
        $cur = array_fill(0, $curLength, 0);
        $prev = array_fill(0, $prevLength, 0);
        foreach ($orders as $o) {
            $at = CarbonImmutable::parse($o->paid_at);
            if ($at->gte($start)) {
                $cur[$bucket($start, $at)] += (int) $o->goods_amount;
            } elseif ($at->lt($prevEnd)) {
                $prev[$bucket($prevStart, $at)] += (int) $o->goods_amount;
            }
        }
        $todayIndex = $bucket($start, $now);
        $count = max($curLength, $prevLength);
        $points = [];
        $runCur = $runPrev = 0;
        for ($i = 0; $i < $count; $i++) {
            $runCur += $cur[$i] ?? 0;
            $runPrev += $prev[$i] ?? 0;
            $day = $start->addDays($i);
            [$axis, $tip] = match (true) {
                $byMonth => ['T'.($i + 1), 'Tháng '.($i + 1)],
                $period === 'quy' => [$day->day === 1 && $i < $curLength ? 'T'.$day->month : '', 'Ngày thứ '.($i + 1).' của quý'.($i < $curLength ? ' ('.$day->format('d/m').')' : '')],
                default => [in_array($i + 1, [1, 5, 10, 15, 20, 25, 30], true) ? (string) ($i + 1) : '', 'Ngày '.($i + 1)],
            };
            $points[] = [$axis, $tip, $i <= $todayIndex && $i < $curLength ? $runCur : null, $i < $prevLength ? $runPrev : null];
        }

        return ['points' => $points, 'max' => max($runCur, $runPrev, 1), 'current' => $runCur, 'previous' => $runPrev];
    }

    /** Top sản phẩm bán chạy trong kỳ (theo doanh thu, rồi số lượng): [product_id, name, qty, amount, orders]. */
    public static function topProducts(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): Collection
    {
        return OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', self::PAID)->where('orders.paid_at', '>=', $from)->where('orders.paid_at', '<', $to)
            ->groupBy('order_items.product_id')
            ->selectRaw('order_items.product_id, MAX(order_items.product_name) AS name, SUM(order_items.qty) AS qty, SUM(order_items.line_amount) AS amount, COUNT(DISTINCT order_items.order_id) AS orders')
            ->orderByDesc('amount')->orderByDesc('qty')->limit($limit)->get()
            ->map(fn ($r) => ['product_id' => (int) $r->product_id, 'name' => $r->name, 'qty' => (int) $r->qty, 'amount' => (int) $r->amount, 'orders' => (int) $r->orders]);
    }

    /**
     * Doanh thu theo nơi nhận hàng trong kỳ: giao tận nơi (một dòng) + từng VP tỉnh / điểm nhận: [name, province, count, total].
     *
     * @return list<array{name: string, province: ?string, count: int, total: int}>
     */
    public static function revenueByPickup(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = self::paid($from, $to)->groupBy('delivery_type', 'pickup_point_id')
            ->selectRaw('delivery_type, pickup_point_id, COUNT(*) AS n, SUM(goods_amount) AS total')->get();
        $points = PickupPoint::withTrashed()->whereIn('id', $rows->pluck('pickup_point_id')->filter())->get()->keyBy('id');

        return $rows->map(function ($r) use ($points) {
            $point = $r->pickup_point_id ? ($points[$r->pickup_point_id] ?? null) : null;

            return [
                'name' => $r->delivery_type === Order::DELIVERY_SHIP ? 'Giao tận nơi' : ($point?->name ?? 'Điểm nhận đã xóa'),
                'province' => $point?->provinceName(), 'count' => (int) $r->n, 'total' => (int) $r->total,
            ];
        })->sortByDesc('total')->values()->all();
    }

    /**
     * Chi trả affiliate so với doanh thu trong kỳ: hoa hồng phát sinh (đã cộng ví, gồm phần thuế TNCN) + đồng chia đã duyệt
     * (cộng ví) trong kỳ; ratio = tổng chi / doanh thu (%, null khi chưa có doanh thu).
     */
    public static function payout(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $revenue = (int) self::paid($from, $to)->sum('goods_amount');
        $credited = Commission::where('status', Commission::STATUS_CREDITED)->where('created_at', '>=', $from)->where('created_at', '<', $to);
        $commission = (int) (clone $credited)->sum('amount');
        $profitShare = (int) ProfitSharePeriod::where('approved_at', '>=', $from)->where('approved_at', '<', $to)->sum('distributed_total');

        return [
            'revenue' => $revenue, 'commission' => $commission,
            'commission_cash' => (int) (clone $credited)->sum('cash_amount'), 'commission_reconsume' => (int) (clone $credited)->sum('reconsume_amount'),
            'commission_tax' => (int) (clone $credited)->sum('tax_amount'),
            'profit_share' => $profitShare, 'total' => $commission + $profitShare,
            'ratio' => $revenue > 0 ? round(($commission + $profitShare) / $revenue * 100, 1) : null,
        ];
    }

    /**
     * Số dư ví toàn hệ thống (số tiền công ty đang giữ hộ thành viên): tổng từng ví, số người có số dư, số người / số tiền ví rút
     * đủ mức rút tối thiểu.
     */
    public static function walletBalances(): array
    {
        $balances = WalletTransaction::groupBy('member_id', 'wallet')->selectRaw('member_id, wallet, SUM(amount) AS balance')->get();
        $cash = $balances->where('wallet', WalletTransaction::WALLET_CASH);
        $reconsume = $balances->where('wallet', WalletTransaction::WALLET_RECONSUME);
        $min = (int) AffiliateSettings::get('min_withdraw');
        $withdrawable = $cash->filter(fn ($r) => (int) $r->balance >= $min);

        return [
            'cash' => (int) $cash->sum('balance'), 'cash_members' => $cash->filter(fn ($r) => (int) $r->balance > 0)->count(),
            'reconsume' => (int) $reconsume->sum('balance'), 'reconsume_members' => $reconsume->filter(fn ($r) => (int) $r->balance > 0)->count(),
            'min_withdraw' => $min, 'withdrawable_members' => $withdrawable->count(), 'withdrawable' => (int) $withdrawable->sum('balance'),
        ];
    }

    /**
     * Đơn đặt trong kỳ và tỉ lệ hủy / quá hạn: total, paid (đã thanh toán), open (đang chờ thanh toán), cancelled theo nguồn
     * (khách tự hủy, quản trị hủy, quá hạn — gồm đơn mới đã quá hạn mà lệnh tự hủy chưa chạy), rate (% hủy trên tổng).
     */
    public static function cancellations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = Order::where('created_at', '>=', $from)->where('created_at', '<', $to)->get(['id', 'status', 'cancel_source', 'expires_at']);
        $cancelled = $orders->filter(fn (Order $o) => $o->displayStatus() === Order::STATUS_CANCELLED);
        $source = fn (Order $o) => $o->isOverdue() ? Order::CANCEL_EXPIRED : ($o->cancel_source ?? Order::CANCEL_ADMIN);
        $total = $orders->count();

        return [
            'total' => $total,
            'paid' => $orders->whereIn('status', self::PAID)->count(),
            'open' => $orders->filter(fn (Order $o) => in_array($o->displayStatus(), [Order::STATUS_NEW, Order::STATUS_AWAITING], true))->count(),
            'cancelled' => $cancelled->count(),
            'by_source' => collect(Order::CANCEL_SOURCES)->map(fn ($label, $key) => ['label' => $label, 'count' => $cancelled->filter(fn ($o) => $source($o) === $key)->count()])->all(),
            'rate' => $total > 0 ? round($cancelled->count() / $total * 100, 1) : null,
        ];
    }

    /** Top thành viên giới thiệu được nhiều người đăng ký nhất trong kỳ: [member, count]. */
    public static function topRecruiters(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): Collection
    {
        $counts = Member::whereNotNull('referrer_id')->where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->groupBy('referrer_id')->selectRaw('referrer_id, COUNT(*) AS n')->orderByDesc('n')->orderBy('referrer_id')->limit($limit)->pluck('n', 'referrer_id');
        $members = Member::with('level:id,name')->whereIn('id', $counts->keys())->get(['id', 'full_name', 'referral_code', 'level_id'])->keyBy('id');

        return $counts->map(fn ($n, $id) => ['member' => $members[$id] ?? null, 'count' => (int) $n])->filter(fn ($r) => $r['member'])->values();
    }

    /**
     * Top doanh số nhóm nhỏ trong kỳ theo cây nhị phân: với mỗi thành viên, doanh số kỳ (tiền hàng đơn đã thanh toán) của cả
     * Nhánh A và Nhánh B, nhóm nhỏ = nhánh thấp hơn. Tính một lượt cho cả cây (đọc vị trí mọi thành viên + doanh số kỳ một
     * lần). Chưa tính phần nhánh lớn chuyển sang từ kỳ trước (thuộc phần thưởng tháng, chưa lập trình).
     */
    public static function topTeams(CarbonImmutable $from, CarbonImmutable $to, int $limit = 10): Collection
    {
        $nodes = Member::get(['id', 'parent_id', 'position']);
        $sales = self::paid($from, $to)->whereNotNull('member_id')->groupBy('member_id')->selectRaw('member_id, SUM(goods_amount) AS total')->pluck('total', 'member_id');
        $children = [];
        foreach ($nodes as $node) {
            if ($node->parent_id !== null && in_array($node->position, [BinaryTree::LEFT, BinaryTree::RIGHT], true)) {
                $children[$node->parent_id][$node->position] = $node->id;
            }
        }
        // Tổng cây con của từng người (gồm chính họ): duyệt từ gốc, cộng ngược từ lá lên
        $subtree = [];
        foreach ($nodes->whereNull('parent_id') as $root) {
            $stack = [[$root->id, false]];
            while ($stack) {
                [$id, $done] = array_pop($stack);
                if ($done) {
                    $subtree[$id] = (int) ($sales[$id] ?? 0) + array_sum(array_map(fn ($c) => $subtree[$c] ?? 0, $children[$id] ?? []));

                    continue;
                }
                $stack[] = [$id, true];
                foreach ($children[$id] ?? [] as $child) {
                    $stack[] = [$child, false];
                }
            }
        }

        $ranked = collect($children)->map(function ($kids, $id) use ($subtree) {
            $left = isset($kids[BinaryTree::LEFT]) ? ($subtree[$kids[BinaryTree::LEFT]] ?? 0) : 0;
            $right = isset($kids[BinaryTree::RIGHT]) ? ($subtree[$kids[BinaryTree::RIGHT]] ?? 0) : 0;

            return ['id' => $id, 'left' => $left, 'right' => $right, 'weak' => min($left, $right)];
        })->filter(fn ($r) => $r['weak'] > 0)->sortBy([['weak', 'desc'], ['id', 'asc']])->take($limit)->values();
        $members = Member::with('level:id,name')->whereIn('id', $ranked->pluck('id'))->get(['id', 'full_name', 'referral_code', 'level_id'])->keyBy('id');

        return $ranked->map(fn ($r) => [...$r, 'member' => $members[$r['id']] ?? null])->filter(fn ($r) => $r['member'])->values();
    }

    /** Thành viên đăng ký từ INACTIVE_DAYS ngày trước mà chưa kích hoạt: count + danh sách lâu nhất. */
    public static function inactiveMembers(CarbonImmutable $now, int $limit = 10): array
    {
        $query = Member::whereNull('activated_at')->where('status', Member::STATUS_ACTIVE)->where('created_at', '<=', $now->subDays(self::INACTIVE_DAYS));

        return [
            'count' => (clone $query)->count(),
            'list' => $query->with('referrer:id,full_name,referral_code')->orderBy('created_at')->orderBy('id')->limit($limit)
                ->get(['id', 'full_name', 'phone', 'referral_code', 'referrer_id', 'created_at']),
        ];
    }
}
