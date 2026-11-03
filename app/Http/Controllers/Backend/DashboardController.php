<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Group;
use App\Models\Page;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Tổng quan: lời chào + vài chỉ số hệ thống, mỗi mục chỉ hiện khi có quyền xem. Dashboard affiliate (A1: doanh thu,
     * đơn chờ thanh toán, giao dịch cần xử lý tay, KYC / rút tiền chờ duyệt…) dựng ở Step 10.
     */
    public function __invoke(Request $request): View
    {
        $can = fn (string $gate) => $request->user()->can($gate);

        return view('backend.dashboard', [
            'today' => CarbonImmutable::today(),
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
}
