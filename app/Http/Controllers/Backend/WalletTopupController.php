<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Order;
use App\Models\WalletTopup;
use App\Services\TopupPayment;
use App\Support\AffiliateSettings;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Nạp ví tái tiêu dùng (quyền wallet_topup; thao tác: wallet_topup/confirm — mặc định nhóm Kế toán): danh sách (tab trạng
 * thái — mặc định "Chờ xác nhận", tìm mã lệnh / mã giao dịch / tên / SĐT / mã giới thiệu), chi tiết + ảnh bill, xác nhận đã
 * nhận tiền (mã giao dịch ngân hàng duy nhất toàn hệ thống — chung với đơn hàng; số tiền thực nhận ≥ số trên lệnh, cộng đúng
 * số thực nhận), chưa nhận được tiền (trả về "Chờ chuyển khoản" để khách chuyển bù), hủy lệnh có lý do.
 */
class WalletTopupController extends Controller
{
    public const TABS = [
        WalletTopup::STATUS_AWAITING => 'Chờ xác nhận',
        WalletTopup::STATUS_NEW => 'Chờ chuyển khoản',
        WalletTopup::STATUS_PAID => 'Đã cộng ví',
        WalletTopup::STATUS_CANCELLED => 'Đã hủy',
        'all' => 'Tất cả',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $tab = $this->queryString($request, 'tab');
        $filter = ['q' => $this->queryString($request, 'q'), 'tab' => array_key_exists($tab, self::TABS) ? $tab : WalletTopup::STATUS_AWAITING];

        $base = WalletTopup::query()->when($filter['q'] !== '', function (Builder $query) use ($filter) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
            $phone = Member::normalizePhone($filter['q']);
            $query->where(fn (Builder $q) => $q->whereRaw("code LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("payment_reference LIKE ? ESCAPE '!'", [$like])
                ->orWhereHas('member', fn (Builder $m) => $m->where(fn (Builder $m) => $m
                    ->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("referral_code LIKE ? ESCAPE '!'", [$like])
                    ->when($phone, fn (Builder $m) => $m->orWhere('phone', $phone)))));
        });

        $counts = ['all' => (clone $base)->count()];
        foreach (array_keys(WalletTopup::STATUSES) as $status) {
            $counts[$status] = (clone $base)->whereDisplayStatus($status)->count();
        }

        $topups = (clone $base)
            ->when($filter['tab'] !== 'all', fn (Builder $q) => $q->whereDisplayStatus($filter['tab']))
            ->with('member:id,full_name,phone,referral_code')
            // Hàng chờ: báo trước xử lý trước; các tab khác: mới nhất trước
            ->when($filter['tab'] === WalletTopup::STATUS_AWAITING, fn (Builder $q) => $q->orderBy('confirm_requested_at')->orderBy('id'), fn (Builder $q) => $q->orderByDesc('id'))
            ->paginate(30)
            ->withQueryString();

        if ($topups->isEmpty() && $topups->currentPage() > 1) {
            return redirect($topups->url($topups->lastPage()));
        }

        return view('backend.wallet_topups.index', compact('topups', 'filter', 'counts'));
    }

    public function show(WalletTopup $topup): View
    {
        $topup->load(['member.level', 'confirmer', 'walletTransaction']);

        return view('backend.wallet_topups.show', [
            'topup' => $topup,
            'history' => AuditLog::where('auditable_type', WalletTopup::class)->where('auditable_id', $topup->id)->latest('id')->limit(30)->get(),
        ]);
    }

