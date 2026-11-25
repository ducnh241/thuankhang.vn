<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Group;
use App\Models\Level;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\ProfitSharePeriod;
use App\Models\User;
use App\Models\WalletTopup;
use App\Services\DashboardMetrics;
use App\Services\ProfitShare;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dashboard quản trị (A1). Mỗi khối chỉ tính / hiện khi người xem có quyền của mục đó:
 *   - Việc cần xử lý: đơn chờ xác nhận thanh toán, KYC chờ duyệt, lệnh nạp ví chờ xác nhận, đồng chia tháng trước chờ duyệt.
 *   - Doanh thu (order): tiền hàng các đơn ĐÃ THANH TOÁN (gồm đơn trả bằng ví; không tính phí ship COD) — tháng này so với
 *     tháng trước, hôm nay, toàn thời gian; chia theo hình thức thanh toán; số đơn đã thanh toán và giá trị trung bình.
 *   - Thẻ số liệu: sản phẩm, đơn hàng, đánh giá, thành viên, KYC; đơn theo trạng thái; thành viên theo cấp bậc.
 *   - So sánh với kỳ trước (?ky = thang | quy | nam): mỗi chỉ số kỳ này (tới hiện tại), cùng kỳ trước (tới cùng thời điểm —
 *     so công bằng khi kỳ chưa hết), % thay đổi và cả kỳ trước. Chỉ số theo quyền: doanh thu / đơn (order), thành viên
 *     (member), hoa hồng (member | commission), nạp ví (wallet_topup).
 *   - Chỉ số mở rộng (App\Services\DashboardMetrics): doanh thu 12 tháng; theo kỳ đang chọn — top sản phẩm, doanh thu theo
 *     nơi nhận (giao tận nơi / từng VP tỉnh), tỉ lệ hủy / quá hạn, chi trả affiliate / doanh thu, top giới thiệu, top doanh số
 *     nhóm nhỏ; số dư ví toàn hệ thống; thành viên chưa kích hoạt sau 30 ngày.
 *   - Chỉ số hệ thống + hoạt động gần đây (như trước).
 */
class DashboardController extends Controller
{
    private const PAID = [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED];

    /** Kỳ so sánh: khóa trên URL => [nhãn, nhãn kỳ trước]. */
    public const PERIODS = ['thang' => ['Tháng', 'tháng trước'], 'quy' => ['Quý', 'quý trước'], 'nam' => ['Năm', 'năm trước']];

    public function __invoke(Request $request): View
    {
        $can = fn (string $gate) => $request->user()->can($gate);
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();
        $prevStart = $monthStart->subMonthNoOverflow();

        $period = array_key_exists($key = (string) $request->query('ky'), self::PERIODS) ? $key : 'thang';
        [$from, $now] = self::ranges($period, CarbonImmutable::now());
        $seesMoney = $can('member') || $can('commission');

        return view('backend.dashboard', [
            'today' => $today,
            'period' => $period,
            'compare' => $this->compare($period, $can),
            'todo' => $this->todo($can),
            'revenue' => $can('order') ? $this->revenue($today, $monthStart, $prevStart) : null,
            'cards' => array_filter([
                'products' => $can('product') ? $this->products() : null,
                'orders' => $can('order') ? $this->orders($monthStart) : null,
                'reviews' => $can('review') ? $this->reviews() : null,
                'members' => $can('member') ? $this->members($monthStart) : null,
                'kyc' => $can('kyc') ? $this->kyc() : null,
            ]),
            'orderStatuses' => $can('order') ? collect(Order::STATUSES)->map(fn ($label, $status) => [
                'label' => $label, 'count' => Order::whereDisplayStatus($status)->count(), 'url' => route('backend.orders.index', ['tab' => $status]),
            ])->all() : null,
            'levels' => $can('member') ? $this->levels() : null,
            // Chỉ số mở rộng (25/11/2026), theo kỳ đang chọn (?ky)
            'periodLabel' => self::periodLabel($period, $from),
            'monthly' => $can('order') ? DashboardMetrics::monthlyRevenue($now) : null,
            'trend' => $can('order') ? DashboardMetrics::cumulativeRevenue($period, $now) : null,
            'topProducts' => $can('order') ? DashboardMetrics::topProducts($from, $now) : null,
            'byPickup' => $can('order') ? DashboardMetrics::revenueByPickup($from, $now) : null,
            'cancellations' => $can('order') ? DashboardMetrics::cancellations($from, $now) : null,
            'payout' => $can('order') && $seesMoney ? DashboardMetrics::payout($from, $now) : null,
            'wallets' => $seesMoney ? DashboardMetrics::walletBalances() : null,
            'recruiters' => $can('member') ? DashboardMetrics::topRecruiters($from, $now) : null,
            'teams' => $can('member') ? DashboardMetrics::topTeams($from, $now) : null,
            'inactive' => $can('member') ? DashboardMetrics::inactiveMembers($now) : null,
            'stats' => array_filter([
                'users' => $can('user') ? ['Người dùng quản trị', User::visible()->count(), 'team', route('backend.users.index')] : null,
                'groups' => $can('group') ? ['Nhóm quyền', Group::count(), 'safety-certificate', route('backend.groups.index')] : null,
                'pages' => $can('page') ? ['Trang nội dung', Page::count(), 'file-text', route('backend.pages.index')] : null,
            ]),
            'recentLogs' => $can('audit_log')
                ? AuditLog::query()
                    ->where(fn ($q) => $q->whereNull('auditable_type')->orWhere('auditable_type', '!=', User::class)
                        ->orWhereNotIn('auditable_id', User::hiddenIds()))
                    ->latest('id')->limit(8)->get()
                : null,
        ]);
    }

