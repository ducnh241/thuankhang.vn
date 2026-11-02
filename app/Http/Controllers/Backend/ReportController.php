<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Project;
use App\Support\Renewal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Báo cáo doanh thu (như nhánh main):
 * - Doanh thu = tổng tiền đã thu theo ngày thu (paid_at), bỏ khoản thu của dự án đã xóa (giống trang Thanh toán);
 *   phát sinh chưa thu không tính (tới khi bấm "Đã thu").
 * - Công nợ = nợ hợp đồng (giá − thu mới đã thu) + phát sinh chưa thu, tính riêng từng dự án (Project::debtAmount; main
 *   cộng giá sau khi JOIN payments → giá bị nhân theo số khoản thu, ra số sai).
 * - Dự báo gia hạn: tháng đã qua lấy khoản thu gia hạn thực tế, tháng tới lấy phí gia hạn của các dự án đến kỳ
 *   mà chưa gia hạn (cùng cách tính kỳ gia hạn với trang Nhắc gia hạn).
 */
class ReportController extends Controller
{
    public const PERIODS = [
        'this_month' => 'Tháng này',
        'last_month' => 'Tháng trước',
        'this_quarter' => 'Quý này',
        'last_quarter' => 'Quý trước',
        'this_year' => 'Năm nay',
        'last_year' => 'Năm trước',
        'custom' => 'Tùy chọn',
    ];