    public function bill(WalletTopup $topup): Response
    {
        $image = $topup->billImage();
        abort_if($image === null, 404);

        return response($image['contents'], 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Đã nhận tiền: mã giao dịch (không trùng đơn hàng / lệnh khác) + số tiền thực nhận (≥ số trên lệnh) → cộng ví. */
    public function confirm(Request $request, WalletTopup $topup): RedirectResponse
    {
        $request->merge([
            'paid_amount' => is_string($request->input('paid_amount')) ? (Money::parse($request->input('paid_amount')) ?? $request->input('paid_amount')) : $request->input('paid_amount'),
            'payment_reference' => is_string($request->input('payment_reference')) ? strtoupper(trim($request->input('payment_reference'))) : $request->input('payment_reference'),
        ]);
        $data = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100', Rule::unique('wallet_topups', 'payment_reference')->ignore($topup->id),
                Rule::unique('orders', 'payment_reference'), 'not_regex:/^'.preg_quote(Order::WALLET_REFERENCE_PREFIX, '/').'/'],
            'paid_amount' => ['required', 'integer', 'min:'.$topup->amount, 'max:'.WalletTopup::MAX_AMOUNT * 10],
            'payment_note' => [Rule::requiredIf($topup->status === WalletTopup::STATUS_CANCELLED), 'nullable', 'string', 'max:300'],
        ], [
            'payment_reference.required' => 'Nhập mã giao dịch ngân hàng.',
            'payment_reference.unique' => 'Mã giao dịch này đã dùng để xác nhận đơn hàng / lệnh nạp khác.',
            'payment_reference.not_regex' => 'Mã bắt đầu bằng “'.Order::WALLET_REFERENCE_PREFIX.'” dành cho đơn trả bằng ví.',
            'paid_amount.required' => 'Nhập số tiền thực nhận.',
            'paid_amount.integer' => 'Số tiền không hợp lệ.',
            'paid_amount.min' => 'Số tiền thực nhận thấp hơn số trên lệnh ('.Money::format($topup->amount).' đ). Bấm “Chưa nhận được tiền” để khách chuyển bù.',
            'payment_note.required' => 'Lệnh đã hủy: ghi chú lý do khôi phục (VD: tiền về sau khi quá hạn).',
        ], ['payment_reference' => 'mã giao dịch', 'paid_amount' => 'số tiền', 'payment_note' => 'ghi chú']);

        $done = TopupPayment::confirm($topup, $data, $request->user('web')->id);
        if ($done === false) {
            return back()->with('error', 'Lệnh đã được xác nhận hoặc đang ở trạng thái không xác nhận được.');
        }

        return back()->with('success', "Đã xác nhận lệnh {$topup->code}: cộng ".Money::format($done->paid_amount).' đ vào ví tái tiêu dùng của '.$done->member->full_name.'.');
    }

    /** Khách báo đã chuyển nhưng chưa thấy tiền / chuyển thiếu: trả về "Chờ chuyển khoản", hạn tính lại từ bây giờ. */
    public function reject(Request $request, WalletTopup $topup): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']],
            ['reason.required' => 'Nhập lý do.', 'reason.min' => 'Lý do quá ngắn (ít nhất 5 ký tự).'], ['reason' => 'lý do']);

        $done = DB::transaction(function () use ($topup, $data) {
            $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
            if ($topup->status !== WalletTopup::STATUS_AWAITING) {
                return false;
            }
            $topup->forceFill([
                'status' => WalletTopup::STATUS_NEW, 'confirm_requested_at' => null,
                'expires_at' => now()->addHours((int) AffiliateSettings::get('payment_expiry_hours')),
            ])->save();
            AuditLog::record('updated', $topup, ['old' => ['status' => WalletTopup::STATUS_AWAITING], 'new' => ['status' => WalletTopup::STATUS_NEW]],
                "Lệnh nạp {$topup->code}: chưa nhận được tiền, trả về Chờ chuyển khoản. Lý do: ".trim($data['reason']));

            return true;
        });

        return $done
            ? back()->with('success', "Đã trả lệnh {$topup->code} về “Chờ chuyển khoản”, hạn tính lại.")
            : back()->with('error', 'Lệnh không còn ở trạng thái chờ xác nhận.');
    }

    public function cancel(Request $request, WalletTopup $topup): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']],
            ['reason.required' => 'Nhập lý do hủy.', 'reason.min' => 'Lý do quá ngắn (ít nhất 5 ký tự).'], ['reason' => 'lý do']);

        $done = DB::transaction(function () use ($topup, $data) {
            $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
            if (! $topup->isCancellable()) {
                return false;
            }
            $old = $topup->status;
            $topup->forceFill(['status' => WalletTopup::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_source' => WalletTopup::CANCEL_ADMIN, 'cancel_reason' => trim($data['reason'])])->save();
            AuditLog::record('updated', $topup, ['old' => ['status' => $old], 'new' => ['status' => WalletTopup::STATUS_CANCELLED]], "Hủy lệnh nạp {$topup->code}. Lý do: ".trim($data['reason']));

            return true;
        });

        return $done ? back()->with('success', "Đã hủy lệnh {$topup->code}.") : back()->with('error', 'Chỉ hủy được lệnh chưa xác nhận.');
    }
}
