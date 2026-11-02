<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\PaymentRequest;
use App\Models\Payment;
use App\Models\Project;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $filter = $this->filter($request);
        $today = CarbonImmutable::today();

        $base = Payment::query()->whereHas('project'); // bỏ khoản thu của dự án đã xóa
        $this->applyFilter($base, $filter);

        // Tab: tiền đã thu theo loại + tab "Chưa thu" (phát sinh khách chưa trả) — số khoản + tổng tiền, tính trên các bộ
        // lọc còn lại
        $rows = (clone $base)->select('type', 'status', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))->groupBy('type', 'status')->get();
        $stat = fn ($rows) => ['count' => (int) $rows->sum('cnt'), 'total' => (float) $rows->sum('total')];
        $received = $rows->where('status', 'paid');
        $summary = ['all' => $stat($received), 'unpaid' => $stat($rows->where('status', 'unpaid'))];
        foreach (array_keys(Payment::TYPES) as $type) {
            $summary[$type] = $stat($received->filter(fn ($r) => ($r->type ?? 'new') === $type));
        }

        $payments = (clone $base)
            ->when($filter['status'] === 'unpaid', fn ($q) => $q->unpaid(), fn ($q) => $q->received())
            ->when($filter['status'] !== 'unpaid' && $filter['type'] !== '', fn ($q) => $q->where('type', $filter['type']))
            ->with('project.customer')
            ->orderByDesc('paid_at')->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        // Trang vượt quá số trang (vd vừa xóa hết dòng của trang cuối): về trang cuối còn dữ liệu
        if ($payments->isEmpty() && $payments->currentPage() > 1) {
            return redirect($payments->url($payments->lastPage()));
        }

        return view('backend.payments.index', [
            'today' => $today,
            'metrics' => $this->metrics($today),
            'payments' => $payments,
            'filter' => $filter,
            'summary' => $summary,
            'projects' => $this->projectOptions(),
        ]);
    }

    public function store(PaymentRequest $request): RedirectResponse
    {
        $payment = Payment::create($request->validated());
        $message = $payment->isUnpaid()
            ? 'Đã ghi phát sinh '.Money::format($payment->amount).' (chưa thu, tính vào công nợ).'
            : 'Đã thêm khoản thu '.Money::format($payment->amount).'.';

        return back(fallback: route('backend.projects.show', $payment->project_id))->with('success', $message);
    }

    public function update(PaymentRequest $request, Payment $payment): RedirectResponse
    {
        $wasUnpaid = $payment->isUnpaid();
        $payment->update($request->validated());
        $message = $wasUnpaid && ! $payment->isUnpaid()
            ? 'Đã ghi nhận thu '.Money::format($payment->amount).' ngày '.$payment->paid_at->format('d/m/Y').'.'
            : 'Đã cập nhật khoản thu.';

        return back(fallback: route('backend.projects.show', $payment->project_id))->with('success', $message);
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        $payment->delete();

        return back(fallback: route('backend.projects.show', $payment->project_id))->with('success', 'Đã xóa khoản thu '.Money::format($payment->amount).'.');
    }

    public function bulkDestroy(Request $request): RedirectResponse
    {
        $ids = array_filter((array) $request->input('ids', []), 'is_numeric');
        $payments = Payment::whereIn('id', $ids)->get();

        // Xóa từng khoản để cập nhật trạng thái thanh toán của dự án và ghi lịch sử
        DB::transaction(fn () => $payments->each->delete());

        return back(fallback: route('backend.payments.index'))
            ->with($payments->count() ? 'success' : 'error', "Đã xóa {$payments->count()} khoản thu.");
    }

    /**
     * Dải chỉ số đầu trang (không theo bộ lọc): tháng này (tới hôm nay), cả tháng trước, năm nay (tách thu mới / gia
     * hạn), còn phải thu — cùng cách tính với dashboard / báo cáo doanh thu (chỉ tiền đã thu, bỏ khoản thu của dự án
     * đã xóa).
     */
    private function metrics(CarbonImmutable $today): array
    {
        $sum = function (CarbonImmutable $from, CarbonImmutable $to) {
            $rows = Payment::query()->whereHas('project')->received()
                ->whereDate('paid_at', '>=', $from->toDateString())->whereDate('paid_at', '<=', $to->toDateString())
                ->select('type', DB::raw('COUNT(*) as cnt'), DB::raw('SUM(amount) as total'))->groupBy('type')->get()->keyBy('type');

            return [
                'count' => (int) $rows->sum('cnt'),
                'total' => (float) $rows->sum('total'),
                'renewal' => (float) ($rows['renewal']->total ?? 0),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ];
        };
        $prev = $today->startOfMonth()->subMonthNoOverflow();

        return [
            'month' => $sum($today->startOfMonth(), $today),
            'prev_month' => $sum($prev, $prev->endOfMonth()->startOfDay()),
            'year' => $sum($today->startOfYear(), $today),
            'debt' => Project::debtSummary(),
        ];
    }

    /** Dự án (chưa xóa) cho ô chọn trong hộp thoại thêm khoản thu. */
    private function projectOptions()
    {
        return Project::with('customer:id,name')->orderByDesc('id')->get(['id', 'name', 'customer_id']);
    }

    private function filter(Request $request): array
    {
        // Ngày sai kiểu 2026-13-45 bị createFromFormat cộng dồn thành ngày khác → so lại chuỗi
        $date = function (string $value) {
            try {
                return $value !== '' && Carbon::createFromFormat('!Y-m-d', $value)->format('Y-m-d') === $value ? $value : '';
            } catch (\Throwable) {
                return '';
            }
        };

        $from = $date($this->queryString($request, 'from'));
        $to = $date($this->queryString($request, 'to'));
        // Gõ tay URL "từ" sau "đến" → đổi chỗ thay vì ra danh sách trống
        if ($from !== '' && $to !== '' && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [
            'q' => $this->queryString($request, 'q'),
            'type' => array_key_exists($this->queryString($request, 'type'), Payment::TYPES) ? $this->queryString($request, 'type') : '',
            // "unpaid" = tab Chưa thu; còn lại là tiền đã thu
            'status' => $this->queryString($request, 'status') === 'unpaid' ? 'unpaid' : '',
            'method' => array_key_exists($this->queryString($request, 'method'), Payment::METHODS) ? $this->queryString($request, 'method') : '',
            'from' => $from,
            'to' => $to,
        ];
    }

    private function applyFilter(Builder $query, array $filter): void
    {
        if ($filter['q'] !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw("note LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('project', fn ($p) => $p->whereRaw("name LIKE ? ESCAPE '!'", [$like])
                        ->orWhereRaw("primary_domain LIKE ? ESCAPE '!'", [$like])
                        ->orWhereHas('domains', fn ($d) => $d->whereRaw("domain LIKE ? ESCAPE '!'", [$like]))
                        ->orWhereHas('customer', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like])));
            });
        }

        $query->when($filter['method'] !== '', fn ($q) => $q->where('method', $filter['method']))
            ->when($filter['from'] !== '', fn ($q) => $q->whereDate('paid_at', '>=', $filter['from']))
            ->when($filter['to'] !== '', fn ($q) => $q->whereDate('paid_at', '<=', $filter['to']));
    }
}
