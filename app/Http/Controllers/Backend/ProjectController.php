<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\ProjectRequest;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Hosting;
use App\Models\Project;
use App\Models\ProjectAuditLog;
use App\Models\Tag;
use App\Support\Money;
use App\Support\Renewal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProjectController extends Controller
{
    /** Sắp xếp cho phép: key => [cột, chiều] */
    private const SORTS = [
        'id:desc' => ['id', 'desc'],
        'id:asc' => ['id', 'asc'],
        'name:asc' => ['name', 'asc'],
        'price:desc' => ['price', 'desc'],
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $filter = $this->filter($request);
        $today = CarbonImmutable::today();

        $base = Project::query();
        $this->applyFilter($base, $filter, withStatus: false);

        // Đếm cho tab trạng thái, tính trên các bộ lọc khác (tìm kiếm, KH, tag, thanh toán)
        $statusCounts = (clone $base)->select('status', DB::raw('COUNT(*) as cnt'))->groupBy('status')->pluck('cnt', 'status')->all();
        $statusCounts['all'] = array_sum($statusCounts);

        [$column, $direction] = self::SORTS[$filter['sort']];

        $projects = (clone $base)
            ->when($filter['status'] !== '', fn ($q) => $q->where('status', $filter['status']))
            // hostings (tên, IP): biết dự án chạy trên server nào; domains: các domain alias
            ->with(['customer', 'tags', 'category', 'hostings:id,name,ip,ownership,is_active', 'domains:id,project_id,domain,site_key,position'])
            // Công nợ (thu mới đã thu, phát sinh chưa thu) + tiền gia hạn / phát sinh đã thu
            ->withDebt()
            ->withSum(['payments as paid_renewal' => fn ($q) => $q->received()->where('type', 'renewal')], 'amount')
            ->withSum(['payments as paid_extra' => fn ($q) => $q->received()->where('type', 'extra')], 'amount')
            ->orderBy($column, $direction)
            // Cột sắp xếp có giá trị trùng (cùng tên, giá 0…) → thêm id để phân trang không lặp/mất dòng
            ->when($column !== 'id', fn ($q) => $q->orderBy('id', 'desc'))
            ->paginate(20)
            ->withQueryString();

        // Trang vượt quá số trang (vd vừa xóa dòng cuối của trang cuối rồi quay lại): về trang cuối còn dữ liệu
        if ($projects->isEmpty() && $projects->currentPage() > 1) {
            return redirect($projects->url($projects->lastPage()));
        }

        return view('backend.projects.index', [
            'today' => $today,
            'metrics' => $this->metrics($today),
            'projects' => $projects,
            'filter' => $filter,
            'statusCounts' => $statusCounts,
            'customerCounts' => Customer::projectCountMap(),
            'customers' => Customer::orderBy('name')->get(['id', 'name', 'company']),
            'tags' => Tag::orderBy('name')->get(['id', 'name']),
            'categories' => Category::tree(Category::TYPE_PROJECT),
            'hostings' => Hosting::orderBy('name')->get(['id', 'name']),
        ]);
    }

    /** Dải chỉ số đầu trang (toàn bộ dự án, không theo bộ lọc) — gia hạn và công nợ tính như dashboard. */
    private function metrics(CarbonImmutable $today): array
    {
        $renewals = Renewal::summary($today);

        return [
            'status' => Project::query()->selectRaw('status, COUNT(*) as cnt')->groupBy('status')->pluck('cnt', 'status')->map(fn ($c) => (int) $c)->all(),
            'renewal_count' => $renewals['upcoming_count'],
            'renewal_next' => $renewals['upcoming'][0] ?? null,
            'debt' => Project::debtSummary(),
        ];
    }

    public function create(Request $request): View
    {
        // Mở từ trang khách hàng ("+ Dự án mới") thì chọn sẵn khách hàng đó
        return view('backend.projects.form', $this->formData(new Project(['customer_id' => (int) $request->query('customer_id') ?: null])));
    }

    public function store(ProjectRequest $request): RedirectResponse
    {
        $project = new Project;
        $this->save($project, $request);

        return redirect()->route('backend.projects.show', $project)->with('success', 'Đã tạo dự án.');
    }

    public function show(Project $project): View
    {
        $project->load([
            'category.parent',
            'customer.referrer',
            'tags',
            'hostings',
            'domains',
            'payments' => fn ($q) => $q->orderByDesc('paid_at')->orderByDesc('id'),
        ]);

        return view('backend.projects.show', [
            'today' => CarbonImmutable::today(),
            'project' => $project,
            'auditLogs' => $project->auditLogs()->orderByDesc('id')->limit(50)->get(),
            'categoryNames' => Category::project()->pluck('name', 'id')->all(),
            'customerCounts' => Customer::projectCountMap(),
        ]);
    }

    public function edit(Project $project): View
    {
        $project->load('tags', 'hostings', 'domains');

        return view('backend.projects.form', $this->formData($project));
    }

    public function update(ProjectRequest $request, Project $project): RedirectResponse
    {
        $this->save($project, $request);

        return redirect()->route('backend.projects.show', $project)->with('success', 'Đã cập nhật dự án.');
    }

    /** Dự án đã có khoản thu là hồ sơ tài chính: không xóa được (báo cáo doanh thu bỏ khoản thu của dự án đã xóa). */
    public function destroy(Project $project): RedirectResponse
    {
        if ($count = $project->payments()->count()) {
            return back(fallback: route('backend.projects.show', $project))->with('error',
                "Không xóa được \"{$project->name}\": còn {$count} khoản thu (".Money::format($project->paidAmount())."). Hãy chuyển khoản thu sang dự án khác hoặc xóa khoản thu trước.");
        }

        $project->delete();

        return redirect()->route('backend.projects.index')->with('success', "Đã xóa dự án {$project->name}.");
    }

    private function save(Project $project, ProjectRequest $request): void
    {
        DB::transaction(function () use ($project, $request) {
            $data = $request->safe()->except(['tag_ids', 'hosting_ids', 'domains']);
            foreach (['price', 'renewal_hosting_amount', 'renewal_domain_amount'] as $field) {
                if (array_key_exists($field, $data) || ! $project->exists) {
                    $data[$field] = $data[$field] ?? 0;
                }
            }
            $data['currency'] = ($data['currency'] ?? null) ?: ($project->currency ?: 'VND');

            // Dự án mới chưa đặt ngày gia hạn → lấy theo ngày bắt đầu (giống nhánh main)
            if (! $project->exists && empty($data['next_renewal_date']) && ! empty($data['start_date'])) {
                $data['next_renewal_date'] = $data['start_date'];
            }

            $project->fill($data)->save();
            $project->tags()->sync($request->input('tag_ids', []));
            $project->hostings()->sync($request->input('hosting_ids', []));
            $this->syncDomains($project, $request->domains());
            $project->recalculatePaymentStatus();
        });
    }

    /** Thay danh sách domain alias (giữ thứ tự nhập); có đổi thì ghi lịch sử dự án (cột "domains"). */
    private function syncDomains(Project $project, array $domains): void
    {
        $describe = fn ($rows) => collect($rows)->map(fn ($r) => $r['domain'].($r['note'] ? " ({$r['note']})" : ''))->implode(', ');
        $old = $describe($project->domains()->get(['domain', 'note'])->toArray());
        $new = $describe($domains);
        if ($old === $new) {
            return;
        }

        $project->domains()->delete();
        foreach ($domains as $position => $row) {
            $project->domains()->create([...$row, 'position' => $position]);
        }
        $user = auth()->user();
        ProjectAuditLog::create([
            'auditable_type' => Project::class,
            'auditable_id' => $project->id,
            'project_id' => $project->id,
            'action' => 'updated',
            'changes' => ['old' => ['domains' => $old ?: null], 'new' => ['domains' => $new ?: null]],
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? 'system',
            'ip_address' => request()->ip(),
            'created_at' => now(),
        ]);
    }

    private function formData(Project $project): array
    {
        return [
            'project' => $project,
            // Kèm khách hàng hiện tại dù đã bị xóa, để lưu lại form không bị báo lỗi
            'customers' => Customer::withTrashed()
                ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $project->customer_id))
                ->orderBy('name')->get(['id', 'name', 'company', 'deleted_at']),
            'customerCounts' => Customer::projectCountMap(),
            'tags' => Tag::orderBy('name')->get(),
            'hostings' => Hosting::orderBy('name')->get(),
            'categories' => Category::tree(Category::TYPE_PROJECT),
            // Gợi ý ô Công nghệ: các giá trị đã dùng
            'techStacks' => Project::query()->whereNotNull('tech_stack')->where('tech_stack', '!=', '')
                ->distinct()->orderBy('tech_stack')->limit(50)->pluck('tech_stack'),
        ];
    }

    private function filter(Request $request): array
    {
        $str = fn (string $key) => $this->queryString($request, $key);
        $int = fn (string $key) => ctype_digit($str($key)) ? (int) $str($key) : 0;

        return [
            'q' => $str('q'),
            'status' => array_key_exists($str('status'), Project::STATUSES) ? $str('status') : '',
            'payment_status' => array_key_exists($str('payment_status'), Project::PAYMENT_STATUSES) ? $str('payment_status') : '',
            'customer_id' => $int('customer_id'),
            'cat_id' => $int('cat_id'),
            'tag_id' => $int('tag_id'),
            'hosting_id' => $int('hosting_id'),
            // Còn công nợ: nợ hợp đồng hoặc phát sinh chưa thu (như "Còn phải thu")
            'debt' => $str('debt') === '1',
            'sort' => array_key_exists($str('sort'), self::SORTS) ? $str('sort') : 'id:desc',
        ];
    }

    private function applyFilter(Builder $query, array $filter, bool $withStatus = true): void
    {
        if ($filter['q'] !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filter['q']).'%';
            $query->where(function ($q) use ($like) {
                foreach (['name', 'primary_domain', 'note'] as $column) {
                    $q->orWhereRaw("{$column} LIKE ? ESCAPE '!'", [$like]);
                }
                $q->orWhereHas('domains', fn ($d) => $d->whereRaw("domain LIKE ? ESCAPE '!'", [$like]));
                $q->orWhereHas('customer', fn ($c) => $c->whereRaw("name LIKE ? ESCAPE '!'", [$like]));
            });
        }

        $query->when($withStatus && $filter['status'] !== '', fn ($q) => $q->where('status', $filter['status']))
            ->when($filter['payment_status'] !== '', fn ($q) => $q->where('payment_status', $filter['payment_status']))
            ->when($filter['customer_id'], fn ($q) => $q->where('customer_id', $filter['customer_id']))
            // Lọc danh mục tính cả danh mục con
            ->when($filter['cat_id'], fn ($q) => $q->whereIn('cat_id', Category::descendantIds($filter['cat_id'], Category::TYPE_PROJECT)))
            ->when($filter['tag_id'], fn ($q) => $q->whereHas('tags', fn ($t) => $t->whereKey($filter['tag_id'])))
            ->when($filter['hosting_id'], fn ($q) => $q->whereHas('hostings', fn ($h) => $h->whereKey($filter['hosting_id'])))
            ->when($filter['debt'], fn ($q) => $q->hasDebt());
    }
}