    public function revenue(Request $request): View
    {
        $today = CarbonImmutable::today();
        $period = is_string($request->query('period')) && array_key_exists($request->query('period'), self::PERIODS) ? $request->query('period') : 'this_month';
        [$from, $to, $prevFrom, $prevTo] = $this->resolvePeriod($period, $request, $today);

        // Toàn bộ tiền đã thu của dự án chưa xóa — vài trăm dòng, gom nhóm trong PHP
        $payments = Payment::query()->whereHas('project')->received()
            ->get(['id', 'project_id', 'amount', 'type', 'paid_at'])
            ->each(fn (Payment $p) => $p->setAttribute('day', $p->paid_at?->toDateString()));
        $between = fn (CarbonImmutable $a, CarbonImmutable $b) => $payments->filter(fn ($p) => $p->day >= $a->toDateString() && $p->day <= $b->toDateString());

        $current = $between($from, $to);
        $previous = $between($prevFrom, $prevTo);
        $sum = fn (Collection $c) => (float) $c->sum(fn ($p) => (float) $p->amount);

        $projects = Project::query()->withDebt()->with(['customer:id,name,company,referrer_customer_id,deleted_at', 'customer.referrer:id,name'])
            ->get(['id', 'customer_id', 'name', 'primary_domain', 'status', 'price', 'currency', 'start_date', 'deliver_date', 'next_renewal_date', 'renewal_hosting_amount', 'renewal_domain_amount']);
        $inRange = fn ($date, CarbonImmutable $a, CarbonImmutable $b) => $date && $date->toDateString() >= $a->toDateString() && $date->toDateString() <= $b->toDateString();
        $newProjects = $projects->filter(fn ($p) => $inRange($p->start_date, $from, $to));
        $newProjectsPrev = $projects->filter(fn ($p) => $inRange($p->start_date, $prevFrom, $prevTo));

        $revenue = $sum($current);
        $revenuePrev = $sum($previous);
        $stats = [
            'revenue' => $revenue,
            'revenue_prev' => $revenuePrev,
            'revenue_growth' => $this->growth($revenue, $revenuePrev),
            'revenue_new' => $sum($current->where('type', '!=', 'renewal')),
            'revenue_renewal' => $sum($current->where('type', 'renewal')),
            'payment_count' => $current->count(),
            'new_value' => (float) $newProjects->sum(fn ($p) => (float) $p->price),
            'new_value_prev' => (float) $newProjectsPrev->sum(fn ($p) => (float) $p->price),
            'new_count' => $newProjects->count(),
            'new_count_prev' => $newProjectsPrev->count(),
            'delivered_count' => $projects->filter(fn ($p) => $inRange($p->deliver_date, $from, $to))->count(),
            'delivered_count_prev' => $projects->filter(fn ($p) => $inRange($p->deliver_date, $prevFrom, $prevTo))->count(),
            'revenue_new_prev' => $sum($previous->where('type', '!=', 'renewal')),
            'revenue_renewal_prev' => $sum($previous->where('type', 'renewal')),
            'payment_count_prev' => $previous->count(),
            // Cùng kỳ năm trước (vd tháng 10/2026 so với tháng 10/2025)
            'revenue_yoy' => $sum($between($from->subYearNoOverflow(), $to->subYearNoOverflow())),
        ];
        $stats['new_value_growth'] = $this->growth($stats['new_value'], $stats['new_value_prev']);
        $stats['revenue_new_growth'] = $this->growth($stats['revenue_new'], $stats['revenue_new_prev']);
        $stats['revenue_renewal_growth'] = $this->growth($stats['revenue_renewal'], $stats['revenue_renewal_prev']);
        $stats['revenue_yoy_growth'] = $this->growth($revenue, $stats['revenue_yoy']);

        // Các kỳ gần nhất cùng loại với kỳ đang xem (kỳ cuối = kỳ đang xem) — để thấy xu hướng
        $trend = [];
        foreach ($this->trendWindows($period, $from, $to) as [$label, $a, $b]) {
            $rows = $between($a, $b);
            $trend[] = [
                'label' => $label,
                'from' => $a,
                'to' => $b,
                'revenue' => $sum($rows),
                'new' => $sum($rows->where('type', '!=', 'renewal')),
                'renewal' => $sum($rows->where('type', 'renewal')),
                'new_value' => (float) $projects->filter(fn ($p) => $inRange($p->start_date, $a, $b))->sum(fn ($p) => (float) $p->price),
                'ongoing' => $today->betweenIncluded($a, $b),
            ];
        }

        // Tuần / tháng / năm hiện tại so với kỳ trước
        $quick = [];
        foreach ([
            'Tuần này' => [$today->startOfWeek(), $today->endOfWeek(), $today->startOfWeek()->subWeek(), $today->startOfWeek()->subDay()],
            'Tháng này' => [$today->startOfMonth(), $today->endOfMonth(), $today->startOfMonth()->subMonthNoOverflow(), $today->startOfMonth()->subDay()],
            'Năm nay' => [$today->startOfYear(), $today->endOfYear(), $today->startOfYear()->subYear(), $today->startOfYear()->subDay()],
        ] as $label => [$a, $b, $pa, $pb]) {
            $cur = $sum($between($a, $b));
            $prev = $sum($between($pa, $pb));
            $quick[$label] = ['current' => $cur, 'previous' => $prev, 'growth' => $this->growth($cur, $prev)];
        }

        // Doanh thu 12 tháng: năm được chọn (mặc định năm của kỳ) so với năm trước
        $year = $request->query('year');
        $year = is_string($year) && ctype_digit($year) ? min(max((int) $year, 2000), $today->year + 1) : $to->year;
        $monthly = [];
        for ($m = 1; $m <= 12; $m++) {
            $monthly[$m] = ['current' => 0.0, 'previous' => 0.0, 'new' => 0.0, 'renewal' => 0.0];
        }
        foreach ($payments as $p) {
            if (! $p->paid_at) {
                continue;
            }
            if ($p->paid_at->year === $year) {
                $monthly[$p->paid_at->month]['current'] += (float) $p->amount;
                $monthly[$p->paid_at->month][$p->type === 'renewal' ? 'renewal' : 'new'] += (float) $p->amount;
            } elseif ($p->paid_at->year === $year - 1) {
                $monthly[$p->paid_at->month]['previous'] += (float) $p->amount;
            }
        }

        // Công nợ hiện tại: từng dự án (trừ đã hủy gia hạn) còn nợ hợp đồng hoặc phát sinh chưa thu
        $paidByProject = $payments->groupBy('project_id')->map(fn ($c) => $sum($c));
        $debts = $projects
            ->reject(fn ($p) => $p->status === 'cancelled')
            ->map(fn ($p) => ['project' => $p, 'paid' => (float) $p->paid_new, 'extra' => (float) $p->unpaid_extra, 'remaining' => $p->debtAmount()])
            ->filter(fn ($d) => $d['remaining'] > 0.01)
            ->sortByDesc('remaining')->values();

        return view('backend.reports.revenue', [
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'prevFrom' => $prevFrom,
            'prevTo' => $prevTo,
            'today' => $today,
            'stats' => $stats,
            'trend' => $trend,
            'quick' => $quick,
            'year' => $year,
            // Năm có khoản thu đầu tiên: nút "năm trước" của biểu đồ theo tháng dừng ở đây
            'firstYear' => min($today->year, (int) ($payments->min(fn ($p) => $p->paid_at?->year) ?? $today->year)),
            'monthly' => $monthly,
            'forecast' => $this->renewalForecast($today, $payments, $projects),
            'debtTotal' => (float) $debts->sum('remaining'),
            'debts' => $debts->take(10),
            'debtCount' => $debts->count(),
            'topCustomers' => $this->topCustomersInPeriod($current, $projects),
            'ranking' => $this->customerRanking($paidByProject, $projects),
        ]);
    }

