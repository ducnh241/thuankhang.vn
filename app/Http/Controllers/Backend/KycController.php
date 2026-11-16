<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Member;
use App\Models\MemberKyc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * KYC thành viên (A11): hàng chờ duyệt (tab mặc định) + đã duyệt / không được duyệt, trang hồ sơ (thông tin CCCD, tài
 * khoản ngân hàng, ảnh 2 mặt — cần quyền kyc/view_document), duyệt / từ chối kèm lý do (kyc/review). Khi duyệt có thể
 * kích hoạt luôn tài khoản thành viên (cần thêm quyền member/activate). Nhật ký chỉ ghi thao tác + lý do, không ghi số
 * CCCD / số tài khoản.
 */
class KycController extends Controller
{
    public const TABS = [
        MemberKyc::STATUS_PENDING => 'Chờ duyệt',
        MemberKyc::STATUS_APPROVED => 'Đã duyệt',
        MemberKyc::STATUS_REJECTED => 'Không được duyệt',
        'all' => 'Tất cả',
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $tab = $this->queryString($request, 'tab');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, self::TABS) ? $tab : MemberKyc::STATUS_PENDING,
        ];

        $base = MemberKyc::query()->when($filter['q'] !== '', function (Builder $query) use ($filter) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
            $phone = Member::normalizePhone($filter['q']);
            $digits = preg_replace('/[\s.\-]/', '', $filter['q']);
            $query->where(fn (Builder $q) => $q
                ->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                ->orWhereRaw("bank_account_number LIKE ? ESCAPE '!'", [$like])
                // Số CCCD lưu mã hóa: chỉ tra được khi gõ đủ 12 số
                ->when(preg_match('/^\d{12}$/', $digits), fn (Builder $q) => $q->orWhere('id_number_hash', MemberKyc::hashIdNumber($digits)))
                ->orWhereHas('member', fn (Builder $m) => $m->where(fn (Builder $m) => $m
                    ->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("referral_code LIKE ? ESCAPE '!'", [$like])
                    ->when($phone, fn (Builder $m) => $m->orWhere('phone', $phone)))));
        });

        $byStatus = (clone $base)->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $counts = ['all' => (int) $byStatus->sum()];
        foreach (array_keys(MemberKyc::STATUSES) as $status) {
            $counts[$status] = (int) ($byStatus[$status] ?? 0);
        }

        $kycs = (clone $base)
            ->when($filter['tab'] !== 'all', fn (Builder $q) => $q->where('status', $filter['tab']))
            ->with('member:id,full_name,phone,referral_code,activated_at,status')
            // Hàng chờ: hồ sơ gửi trước duyệt trước; các tab khác: mới nhất trước
            ->when($filter['tab'] === MemberKyc::STATUS_PENDING, fn (Builder $q) => $q->orderBy('submitted_at')->orderBy('id'), fn (Builder $q) => $q->orderByDesc('submitted_at')->orderByDesc('id'))
            ->paginate(30)
            ->withQueryString();

        if ($kycs->isEmpty() && $kycs->currentPage() > 1) {
            return redirect($kycs->url($kycs->lastPage()));
        }

        return view('backend.kyc.index', compact('kycs', 'filter', 'counts'));
    }

    public function show(MemberKyc $kyc): View
    {
        $kyc->load(['member.level', 'reviewer']);

        return view('backend.kyc.show', [
            'kyc' => $kyc,
            'member' => $kyc->member,
            'duplicates' => $this->duplicates($kyc),
            'firstLevel' => Level::earning()->firstWhere('active', true),
            'history' => AuditLog::where('auditable_type', MemberKyc::class)->where('auditable_id', $kyc->id)->latest('id')->limit(20)->get(),
        ]);
    }

    /** Ảnh CCCD (side = front | back), đã giải mã — chỉ người có quyền kyc/view_document. */
    public function image(MemberKyc $kyc, string $side): Response
    {
        $image = $kyc->image($side);
        abort_if($image === null, 404);

        return response($image['contents'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Duyệt hồ sơ đang chờ. activate = 1 (và có quyền member/activate): kích hoạt luôn tài khoản thành viên. */
    public function approve(Request $request, MemberKyc $kyc): RedirectResponse
    {
        $request->validate(['activate' => ['nullable', 'boolean']]);
        $activate = $request->boolean('activate') && $request->user()->can('member/activate');

        $error = DB::transaction(function () use ($kyc, $activate) {
            $kyc = MemberKyc::lockForUpdate()->findOrFail($kyc->id);
            if (! $kyc->isPending()) {
                return 'Hồ sơ này đã được xử lý trước đó ('.mb_strtolower($kyc->statusLabel()).').';
            }
            // 1 CCCD = 1 tài khoản = 1 số tài khoản: không duyệt khi đã có hồ sơ ĐÃ DUYỆT khác dùng trùng
            $approved = MemberKyc::where('id', '!=', $kyc->id)->where('status', MemberKyc::STATUS_APPROVED);
            if ((clone $approved)->where('id_number_hash', $kyc->id_number_hash)->exists()) {
                return 'Không duyệt được: số CCCD này đã được duyệt cho một thành viên khác.';
            }
            if ((clone $approved)->where('bank_code', $kyc->bank_code)->where('bank_account_number', $kyc->bank_account_number)->exists()) {
                return 'Không duyệt được: số tài khoản này đã được duyệt cho một thành viên khác.';
            }

            $kyc->forceFill(['status' => MemberKyc::STATUS_APPROVED, 'reject_reason' => null, 'reviewed_at' => now(), 'reviewed_by' => auth()->id()])->save();
            $member = $kyc->member;
            AuditLog::record('updated', $kyc, ['old' => ['status' => MemberKyc::STATUS_PENDING], 'new' => ['status' => MemberKyc::STATUS_APPROVED]],
                "Duyệt hồ sơ KYC của {$member->full_name} ({$member->referral_code})");
            if ($activate && ! $member->isActivated()) {
                $member->activateManually('Kích hoạt tài khoản khi duyệt hồ sơ KYC');
            }

            return null;
        });

        if ($error) {
            return back()->with('error', $error);
        }
        $member = $kyc->member()->first();

        return redirect()->route('backend.kyc.show', $kyc)->with('success', "Đã duyệt hồ sơ KYC của {$member->full_name}"
            .($activate && $member->isActivated() ? ' và kích hoạt tài khoản.' : '.'));
    }

    /** Từ chối hồ sơ đang chờ (bắt buộc lý do — thành viên thấy lý do này và gửi lại hồ sơ). */
    public function reject(Request $request, MemberKyc $kyc): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']],
            ['reason.required' => 'Nhập lý do không duyệt.', 'reason.min' => 'Lý do quá ngắn (ít nhất 5 ký tự).'], ['reason' => 'lý do']);
        $reason = trim($data['reason']);

        $error = DB::transaction(function () use ($kyc, $reason) {
            $kyc = MemberKyc::lockForUpdate()->findOrFail($kyc->id);
            if (! $kyc->isPending()) {
                return 'Hồ sơ này đã được xử lý trước đó ('.mb_strtolower($kyc->statusLabel()).').';
            }
            $kyc->forceFill(['status' => MemberKyc::STATUS_REJECTED, 'reject_reason' => $reason, 'reviewed_at' => now(), 'reviewed_by' => auth()->id()])->save();
            $member = $kyc->member;
            AuditLog::record('updated', $kyc, ['old' => ['status' => MemberKyc::STATUS_PENDING], 'new' => ['status' => MemberKyc::STATUS_REJECTED]],
                "Không duyệt hồ sơ KYC của {$member->full_name} ({$member->referral_code}). Lý do: {$reason}");

            return null;
        });

        return $error
            ? back()->with('error', $error)
            : redirect()->route('backend.kyc.show', $kyc)->with('success', 'Đã đánh dấu hồ sơ không được duyệt. Thành viên sẽ thấy lý do và có thể gửi lại.');
    }

    /** Hồ sơ còn hiệu lực của thành viên khác dùng trùng CCCD / số tài khoản (cảnh báo trước khi duyệt). */
    private function duplicates(MemberKyc $kyc): array
    {
        $others = MemberKyc::heldByOthers($kyc->member_id)->with('member:id,full_name,referral_code');

        return [
            'id_number' => (clone $others)->where('id_number_hash', $kyc->id_number_hash)->get(),
            'bank' => (clone $others)->where('bank_code', $kyc->bank_code)->where('bank_account_number', $kyc->bank_account_number)->get(),
        ];
    }
}