    /**
     * [kỳ này: từ, tới (hiện tại); kỳ trước: từ, tới cùng thời điểm, hết kỳ] cho tháng / quý / năm. "Cùng thời điểm" = đầu kỳ
     * trước + đúng khoảng thời gian đã trôi của kỳ này (không vượt quá hết kỳ trước).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable, 4: CarbonImmutable}
     */
    public static function ranges(string $period, CarbonImmutable $now): array
    {
        [$start, $prevStart] = match ($period) {
            'quy' => [$now->startOfQuarter(), $now->startOfQuarter()->subMonthsNoOverflow(3)],
            'nam' => [$now->startOfYear(), $now->startOfYear()->subYearNoOverflow()],
            default => [$now->startOfMonth(), $now->startOfMonth()->subMonthNoOverflow()],
        };
        $prevToDate = $prevStart->addSeconds((int) $start->diffInSeconds($now));

        return [$start, $now, $prevStart, $prevToDate->min($start), $start];
    }

    /**
     * Bảng so sánh: [kỳ (nhãn + khoảng ngày), rows: [nhãn, kỳ này, cùng kỳ trước, % thay đổi | null, cả kỳ trước, kiểu money|int]].
     */
    private function compare(string $period, callable $can): ?array
    {
        [$start, $now, $prevStart, $prevToDate, $prevEnd] = self::ranges($period, CarbonImmutable::now());
        $rows = [];
        $add = function (string $label, callable $value, string $type = 'int') use (&$rows, $start, $now, $prevStart, $prevToDate, $prevEnd) {
            $current = (int) $value($start, $now);
            $previous = (int) $value($prevStart, $prevToDate);
            $rows[] = [$label, $current, $previous, $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null,
                (int) $value($prevStart, $prevEnd), $type];
        };
        $paid = fn ($from, $to) => Order::whereIn('status', self::PAID)->where('paid_at', '>=', $from)->where('paid_at', '<', $to);

        if ($can('order')) {
            $add('Doanh thu', fn ($from, $to) => $paid($from, $to)->sum('goods_amount'), 'money');
            $add('Đơn đã thanh toán', fn ($from, $to) => $paid($from, $to)->count());
            $add('Giá trị đơn trung bình', fn ($from, $to) => (int) $paid($from, $to)->avg('goods_amount'), 'money');
            $add('Đơn đặt mới', fn ($from, $to) => Order::where('created_at', '>=', $from)->where('created_at', '<', $to)->count());
        }
        if ($can('member')) {
            $add('Thành viên đăng ký mới', fn ($from, $to) => Member::where('created_at', '>=', $from)->where('created_at', '<', $to)->count());
            $add('Thành viên kích hoạt mới', fn ($from, $to) => Member::where('activated_at', '>=', $from)->where('activated_at', '<', $to)->count());
        }
        if ($can('member') || $can('commission')) {
            $add('Hoa hồng phát sinh (trước khi chia ví / thuế)', fn ($from, $to) => Commission::where('status', Commission::STATUS_CREDITED)
                ->where('created_at', '>=', $from)->where('created_at', '<', $to)->sum('amount'), 'money');
        }
        if ($can('wallet_topup')) {
            $add('Tiền nạp ví đã xác nhận', fn ($from, $to) => WalletTopup::where('status', WalletTopup::STATUS_PAID)
                ->where('paid_at', '>=', $from)->where('paid_at', '<', $to)->sum('paid_amount'), 'money');
        }
        if ($rows === []) {
            return null;
        }
        $quarter = fn (CarbonImmutable $d) => 'Quý '.$d->quarter.'/'.$d->year;
        $name = fn (CarbonImmutable $d) => match ($period) {
            'quy' => $quarter($d), 'nam' => 'Năm '.$d->year, default => 'Tháng '.$d->format('m/Y')
        };

        return [
            'current' => $name($start), 'previous' => $name($prevStart),
            'current_range' => $start->format('d/m').' – '.$now->format('d/m/Y H:i'),
            'previous_range' => $prevStart->format('d/m').' – '.$prevToDate->subSecond()->format('d/m/Y H:i'),
            'rows' => $rows,
        ];
    }