    /** Khách hàng có doanh thu cao nhất trong kỳ. */
    private function topCustomersInPeriod(Collection $payments, Collection $projects): Collection
    {
        $customerOf = $projects->pluck('customer', 'id');

        return $payments->filter(fn ($p) => $customerOf[$p->project_id] ?? null)
            ->groupBy(fn ($p) => $customerOf[$p->project_id]->id)
            ->map(fn ($c) => [
                'customer' => $customerOf[$c->first()->project_id],
                'total' => (float) $c->sum(fn ($p) => (float) $p->amount),
                'count' => $c->count(),
                'projects' => $c->pluck('project_id')->unique()->count(),
            ])
            ->sortByDesc('total')->take(10)->values();
    }

    /**
     * Xếp hạng khách theo tổng giá trị mang lại: trực tiếp (dự án của họ), giới thiệu (dự án của các KH họ
     * giới thiệu, đệ quy) và tổng — như main.
     *
     * @return array{direct: Collection, downline: Collection, total: Collection}
     */
    private function customerRanking(Collection $paidByProject, Collection $projects): array
    {
        $direct = [];
        foreach ($projects as $p) {
            if ($p->customer_id) {
                $direct[$p->customer_id] = ($direct[$p->customer_id] ?? 0) + ($paidByProject[$p->id] ?? 0);
            }
        }

        $customers = Customer::withTrashed()->get(['id', 'name', 'company', 'referrer_customer_id', 'deleted_at']);
        $children = $customers->whereNotNull('referrer_customer_id')->groupBy('referrer_customer_id');

        $rows = [];
        foreach ($customers as $customer) {
            if ($customer->trashed()) {
                continue;
            }
            // Duyệt cây giới thiệu (có chặn vòng lặp do dữ liệu cũ)
            $downline = 0.0;
            $seen = [$customer->id => true];
            $queue = [$customer->id];
            while ($queue) {
                foreach ($children[array_shift($queue)] ?? [] as $child) {
                    if (! isset($seen[$child->id])) {
                        $seen[$child->id] = true;
                        $queue[] = $child->id;
                        $downline += $direct[$child->id] ?? 0;
                    }
                }
            }
            $own = $direct[$customer->id] ?? 0.0;
            if ($own > 0 || $downline > 0) {
                $rows[] = ['customer' => $customer, 'direct' => $own, 'downline' => $downline, 'total' => $own + $downline];
            }
        }
        $rows = collect($rows);
        $top = fn (string $key) => $rows->filter(fn ($r) => $r[$key] > 0)->sortByDesc($key)->take(20)->values();

        return ['total' => $top('total'), 'direct' => $top('direct'), 'downline' => $top('downline')];
    }

    /**
     * Dòng tiền gia hạn 12 tháng năm nay, mỗi tháng gồm 2 phần:
     * - paid: khoản thu gia hạn thực tế (các tháng đến hết tháng này);
     * - expected: phí gia hạn các dự án đến kỳ từ hôm nay tới cuối năm mà chưa gia hạn (bỏ dự án đã hủy,
     *   dự án dùng hosting của khách). Main coi cả tháng này là "thực tế" → bỏ sót các kỳ còn lại trong tháng.
     */
    private function renewalForecast(CarbonImmutable $today, Collection $payments, Collection $projects): array
    {
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['paid' => 0.0, 'paid_count' => 0, 'expected' => 0.0, 'expected_count' => 0, 'hosting' => 0.0, 'domain' => 0.0, 'total' => 0.0];
        }
        foreach ($payments as $p) {
            if ($p->type === 'renewal' && $p->paid_at && $p->paid_at->year === $today->year && $p->paid_at->month <= $today->month) {
                $months[$p->paid_at->month]['paid'] += (float) $p->amount;
                $months[$p->paid_at->month]['paid_count']++;
            }
        }

        $projects->loadMissing('hostings');
        $end = $today->endOfYear()->startOfDay();
        foreach ($projects as $project) {
            $date = $project->renewalDate();
            if (! $date || ! $project->stillRenews() || $project->selfRenews()) {
                continue;
            }
            $due = Renewal::occurrence($date, $today, $end, $today);
            if ($due && ! Renewal::isRenewed($date, $due)) {
                $months[$due->month]['expected'] += $project->renewalTotal();
                $months[$due->month]['expected_count']++;
                $months[$due->month]['hosting'] += (float) $project->renewal_hosting_amount;
                $months[$due->month]['domain'] += (float) $project->renewal_domain_amount;
            }
        }
        foreach ($months as $m => $row) {
            $months[$m]['total'] = $row['paid'] + $row['expected'];
        }

