<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Nghiệp vụ nhắc gia hạn (giữ như nhánh main):
 * - So ngày gia hạn theo NGÀY–THÁNG, bỏ qua năm, vì năm trong dữ liệu import cũ không chuẩn.
 * - Mỗi dự án có một "kỳ gia hạn" trong khoảng đang xem; "đã gia hạn" = ngày gia hạn kế tiếp đã qua kỳ đó
 *   (main so năm với năm hiện tại → sai ở giao năm: tháng 12 xem hạn tháng 1 năm sau bị báo đã gia hạn).
 */
class Renewal
{
    /** Số ngày tới để nhắc dự án sắp đến kỳ gia hạn (dashboard, trang dự án). */
    public const SOON_DAYS = 30;

    public const KEY_TEMPLATE_HOSTING = 'renewal_template_hosting';
    public const KEY_TEMPLATE_FULL = 'renewal_template_full';

    public const PLACEHOLDERS = [
        '{customer_name}' => 'Tên khách hàng',
        '{project_name}' => 'Tên dự án',
        '{primary_domain}' => 'Domain chính',
        '{hosting_amount}' => 'Phí gia hạn hosting',
        '{domain_amount}' => 'Phí gia hạn domain',
        '{total_amount}' => 'Tổng phí gia hạn',
        '{next_renewal_date}' => 'Ngày gia hạn (dd/mm/yyyy)',
        '{year}' => 'Năm hiện tại',
        '{currency}' => 'Tiền tệ',
    ];

    public const DEFAULT_TEMPLATE_HOSTING = <<<'TXT'
Chào {customer_name},

Hosting cho website {primary_domain} ({project_name}) sắp đến hạn gia hạn vào ngày {next_renewal_date}.

Phí gia hạn hosting năm {year}: {hosting_amount} {currency}

Nhờ anh/chị thanh toán giúp để website tiếp tục hoạt động ổn định.
Cảm ơn anh/chị!
TXT;

    public const DEFAULT_TEMPLATE_FULL = <<<'TXT'
Chào {customer_name},

Đã đến kỳ gia hạn website {primary_domain} (dự án: {project_name}).

Chi tiết gia hạn năm {year}:
- Hosting: {hosting_amount} {currency}
- Tên miền: {domain_amount} {currency}
- Tổng cộng: {total_amount} {currency}

Hạn cuối thanh toán: {next_renewal_date}

Cảm ơn anh/chị!
TXT;

    public static function templates(): array
    {
        return [
            'hosting' => Setting::get(self::KEY_TEMPLATE_HOSTING, self::DEFAULT_TEMPLATE_HOSTING),
            'full' => Setting::get(self::KEY_TEMPLATE_FULL, self::DEFAULT_TEMPLATE_FULL),
        ];
    }

    /** Ngày–tháng của $date đặt vào năm $year (29/02 vào năm không nhuận → 28/02). */
    public static function inYear(Carbon|CarbonImmutable $date, int $year): CarbonImmutable
    {
        $day = min($date->day, CarbonImmutable::create($year, $date->month, 1)->daysInMonth);

        return CarbonImmutable::create($year, $date->month, $day)->startOfDay();
    }

    /** Ngày gia hạn quy về năm hiện tại. */
    public static function dueThisYear(Carbon|CarbonImmutable $date, ?CarbonImmutable $today = null): CarbonImmutable
    {
        return self::inYear($date, ($today ?? CarbonImmutable::today())->year);
    }

