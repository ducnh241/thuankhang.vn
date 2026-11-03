<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    /**
     * Nhật ký hệ thống (A14): chỉ xem. Lọc theo từ khóa (người thực hiện, mô tả, IP), thao tác, loại đối tượng, ngày.
     * Tài khoản ẩn không hiện tên (ghi "Hệ thống"), không tìm được theo tên, và log về chính tài khoản đó bị ẩn.
     */
    public function index(Request $request): View|RedirectResponse
    {
        $filter = [
            'q' => $this->queryString($request, 'q'),
            'action' => $this->option($request, 'action', AuditLog::ACTIONS),
            'type' => $this->option($request, 'type', AuditLog::SUBJECTS),
            'from' => $this->date($request, 'from'),
            'to' => $this->date($request, 'to'),
        ];

        $logs = AuditLog::query()
            // Viết rõ nhánh NULL: NOT (NULL AND …) trong SQL là NULL → mất các dòng không có đối tượng
            ->where(fn (Builder $q) => $q->whereNull('auditable_type')->orWhere('auditable_type', '!=', User::class)
                ->orWhereNotIn('auditable_id', User::hiddenIds()))
            ->when($filter['q'] !== '', function (Builder $query) use ($filter) {
                $like = '%'.addcslashes($filter['q'], '%_\\').'%';
                $query->where(fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->where('user_name', 'like', $like)->whereNotIn('user_id', User::hiddenIds()))
                    ->orWhere('description', 'like', $like)
                    ->orWhere('ip_address', 'like', $like)
                    ->when(ctype_digit($filter['q']), fn (Builder $q) => $q->orWhere('auditable_id', (int) $filter['q'])));
            })
            ->when($filter['action'] !== '', fn (Builder $q) => $q->where('action', $filter['action']))
            ->when($filter['type'] !== '', fn (Builder $q) => $q->where('auditable_type', $filter['type']))
            ->when($filter['from'] !== '', fn (Builder $q) => $q->where('created_at', '>=', Carbon::parse($filter['from'])->startOfDay()))
            ->when($filter['to'] !== '', fn (Builder $q) => $q->where('created_at', '<=', Carbon::parse($filter['to'])->endOfDay()))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        if ($logs->isEmpty() && $logs->currentPage() > 1) {
            return redirect($logs->url($logs->lastPage()));
        }

        return view('backend.audit-logs.index', ['logs' => $logs, 'filter' => $filter]);
    }

    /** Giá trị lọc phải là một khóa hợp lệ (class đối tượng, mã thao tác), không thì bỏ lọc. */
    private function option(Request $request, string $key, array $options): string
    {
        $value = $this->queryString($request, $key);

        return array_key_exists($value, $options) ? $value : '';
    }

    private function date(Request $request, string $key): string
    {
        $value = $this->queryString($request, $key);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false ? $value : '';
    }
}