        $paid = array_sum(array_column($months, 'paid'));
        $expected = array_sum(array_column($months, 'expected'));

        return ['months' => $months, 'actual' => $paid, 'upcoming' => $expected, 'total' => $paid + $expected];
    }

    /**
     * Các kỳ liền nhau cùng độ dài, kết thúc ở kỳ đang xem: 12 tháng / 8 quý / 6 năm / 8 khoảng tùy chọn.
     *
     * @return list<array{0: string, 1: CarbonImmutable, 2: CarbonImmutable}>
     */
    private function trendWindows(string $period, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $windows = [];
        switch ($period) {
            case 'this_quarter':
            case 'last_quarter':
                for ($i = 7; $i >= 0; $i--) {
                    $a = $from->subMonthsNoOverflow(3 * $i)->startOfQuarter();
                    $windows[] = ['Q'.$a->quarter.'/'.$a->format('y'), $a, $a->endOfQuarter()->startOfDay()];
                }
                break;
            case 'this_year':
            case 'last_year':
                for ($i = 5; $i >= 0; $i--) {
                    $a = $from->subYears($i)->startOfYear();
                    $windows[] = [(string) $a->year, $a, $a->endOfYear()->startOfDay()];
                }
                break;
            case 'custom':
                $days = (int) $from->diffInDays($to) + 1;
                for ($i = 7; $i >= 0; $i--) {
                    $a = $from->subDays($days * $i);
                    $b = $a->addDays($days - 1);
                    // Khoảng ngắn: "01/10–07/10"; từ 4 tuần trở lên ghi ngày bắt đầu kèm năm cho khỏi trùng nhãn giữa các năm
                    $windows[] = [match (true) {
                        $days === 1 => $a->format('d/m'),
                        $days < 28 => $a->format('d/m').'–'.$b->format('d/m'),
                        default => $a->format('d/m/y'),
                    }, $a, $b];
                }
                break;
            default: // tháng
                for ($i = 11; $i >= 0; $i--) {
                    $a = $from->subMonthsNoOverflow($i)->startOfMonth();
                    // Nhãn ngắn: chỉ ghi năm ở cột đầu và tháng 1
                    $windows[] = ['T'.$a->month.($i === 11 || $a->month === 1 ? '/'.$a->format('y') : ''), $a, $a->endOfMonth()->startOfDay()];
                }
        }

        return $windows;
    }

    /** % tăng trưởng so với kỳ trước; null = kỳ trước bằng 0 nên không so được. */
    private function growth(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return $current > 0 ? null : 0.0;
        }

        return round(($current - $previous) / $previous * 100, 1);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} kỳ đang xem và kỳ trước liền kề */
    private function resolvePeriod(string $period, Request $request, CarbonImmutable $today): array
    {
        return match ($period) {
            'last_month' => [$m = $today->startOfMonth()->subMonthNoOverflow(), $m->endOfMonth()->startOfDay(), $m->subMonthNoOverflow(), $m->subDay()],
            'this_quarter' => [$q = $today->startOfQuarter(), $q->endOfQuarter()->startOfDay(), $q->subMonthsNoOverflow(3), $q->subDay()],
            'last_quarter' => [$q = $today->startOfQuarter()->subMonthsNoOverflow(3), $q->endOfQuarter()->startOfDay(), $q->subMonthsNoOverflow(3), $q->subDay()],
            'this_year' => [$y = $today->startOfYear(), $y->endOfYear()->startOfDay(), $y->subYear(), $y->subDay()],
            'last_year' => [$y = $today->startOfYear()->subYear(), $y->endOfYear()->startOfDay(), $y->subYear(), $y->subDay()],
            'custom' => $this->customPeriod($request, $today),
            default => [$m = $today->startOfMonth(), $m->endOfMonth()->startOfDay(), $m->subMonthNoOverflow(), $m->subDay()],
        };
    }

    private function customPeriod(Request $request, CarbonImmutable $today): array
    {
        $date = function ($value, CarbonImmutable $default) {
            try {
                $parsed = is_string($value) ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;

                // createFromFormat tự cộng dồn ngày sai (2026-13-45) → so lại chuỗi
                return $parsed && $parsed->format('Y-m-d') === $value && $parsed->year >= 2000 && $parsed->year <= 2100 ? $parsed : $default;
            } catch (\Throwable) {
                return $default;
            }
        };
        $from = $date($request->query('from'), $today->startOfMonth());
        $to = $date($request->query('to'), $today->endOfMonth()->startOfDay());
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        // Kỳ trước có cùng số ngày, liền trước
        $days = (int) $from->diffInDays($to) + 1;

        return [$from, $to, $from->subDays($days), $from->subDay()];
    }
}