    /**
     * Kỳ gia hạn của dự án trong khoảng [from, to]: ngày–tháng gia hạn rơi vào khoảng đó (bỏ qua năm,
     * vì năm trong dữ liệu cũ không chuẩn). Khoảng dài hơn 1 năm có nhiều kỳ → lấy kỳ gần hôm nay nhất.
     * Null nếu ngày–tháng không rơi vào khoảng.
     */
    public static function occurrence(Carbon|CarbonImmutable $date, CarbonImmutable $from, CarbonImmutable $to, ?CarbonImmutable $today = null): ?CarbonImmutable
    {
        $today = ($today ?? CarbonImmutable::today())->startOfDay();
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $best = null;
        for ($year = $from->year; $year <= $to->year; $year++) {
            $candidate = self::inYear($date, $year);
            if ($candidate->betweenIncluded($from, $to)
                && ($best === null || abs($candidate->diffInDays($today)) < abs($best->diffInDays($today)))) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /** Ngày–tháng có nằm trong khoảng [from, to] không — khoảng có thể vắt qua năm (27/12 → 02/01). */
    public static function inRange(Carbon|CarbonImmutable $date, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        return self::occurrence($date, $from, $to) !== null;
    }

    /** Số ngày từ hôm nay tới kỳ gia hạn (âm = đã quá hạn). */
    public static function daysLeft(CarbonImmutable $due, ?CarbonImmutable $today = null): int
    {
        return (int) ($today ?? CarbonImmutable::today())->startOfDay()->diffInDays($due->startOfDay(), false);
    }

    /**
     * Đã gia hạn cho kỳ $due: năm của ngày gia hạn kế tiếp > năm của kỳ đó.
     * Kỳ đang xem nằm trong năm nay thì đúng là "năm gia hạn tiếp theo > năm hiện tại"; so với năm của kỳ
     * (không phải năm của hôm nay) để cuối tháng 12 xem kỳ tháng 1 năm sau không bị báo nhầm là đã gia hạn.
     */
    public static function isRenewed(Carbon|CarbonImmutable $date, CarbonImmutable $due): bool
    {
        return $date->year > $due->year;
    }

    /**
     * Dự án đến kỳ gia hạn trong $days ngày tới mà chưa gia hạn (xếp theo ngày đến hạn), và số dự án quá hạn từ đầu
     * năm — cùng cách tính với trang Nhắc gia hạn (bỏ dự án đã đóng / hủy và dự án khách tự gia hạn).
     */
    public static function summary(CarbonImmutable $today, int $days = self::SOON_DAYS): array
    {
        $upcoming = [];
        $overdue = 0;
        $projects = Project::query()->hasRenewalDate()->stillRenewing()
            ->with(['customer:id,name,deleted_at', 'hostings:id,ownership'])
            ->get(['id', 'name', 'customer_id', 'status', 'next_renewal_date', 'deliver_date', 'renewal_hosting_amount', 'renewal_domain_amount']);

        foreach ($projects as $project) {
            $alerts = $project->renewalAlerts($today, $days);
            if ($alerts['upcoming']) {
                $upcoming[] = ['project' => $project, ...$alerts['upcoming']];
            }
            if ($alerts['overdue']) {
                $overdue++;
            }
        }
        usort($upcoming, fn ($a, $b) => [$a['due'], $a['project']->id] <=> [$b['due'], $b['project']->id]);

        return [
            'upcoming' => $upcoming,
            'upcoming_count' => count($upcoming),
            'upcoming_amount' => array_sum(array_map(fn ($r) => $r['project']->renewalTotal(), $upcoming)),
            'overdue_count' => $overdue,
        ];
    }

    /** Thay các biến {..} trong mẫu thông báo bằng dữ liệu của dự án. */
    public static function render(string $template, Project $project, ?CarbonImmutable $due = null): string
    {
        $hosting = (float) $project->renewal_hosting_amount;
        $domain = (float) $project->renewal_domain_amount;

        return strtr($template, [
            '{customer_name}' => $project->customer?->name ?? '',
            '{project_name}' => $project->name ?? '',
            '{primary_domain}' => $project->primary_domain ?? '',
            '{hosting_amount}' => Money::format($hosting),
            '{domain_amount}' => Money::format($domain),
            '{total_amount}' => Money::format($hosting + $domain),
            '{next_renewal_date}' => ($due ?? $project->renewalDate())?->format('d/m/Y') ?? '',
            '{year}' => (string) ($due?->year ?? now()->year),
            '{currency}' => $project->currency ?: 'VND',
        ]);
    }

    /** Ngày gia hạn kế tiếp sau khi khách trả tiền cho kỳ $due: cùng ngày–tháng, năm sau kỳ đó. */
    public static function nextDate(Project $project, CarbonImmutable $due): CarbonImmutable
    {
        $base = CarbonImmutable::make($project->next_renewal_date ?? $due);

        return self::inYear($base, $due->year + 1);
    }
}
