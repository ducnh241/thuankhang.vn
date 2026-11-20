<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Services\CommissionEngine;
use App\Support\AffiliateSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Ví hoa hồng của tôi (/tai-khoan/vi, mở từ ô "Ví hoa hồng" ở trang tài khoản): số dư ví rút tiền + ví tái tiêu dùng;
 * lịch sử hoa hồng theo kỳ (?ky = tuan | thang | thang-truoc | nam, mặc định tháng này) kèm tổng của kỳ so với kỳ trước và
 * biểu đồ lũy kế hai kỳ. Mỗi dòng lịch sử gọn (tầng, người mua, ngày, mã đơn, số vào ví rút tiền); bấm mở popup chi tiết
 * cách tính: tiền hàng (từng sản phẩm) − phí doanh nghiệp = cơ sở × tỉ lệ = hoa hồng → ví rút / tái tiêu dùng / thuế TNCN.
 * Chỉ hiện dòng đã được cộng; không hiện số điện thoại / email của người mua.
 */
class WalletController extends Controller
{
    /** Kỳ lọc: khóa trên URL => [nhãn, nhãn kỳ trước]. */
    public const PERIODS = [
        'tuan' => ['Tuần này', 'Tuần trước'],
        'thang' => ['Tháng này', 'Tháng trước'],
        'thang-truoc' => ['Tháng trước', 'Tháng trước nữa'],
        'nam' => ['Năm nay', 'Năm trước'],
    ];

    public const DEFAULT_PERIOD = 'thang';

    public function __invoke(Request $request): View
    {
        $member = $request->user('member')->load('level');
        $period = array_key_exists($key = (string) $request->query('ky'), self::PERIODS) ? $key : self::DEFAULT_PERIOD;
        [$start, $end, $prevStart, $prevEnd] = self::range($period, now());

        $lines = $member->commissions()->credited()->where('created_at', '>=', $start)->where('created_at', '<', $end)
            ->with(['order:id,code', 'order.items:id,order_id,product_name,variant_name,qty,line_amount', 'buyer:id,full_name'])
            ->paginate(20)->withQueryString();

        $rows = $member->commissions()->credited()->where('created_at', '>=', $prevStart)->where('created_at', '<', $end)
            ->get(['created_at', 'amount', 'cash_amount', 'reconsume_amount', 'tax_amount', 'tax_percent']);
        $current = $rows->filter(fn (Commission $line) => $line->created_at->gte($start));
        $previous = $rows->filter(fn (Commission $line) => $line->created_at->lt($prevEnd));

        return view('frontend.wallet', [
            'member' => $member,
            'wallet' => $member->walletSummary(),
            'feePercent' => AffiliateSettings::get('business_fee_percent'),
            'split' => CommissionEngine::split(0),
            'period' => $period,
            'lines' => $lines,
            'totals' => [
                'amount' => (int) $current->sum('amount'),
                'cash' => (int) $current->sum(fn ($line) => $line->tax_percent === null ? $line->amount : $line->cash_amount),
                'reconsume' => (int) $current->sum('reconsume_amount'),
                'tax' => (int) $current->sum('tax_amount'),
                'previous' => (int) $previous->sum('amount'),
            ],
            'chart' => self::chart($period, $start, $end, $prevStart, $current, $previous),
        ]);
    }

    /**
     * [bắt đầu, kết thúc (không gồm), bắt đầu kỳ trước, kết thúc kỳ trước] của kỳ $period tính tại $now.
     *
     * @return array{0: Carbon, 1: Carbon, 2: Carbon, 3: Carbon}
     */
    public static function range(string $period, Carbon $now): array
    {
        [$start, $unit] = match ($period) {
            'tuan' => [$now->copy()->startOfWeek(Carbon::MONDAY), 'week'],
            'thang-truoc' => [$now->copy()->startOfMonth()->subMonthNoOverflow(), 'month'],
            'nam' => [$now->copy()->startOfYear(), 'year'],
            default => [$now->copy()->startOfMonth(), 'month'],
        };
        $end = $start->copy()->add(1, $unit);
        $prevStart = $start->copy()->sub(1, $unit);

        return [$start, $end, $prevStart, $start->copy()];
    }

    /**
     * Dữ liệu biểu đồ lũy kế hoa hồng hai kỳ, theo ngày (tuần / tháng) hoặc theo tháng (năm). Kỳ hiện tại dừng ở hôm nay
     * (điểm sau đó null). points: [nhãn trục, nhãn tooltip, lũy kế kỳ này | null, lũy kế kỳ trước | null].
     *
     * @return array{points: list<array{0: string, 1: string, 2: ?int, 3: ?int}>, max: int}
     */
    private static function chart(string $period, Carbon $start, Carbon $end, Carbon $prevStart, $current, $previous): array
    {
        $byMonth = $period === 'nam';
        $count = match (true) {
            $byMonth => 12,
            $period === 'tuan' => 7,
            default => max($start->daysInMonth, $prevStart->daysInMonth),
        };
        $bucket = fn (Commission $line, Carbon $from) => $byMonth ? $line->created_at->month - 1 : (int) $from->diffInDays($line->created_at->copy()->startOfDay());
        $sums = fn ($lines, Carbon $from) => $lines->groupBy(fn ($line) => $bucket($line, $from))->map->sum('amount');
        $cur = $sums($current, $start);
        $prev = $sums($previous, $prevStart);
        $length = fn (Carbon $from) => $byMonth || $period === 'tuan' ? $count : $from->daysInMonth;
        $todayIndex = now()->lt($end) ? ($byMonth ? now()->month - 1 : (int) $start->diffInDays(now()->startOfDay())) : $count - 1;
        $weekdays = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

        $points = [];
        $runCur = $runPrev = 0;
        for ($i = 0; $i < $count; $i++) {
            $runCur += (int) ($cur[$i] ?? 0);
            $runPrev += (int) ($prev[$i] ?? 0);
            [$axis, $tip] = match (true) {
                $byMonth => ['T'.($i + 1), 'Tháng '.($i + 1)],
                $period === 'tuan' => [$weekdays[$i], $weekdays[$i] === 'CN' ? 'Chủ nhật' : 'Thứ '.($i + 2)],
                default => [(string) ($i + 1), 'Ngày '.($i + 1)],
            };
            $points[] = [$axis, $tip, $i <= $todayIndex && $i < $length($start) ? $runCur : null, $i < $length($prevStart) ? $runPrev : null];
        }

        return ['points' => $points, 'max' => max($runCur, $runPrev)];
    }
}