    /** "Tháng 11/2026" | "Quý 4/2026" | "Năm 2026" — kỳ đang chọn. */
    public static function periodLabel(string $period, CarbonImmutable $start): string
    {
        return match ($period) {
            'quy' => 'Quý '.$start->quarter.'/'.$start->year,
            'nam' => 'Năm '.$start->year,
            default => 'Tháng '.$start->format('m/Y'),
        };
    }

    /** Việc đang chờ xử lý: [nhãn, số, ghi chú, link]. */
    private function todo(callable $can): array
    {
        $items = [];
        if ($can('order')) {
            $items['orders'] = ['Đơn chờ xác nhận thanh toán', Order::where('status', Order::STATUS_AWAITING)->count(), 'Khách đã báo chuyển khoản', route('backend.orders.index', ['tab' => Order::STATUS_AWAITING])];
        }
        if ($can('kyc')) {
            $items['kyc'] = ['KYC chờ duyệt', MemberKyc::where('status', MemberKyc::STATUS_PENDING)->count(), 'Hồ sơ thành viên gửi', route('backend.kyc.index')];
        }
        if ($can('wallet_topup')) {
            $items['topups'] = ['Lệnh nạp ví chờ xác nhận', WalletTopup::where('status', WalletTopup::STATUS_AWAITING)->count(), 'Thành viên đã gửi bill', route('backend.wallet-topups.index')];
        }
        if ($can('profit_share')) {
            $period = CarbonImmutable::today()->startOfMonth()->subMonthNoOverflow()->format('Y-m');
            $open = ProfitShare::canApproveNow($period) && ! ProfitSharePeriod::where('period', $period)->exists();
            [, $until] = ProfitShare::approveWindow($period);
            $items['profit_share'] = ['Đồng chia chờ duyệt', $open ? 1 : 0,
                $open ? 'Tháng '.substr($period, 5, 2).'/'.substr($period, 0, 4).' — hạn hết '.$until->format('d/m') : 'Duyệt từ ngày 1 đến 5 hằng tháng',
                route('backend.profit-share.show', $period)];
        }

        return $items;
    }

