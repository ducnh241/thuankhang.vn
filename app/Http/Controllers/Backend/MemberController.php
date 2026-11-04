<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\MemberReferrerRequest;
use App\Models\AuditLog;
use App\Models\Level;
use App\Models\Member;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Thành viên website (A10): danh sách (tìm theo tên / SĐT / mã, tab trạng thái, lọc cấp), trang chi tiết (thông tin,
 * người giới thiệu, cây F1 / F2), khóa / mở tài khoản (member/lock), sửa người giới thiệu có lý do (member/referrer).
 * Khóa / sửa người giới thiệu ghi một dòng nhật ký kèm lý do (lưu không qua observer để khỏi ghi trùng).
 */
class MemberController extends Controller
{
    public const TABS = ['' => 'Tất cả', 'activated' => 'Đã kích hoạt', 'inactive' => 'Chưa kích hoạt', 'locked' => 'Đang khóa'];

    /** Số F1 hiện tối đa ở trang chi tiết. */
    public const F1_LIMIT = 200;

    public function index(Request $request): View|RedirectResponse
    {
        $levels = Level::ordered()->get();
        $tab = $this->queryString($request, 'tab');
        $levelId = $this->queryString($request, 'level_id');
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'tab' => array_key_exists($tab, self::TABS) ? $tab : '',
            'level_id' => ctype_digit($levelId) && $levels->contains('id', (int) $levelId) ? $levelId : '',
        ];

        $base = Member::query()
            ->when($filter['q'] !== '', function (Builder $query) use ($filter) {
                $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
                $phone = Member::normalizePhone($filter['q']);
                $query->where(fn ($q) => $q->whereRaw("full_name LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("phone LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("referral_code LIKE ? ESCAPE '!'", [$like])
                    ->orWhereRaw("email LIKE ? ESCAPE '!'", [$like])
                    ->when($phone, fn ($q) => $q->orWhere('phone', $phone)));
            })
            ->when($filter['level_id'] !== '', fn ($q) => $q->where('level_id', (int) $filter['level_id']));

        $counts = [
            '' => (clone $base)->count(),
            'activated' => (clone $base)->whereNotNull('activated_at')->count(),
            'inactive' => (clone $base)->whereNull('activated_at')->count(),
            'locked' => (clone $base)->where('status', Member::STATUS_LOCKED)->count(),
        ];

        $members = (clone $base)
            ->when($filter['tab'] === 'activated', fn ($q) => $q->whereNotNull('activated_at'))
            ->when($filter['tab'] === 'inactive', fn ($q) => $q->whereNull('activated_at'))
            ->when($filter['tab'] === 'locked', fn ($q) => $q->where('status', Member::STATUS_LOCKED))
            ->with(['level:id,name', 'referrer:id,full_name,referral_code'])
            ->withCount('referrals')
            ->orderByDesc('id')
            ->paginate(30)
            ->withQueryString();

        if ($members->isEmpty() && $members->currentPage() > 1) {
            return redirect($members->url($members->lastPage()));
        }

        return view('backend.members.index', compact('members', 'filter', 'counts', 'levels'));
    }

    public function show(Member $member): View
    {
        $member->load(['level', 'referrer.referrer'])->loadCount('referrals');
        $f1 = $member->referrals()->with('level:id,name')->withCount('referrals')
            ->orderByDesc('id')->limit(self::F1_LIMIT)->get();
        // F2 = F1 của các F1, nhóm theo F1 để hiện dòng mở rộng
        $f2 = Member::whereIn('referrer_id', $f1->pluck('id'))->with('level:id,name')->orderByDesc('id')->get()->groupBy('referrer_id');

        return view('backend.members.show', [
            'member' => $member,
            'f1' => $f1,
            'f2' => $f2,
            'f2Count' => Member::whereIn('referrer_id', $member->referrals()->select('id'))->count(),
            'history' => AuditLog::where('auditable_type', Member::class)->where('auditable_id', $member->id)
                ->whereIn('action', ['updated', 'created'])->latest('id')->limit(20)->get(),
        ]);
    }

    /** Khóa / mở khóa (action = lock | unlock), lý do không bắt buộc. */
    public function lock(Request $request, Member $member): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:lock,unlock'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $status = $data['action'] === 'lock' ? Member::STATUS_LOCKED : Member::STATUS_ACTIVE;
        if ($member->status === $status) {
            return back()->with('error', $status === Member::STATUS_LOCKED ? 'Tài khoản đã bị khóa từ trước.' : 'Tài khoản đang hoạt động.');
        }

        DB::transaction(function () use ($member, $status, $data) {
            $old = $member->status;
            $member->forceFill(['status' => $status])->saveQuietly();
            AuditLog::record('updated', $member, ['old' => ['status' => $old], 'new' => ['status' => $status]],
                ($status === Member::STATUS_LOCKED ? 'Khóa tài khoản' : 'Mở khóa tài khoản').(! empty($data['reason']) ? '. Lý do: '.trim($data['reason']) : ''));
        });

        return back()->with('success', $status === Member::STATUS_LOCKED ? "Đã khóa tài khoản {$member->full_name}." : "Đã mở khóa tài khoản {$member->full_name}.");
    }

    /** Sửa người giới thiệu (bắt buộc lý do). Chỉ ảnh hưởng hoa hồng các đơn thanh toán sau thời điểm sửa. */
    public function updateReferrer(MemberReferrerRequest $request, Member $member): RedirectResponse
    {
        $new = $request->newReferrer();
        $label = fn (?Member $m) => $m ? "{$m->full_name} ({$m->referral_code})" : 'Không có';

        DB::transaction(function () use ($member, $new, $label, $request) {
            $old = $member->referrer;
            $member->forceFill(['referrer_id' => $new?->id])->saveQuietly();
            AuditLog::record('updated', $member,
                ['old' => ['referrer_id' => $old?->id, 'referrer' => $label($old)], 'new' => ['referrer_id' => $new?->id, 'referrer' => $label($new)]],
                'Sửa người giới thiệu. Lý do: '.$request->validated('reason'));
        });

        return redirect()->route('backend.members.show', $member)->with('success', 'Đã đổi người giới thiệu thành '.$label($new).'.');
    }
}
