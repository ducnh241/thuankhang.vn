<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Setting;
use App\Support\Money;
use App\Support\Renewal;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RenewalController extends Controller
{
    public const PERIODS = [
        'this_week' => 'Tuần này',
        'this_month' => 'Tháng này',
        'next_month' => 'Tháng tới',
        'overdue' => 'Quá hạn',
        'custom' => 'Tùy chọn',
    ];

    /** Nhóm tình trạng của dự án trong kỳ (xem index()), theo đúng thứ tự hiển thị trong bảng. */
    public const STATES = ['not_renewed', 'self_renew', 'renewed', 'cancelled'];

    /**
     * Lọc theo hosting: mine = có ít nhất một hosting của mình (mình lo gia hạn hosting); customer = chỉ chạy trên hosting
     * của khách (như Project::selfRenews). Dự án chưa gắn hosting chỉ hiện khi không lọc.
     */
    public const HOSTINGS = ['mine' => 'Của mình', 'customer' => 'Của khách'];

    public function index(Request $request): View
    {
        $filter = $this->filter($request);
        $today = CarbonImmutable::today();
        [$from, $to] = $this->range($filter, $today);

        // Lọc ngày–tháng trong PHP (bỏ qua năm) — chạy được cả MySQL lẫn SQLite, dữ liệu vài trăm dòng
        // Mọi dự án có ngày gia hạn — hoặc chưa có thì theo ngày bàn giao — kể cả đã hủy / đã đóng (hiện ở nhóm hủy để soát lại)
        $candidates = Project::query()
            ->hasRenewalDate()
            ->with(['customer', 'hostings'])
            ->get();

        // Kỳ gia hạn của từng dự án trong khoảng đang xem (lọc ngày–tháng trong PHP — chạy được cả MySQL lẫn SQLite)
        $due = [];
        foreach ($candidates as $project) {
            if ($occurrence = Renewal::occurrence($project->renewalDate(), $from, $to, $today)) {
                $due[$project->id] = $occurrence;
            }
        }
        // Mỗi dự án trong kỳ thuộc đúng một nhóm (tô màu nền + tab lọc):
        //   cancelled    đã hủy gia hạn / đã đóng — không gia hạn nữa
        //   renewed      đã gia hạn: năm của ngày gia hạn kế tiếp > năm của kỳ đang xem
        //   self_renew   chưa gia hạn nhưng chỉ dùng hosting của khách — khách tự gia hạn, không qua mình
        //   not_renewed  còn lại: chưa gia hạn, mình phải lo (hosting của mình hoặc chưa gắn hosting)
        $states = [];
        $byId = $candidates->keyBy('id');
        foreach ($due as $id => $occurrence) {
            $project = $byId[$id];
            $states[$id] = match (true) {
                ! $project->stillRenews() => 'cancelled',
                Renewal::isRenewed($project->renewalDate(), $occurrence) => 'renewed',
                $project->selfRenews() => 'self_renew',
                default => 'not_renewed',
            };
        }

        // Mọi kỳ (kể cả "Quá hạn" = đến hạn từ 01/01 tới hôm qua) hiện đủ các nhóm; tab "Chưa gia hạn" là phần còn nợ.
        // Lọc hosting áp trước khi đếm: số trên tab và tiền dự kiến theo đúng bộ lọc đang chọn
        $inPeriod = $candidates->filter(fn (Project $p) => isset($due[$p->id]) && match ($filter['hosting']) {
            'mine' => $p->hostings->contains('ownership', 'mine'),
            'customer' => $p->selfRenews(),
            default => true,
        });

        $counts = ['all' => $inPeriod->count()] + array_map(
            fn ($state) => $inPeriod->filter(fn ($p) => $states[$p->id] === $state)->count(),
            array_combine(self::STATES, self::STATES),
        );

        $projects = $inPeriod
            ->when($filter['renewed'] !== '', fn ($c) => $c->filter(fn ($p) => $states[$p->id] === $filter['renewed']))
            // Theo cụm: chưa gia hạn → đã gia hạn → đã hủy (thứ tự trong STATES); trong cụm theo ngày đến hạn tăng dần
            // (chưa gia hạn: "còn x ngày" tăng dần — quá hạn lâu nhất lên đầu)
            ->sortBy(fn ($p) => array_search($states[$p->id], self::STATES, true).'-'.$due[$p->id]->toDateString().'-'.str_pad((string) $p->id, 8, '0', STR_PAD_LEFT))
            ->values();

        // Tiền dự kiến thu: các dự án mình còn phải gia hạn (không tính khách tự gia hạn, đã hủy / đã đóng)
        $expected = (float) $projects->filter(fn ($p) => $states[$p->id] === 'not_renewed')
            ->sum(fn ($p) => $p->renewalTotal());

        $templates = Renewal::templates();
        $messages = [];
        foreach ($projects as $project) {
            // Chỉ soạn thông báo cho dự án dùng hosting của mình (hosting của khách thì khách tự lo — như main),
            // bỏ dự án đã hủy gia hạn / đã đóng
            if ($states[$project->id] !== 'cancelled' && $project->hostings->contains('ownership', 'mine')) {
                $messages[$project->id] = [
                    'hosting' => Renewal::render($templates['hosting'], $project, $due[$project->id]),
                    'full' => Renewal::render($templates['full'], $project, $due[$project->id]),
                ];
            }
        }

        return view('backend.renewals.index', [
            'projects' => $projects,
            'filter' => $filter,
            'from' => $from,
            'to' => $to,
            'today' => $today,
            'due' => $due,
            'states' => $states,
            'counts' => $counts,
            'expected' => $expected,
            'messages' => $messages,
            // Không có cả ngày gia hạn lẫn ngày bàn giao → không xếp được vào kỳ nào: báo riêng để không bị sót
            'undated' => Project::whereNull('next_renewal_date')->whereNull('deliver_date')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Khách đã gia hạn: ghi khoản thu loại "gia hạn" và lùi ngày gia hạn kế tiếp thêm 1 năm. */
    public function renew(Request $request, Project $project): RedirectResponse
    {
        // Giao diện chỉ có nút gia hạn ở nhóm "chưa gia hạn"; chặn cả request gửi thẳng / trang mở đã lâu
        // (báo ở đầu trang: hộp thoại chỉ mở lại khi có dữ liệu form cũ)
        if ($reason = $this->cannotRenew($project)) {
            return back(fallback: route('backend.renewals.index'))->with('error', "Không ghi nhận gia hạn \"{$project->name}\": {$reason}");
        }

        foreach (['hosting_amount', 'domain_amount'] as $field) {
            $raw = $request->input($field);
            if (is_string($raw)) {
                $request->merge([$field => Money::parse($raw) ?? $raw]);
            }
        }

        $data = $request->validateWithBag('renew', [
            'hosting_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'domain_amount' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'due' => ['required', 'date_format:Y-m-d'],
            // Như form khoản thu: thu trước tối đa 1 năm
            'paid_at' => ['required', 'date_format:Y-m-d', 'after:1900-01-01', 'before_or_equal:'.now()->addYear()->toDateString()],
            'method' => ['nullable', Rule::in(array_keys(Payment::METHODS))],
            'note' => ['nullable', 'string', 'max:1000'],
        ], [], ['due' => 'kỳ gia hạn', 'hosting_amount' => 'phí hosting', 'domain_amount' => 'phí domain', 'paid_at' => 'ngày thu', 'method' => 'phương thức', 'note' => 'ghi chú']);

        $due = CarbonImmutable::createFromFormat('!Y-m-d', $data['due']);
        // Kỳ gửi lên phải là ngày–tháng gia hạn của dự án, trong vòng 1 năm quanh hôm nay
        $date = $project->renewalDate();
        if (! Renewal::inYear($date, $due->year)->equalTo($due) || abs($due->diffInDays(today())) > 366) {
            return back()->withErrors(['due' => 'Kỳ gia hạn không hợp lệ, vui lòng tải lại trang.'], 'renew')->withInput();
        }
        // Đã gia hạn kỳ này rồi (bấm 2 lần, 2 tab, người khác vừa gia hạn) → không ghi thêm khoản thu
        if (Renewal::isRenewed($date, $due)) {
            return back()->withErrors(['due' => "Dự án đã được gia hạn cho kỳ {$due->format('d/m/Y')} (ngày gia hạn kế tiếp: {$date->format('d/m/Y')})."], 'renew')->withInput();
        }

        $total = (float) $data['hosting_amount'] + (float) $data['domain_amount'];
        if ($total <= 0) {
            return back()->withErrors(['hosting_amount' => 'Tổng tiền gia hạn phải lớn hơn 0. Nếu gia hạn không thu tiền, hãy sửa ngày gia hạn trong dự án.'], 'renew')
                ->withInput();
        }

        $oldDate = $date->format('d/m/Y');
        $newDate = Renewal::nextDate($project, $due);

        $saved = DB::transaction(function () use ($project, $data, $total, $newDate, $due) {
            // Khóa dòng dự án rồi kiểm tra lại: 2 request gửi cùng lúc chỉ 1 cái ghi được
            $current = Project::whereKey($project->id)->lockForUpdate()->first()?->renewalDate();
            if ($current && Renewal::isRenewed($current, $due)) {
                return false;
            }

            $project->update(['next_renewal_date' => $newDate->toDateString()]);

            $breakdown = 'Hosting: '.Money::format($data['hosting_amount']).((float) $data['domain_amount'] > 0 ? ' + Domain: '.Money::format($data['domain_amount']) : '');
            Payment::create([
                'project_id' => $project->id,
                'amount' => $total,
                'type' => 'renewal',
                'paid_at' => $data['paid_at'],
                'method' => $data['method'] ?? null,
                'note' => trim((($data['note'] ?? '') ?: 'Gia hạn năm '.$due->year).' ['.$breakdown.']'),
            ]);

            return true;
        });
        if (! $saved) {
            return back()->withErrors(['due' => "Dự án vừa được gia hạn cho kỳ {$due->format('d/m/Y')}."], 'renew')->withInput();
        }

        return back(fallback: route('backend.renewals.index'))->with('success',
            "Đã gia hạn \"{$project->name}\": thu ".Money::format($total)." {$project->currency}. Ngày gia hạn kế tiếp: {$oldDate} → {$newDate->format('d/m/Y')}.");
    }

    /**
     * Khách hủy gia hạn: chuyển trạng thái "Đã hủy gia hạn", ghi lý do vào ghi chú. Giữ ngày gia hạn để dự án vẫn hiện
     * ở nhóm "Đã hủy gia hạn" đúng kỳ của nó (soát lại được) — trạng thái đã loại nó khỏi nhắc / dự kiến thu.
     */
    public function cancel(Request $request, Project $project): RedirectResponse
    {
        // Đã hủy gia hạn / đã đóng: không ghi đè trạng thái, không thêm dòng ghi chú lặp
        if (! $project->stillRenews()) {
            return back(fallback: route('backend.renewals.index'))->with('error', "\"{$project->name}\" đang ở trạng thái \"{$project->statusLabel()}\", không cần hủy gia hạn nữa.");
        }

        $data = $request->validateWithBag('cancel', [
            'reason' => ['required', 'string', 'max:1000'],
        ], [], ['reason' => 'lý do']);

        $line = '[Khách hủy gia hạn ngày '.now()->format('d/m/Y').'] Lý do: '.trim($data['reason']);

        $project->update([
            'status' => 'cancelled',
            'note' => $project->note ? $project->note."\n".$line : $line,
        ]);

        return back(fallback: route('backend.renewals.index'))->with('success', "Đã ghi nhận \"{$project->name}\" hủy gia hạn.");
    }

    public function editTemplate(): View
    {
        // Xem trước bằng một dự án thật đang dùng hosting của mình
        $sample = Project::whereNotNull('next_renewal_date')
            ->whereHas('hostings', fn ($q) => $q->where('ownership', 'mine'))
            ->with('customer')->latest('id')->first();

        return view('backend.renewals.template', [
            'templates' => Renewal::templates(),
            'sample' => $sample,
        ]);
    }

    public function updateTemplate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'template_hosting' => ['required', 'string', 'max:5000'],
            'template_full' => ['required', 'string', 'max:5000'],
        ], [], ['template_hosting' => 'mẫu thông báo hosting', 'template_full' => 'mẫu thông báo đầy đủ']);

        Setting::put(Renewal::KEY_TEMPLATE_HOSTING, $data['template_hosting']);
        Setting::put(Renewal::KEY_TEMPLATE_FULL, $data['template_full']);

        return redirect()->route('backend.renewals.template')->with('success', 'Đã lưu mẫu thông báo gia hạn.');
    }

    private function filter(Request $request): array
    {
        // Chỉ nhận chuỗi (?status[]=1 không làm lỗi trang)
        $q = fn (string $key) => is_string($value = $request->query($key)) ? $value : '';
        // Ngày phải đúng định dạng và có thật (2026-02-30 không bị PHP âm thầm đổi thành 02/03)
        $date = function (string $value) {
            try {
                $parsed = $value !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $value) : null;
            } catch (\Throwable) {
                return '';
            }

            return $parsed && $parsed->format('Y-m-d') === $value ? $value : '';
        };

        return [
            // Mới vào trang: tuần này
            'period' => array_key_exists($q('period'), self::PERIODS) ? $q('period') : 'this_week',
            'from' => $date($q('from')),
            'to' => $date($q('to')),
            'renewed' => in_array($q('renewed'), self::STATES, true) ? $q('renewed') : '',
            'hosting' => array_key_exists($q('hosting'), self::HOSTINGS) ? $q('hosting') : '',
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(array $filter, CarbonImmutable $today): array
    {
        return match ($filter['period']) {
            'this_week' => [$today->startOfWeek(), $today->endOfWeek()->startOfDay()],
            'next_month' => [$today->startOfMonth()->addMonthNoOverflow(), $today->startOfMonth()->addMonthNoOverflow()->endOfMonth()->startOfDay()],
            'custom' => $this->customRange($filter, $today),
            'overdue' => [$today->startOfYear(), $today->subDay()],
            default => [$today->startOfMonth(), $today->endOfMonth()->startOfDay()],
        };
    }

    /** Kỳ tùy chọn tối đa 1 năm (mỗi dự án chỉ có 1 kỳ gia hạn / năm; khoảng quá dài làm vòng lặp theo năm phình to). */
    public const CUSTOM_MAX_DAYS = 366;

    private function customRange(array $filter, CarbonImmutable $today): array
    {
        $from = $filter['from'] ? CarbonImmutable::parse($filter['from']) : $today->startOfMonth();
        $to = $filter['to'] ? CarbonImmutable::parse($filter['to']) : $today->endOfMonth()->startOfDay();
        [$from, $to] = $to->lt($from) ? [$to, $from] : [$from, $to];

        return [$from, $to->min($from->addDays(self::CUSTOM_MAX_DAYS - 1))];
    }

    /** Lý do không ghi nhận gia hạn được (khớp với việc giao diện ẩn nút), null nếu được. */
    private function cannotRenew(Project $project): ?string
    {
        return match (true) {
            ! $project->stillRenews() => "Dự án đang ở trạng thái \"{$project->statusLabel()}\". Đổi trạng thái dự án trước nếu khách gia hạn lại.",
            ! $project->renewalDate() => 'Dự án chưa có ngày gia hạn lẫn ngày bàn giao. Hãy nhập ngày trong dự án trước.',
            $project->selfRenews() => 'Dự án chỉ dùng hosting của khách — khách tự gia hạn, không ghi nhận khoản thu ở đây.',
            default => null,
        };
    }
}
