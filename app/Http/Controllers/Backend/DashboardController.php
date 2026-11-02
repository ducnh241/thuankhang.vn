<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Hosting;
use App\Models\Payment;
use App\Models\Project;
use App\Support\Renewal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Tổng quan vận hành. Mỗi khối chỉ tính khi người dùng có quyền xem phần tương ứng
 * (khối không có quyền trả null → view ẩn).
 */
class DashboardController extends Controller
{
    /** Số ngày tới để nhắc gia hạn dự án / hosting sắp hết hạn. */
    public const SOON_DAYS = Renewal::SOON_DAYS;

    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $today = CarbonImmutable::today();
        $can = fn (string $ability) => $user->can($ability);

        return view('backend.dashboard', [
            'today' => $today,
            'revenue' => $can('report') || $can('payment') ? $this->revenue($today) : null,
            'projectStats' => $can('project') ? $this->projectStats($today) : null,
            'customerStats' => $can('customer') ? $this->customerStats($today) : null,
            'debt' => $can('report') || $can('project') ? Project::debtSummary() : null,
            'renewals' => $can('renewal') ? $this->renewals($today) : null,
            'hostings' => $can('hosting') ? $this->expiringHostings($today) : null,
            'recentPayments' => $can('payment') ? Payment::query()->whereHas('project')->received()->with('project:id,name,customer_id', 'project.customer:id,name,deleted_at')->latest('paid_at')->latest('id')->limit(6)->get() : null,
            'recentProjects' => $can('project') ? Project::query()->with('customer:id,name,deleted_at')->latest('id')->limit(6)->get(['id', 'name', 'customer_id', 'status', 'price', 'created_at']) : null,
        ]);
    }

    /** Doanh thu tháng này / tháng trước (cùng số ngày đã qua) + 6 tháng gần nhất. */
    private function revenue(CarbonImmutable $today): array
    {
        // whereDate: so theo ngày kể cả khi cột lưu kèm giờ (SQLite) — không bỏ sót khoản thu ngày cuối khoảng
        $sum = fn (CarbonImmutable $a, CarbonImmutable $b) => (float) Payment::query()->whereHas('project')->received()
            ->whereDate('paid_at', '>=', $a->toDateString())->whereDate('paid_at', '<=', $b->toDateString())->sum('amount');

        $monthStart = $today->startOfMonth();
        $current = $sum($monthStart, $today);
        // So công bằng: tháng trước tính tới cùng ngày (10/10 so với 1–10/9)
        $prevStart = $monthStart->subMonthNoOverflow();
        $prevSameDay = $prevStart->addDays(min($today->day, $prevStart->daysInMonth) - 1);
        $previous = $sum($prevStart, $prevSameDay);
        $previousFull = $sum($prevStart, $prevStart->endOfMonth()->startOfDay());

        $months = [];
        for ($i = 5; $i >= 0; $i--) {
            $a = $monthStart->subMonthsNoOverflow($i);
            $months[] = ['label' => 'T'.$a->month, 'value' => $sum($a, $a->endOfMonth()->startOfDay()), 'current' => $i === 0];
        }

        return [
            'current' => $current,
            'previous' => $previous,
            'previous_full' => $previousFull,
            'growth' => $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : ($current > 0 ? null : 0.0),
            'months' => $months,
        ];
    }

    private function projectStats(CarbonImmutable $today): array
    {
        $byStatus = Project::query()->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->map(fn ($c) => (int) $c);

        return [
            'total' => $byStatus->sum(),
            'running' => (int) ($byStatus['in_progress'] ?? 0) + (int) ($byStatus['maintenance'] ?? 0) + (int) ($byStatus['delivered'] ?? 0),
            'in_progress' => (int) ($byStatus['in_progress'] ?? 0),
            'new_this_month' => Project::query()->whereDate('start_date', '>=', $today->startOfMonth()->toDateString())->whereDate('start_date', '<=', $today->toDateString())->count(),
        ];
    }

    private function customerStats(CarbonImmutable $today): array
    {
        return [
            'total' => Customer::count(),
            'new_this_month' => Customer::query()->where('created_at', '>=', $today->startOfMonth())->count(),
        ];
    }

    /** Dự án đến kỳ gia hạn trong SOON_DAYS ngày tới (6 dự án gần nhất), và quá hạn từ đầu năm. */
    private function renewals(CarbonImmutable $today): array
    {
        $summary = Renewal::summary($today, self::SOON_DAYS);

        return [...$summary, 'upcoming' => array_slice($summary['upcoming'], 0, 6)];
    }

    /** Hosting đang dùng hết hạn trong SOON_DAYS ngày tới hoặc đã quá hạn. */
    private function expiringHostings(CarbonImmutable $today)
    {
        return Hosting::query()->where('is_active', true)->whereNotNull('expire_date')
            ->whereDate('expire_date', '<=', $today->addDays(self::SOON_DAYS)->toDateString())
            ->withCount('projects')->orderBy('expire_date')->get();
    }
}