    private function revenue(CarbonImmutable $today, CarbonImmutable $monthStart, CarbonImmutable $prevStart): array
    {
        $paid = fn () => Order::whereIn('status', self::PAID)->whereNotNull('paid_at');
        $month = $paid()->where('paid_at', '>=', $monthStart);
        $thisMonth = (int) (clone $month)->sum('goods_amount');
        $monthCount = (clone $month)->count();
        $lastMonth = (int) $paid()->where('paid_at', '>=', $prevStart)->where('paid_at', '<', $monthStart)->sum('goods_amount');
        // Cùng kỳ tháng trước (tới cùng ngày, cùng giờ) — so sánh công bằng khi tháng này chưa hết
        $sameDayLastMonth = $prevStart->addDays($today->day - 1)->setTimeFrom(now());
        $lastMonthToDate = (int) $paid()->where('paid_at', '>=', $prevStart)->where('paid_at', '<', min($sameDayLastMonth, $monthStart))->sum('goods_amount');
        $byMethod = (clone $month)->selectRaw('payment_method, SUM(goods_amount) AS total, COUNT(*) AS n')->groupBy('payment_method')->get()->keyBy('payment_method');

        return [
            'month' => $thisMonth,
            'month_count' => $monthCount,
            'average' => $monthCount ? intdiv($thisMonth, $monthCount) : 0,
            'last_month' => $lastMonth,
            'last_month_to_date' => $lastMonthToDate,
            'change' => $lastMonthToDate > 0 ? round(($thisMonth - $lastMonthToDate) / $lastMonthToDate * 100) : null,
            'today' => (int) $paid()->where('paid_at', '>=', $today)->sum('goods_amount'),
            'total' => (int) $paid()->sum('goods_amount'),
            'by_method' => collect(Order::PAYMENT_METHODS)->map(fn ($label, $method) => [
                'label' => $label, 'total' => (int) ($byMethod[$method]->total ?? 0), 'count' => (int) ($byMethod[$method]->n ?? 0),
            ])->all(),
        ];
    }

    /** Thẻ: [tiêu đề, số chính, icon, link, [[nhãn phụ, số], …]]. */
    private function products(): array
    {
        return ['Sản phẩm', Product::count(), 'shop', route('backend.products.index'), [
            ['Đang bán', Product::where('status', true)->count()],
            ['Ngừng bán', Product::where('status', false)->count()],
            ['Hết hàng', Product::where('status', true)->whereNotNull('stock')->where('stock', '<=', 0)->count()],
        ]];
    }

    private function orders(CarbonImmutable $monthStart): array
    {
        return ['Đơn hàng', Order::count(), 'container', route('backend.orders.index'), [
            ['Đặt tháng này', Order::where('created_at', '>=', $monthStart)->count()],
            ['Đã thanh toán', Order::whereIn('status', self::PAID)->count()],
            ['Đã hủy', Order::whereDisplayStatus(Order::STATUS_CANCELLED)->count()],
        ]];
    }

    private function reviews(): array
    {
        $avg = ProductReview::where('is_visible', true)->avg('rating');

        return ['Đánh giá', ProductReview::count(), 'star', route('backend.reviews.index'), [
            ['Điểm trung bình', $avg === null ? '—' : number_format((float) $avg, 1, ',', '.').' ★'],
            ['Đang hiện', ProductReview::where('is_visible', true)->count()],
            ['Đang ẩn', ProductReview::where('is_visible', false)->count()],
        ]];
    }

    private function members(CarbonImmutable $monthStart): array
    {
        return ['Thành viên', Member::count(), 'contacts', route('backend.members.index'), [
            ['Đã kích hoạt', Member::whereNotNull('activated_at')->count()],
            ['Mới tháng này', Member::where('created_at', '>=', $monthStart)->count()],
            ['Bị khóa', Member::where('status', Member::STATUS_LOCKED)->count()],
        ]];
    }

    private function kyc(): array
    {
        $by = MemberKyc::selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        return ['Hồ sơ KYC', (int) $by->sum(), 'safety-certificate', route('backend.kyc.index', ['tab' => 'all']), [
            ['Chờ duyệt', (int) ($by[MemberKyc::STATUS_PENDING] ?? 0)],
            ['Đã duyệt', (int) ($by[MemberKyc::STATUS_APPROVED] ?? 0)],
            ['Không duyệt', (int) ($by[MemberKyc::STATUS_REJECTED] ?? 0)],
        ]];
    }

    /** Thành viên theo cấp bậc hiện tại (thứ tự cấu hình): [tên, số người, % tổng]. */
    private function levels(): array
    {
        $counts = Member::selectRaw('level_id, COUNT(*) AS n')->groupBy('level_id')->pluck('n', 'level_id');
        $total = max(1, (int) $counts->sum());

        return Level::ordered()->get(['id', 'name', 'threshold_amount', 'active'])->map(fn (Level $level) => [
            'name' => $level->name, 'threshold' => (int) $level->threshold_amount, 'active' => (bool) $level->active,
            'count' => (int) ($counts[$level->id] ?? 0), 'percent' => round(($counts[$level->id] ?? 0) / $total * 100, 1),
        ])->all();
    }
}
