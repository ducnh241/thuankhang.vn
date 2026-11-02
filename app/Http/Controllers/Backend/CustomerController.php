<?php

namespace App\Http\Controllers\Backend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\CustomerRequest;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;

class CustomerController extends Controller
{
    /** Mới nhất / cũ nhất theo ngày tạo (khách nhập từ hệ thống cũ: ngày bắt đầu dự án đầu tiên — không còn theo thứ tự id). */
    public const DEFAULT_SORT = 'created:desc';

    private const SORTS = [
        'created:desc' => ['created_at', 'desc'],
        'created:asc' => ['created_at', 'asc'],
        'name:asc' => ['name', 'asc'],
        'projects:desc' => ['projects_count', 'desc'],
    ];

    public function index(Request $request): View|RedirectResponse
    {
        $q = $this->queryString($request, 'q');
        $tab = in_array($request->query('tab'), ['with_projects', 'no_projects', 'referred'], true) ? $request->query('tab') : 'all';
        $sort = array_key_exists($this->queryString($request, 'sort'), self::SORTS) ? $this->queryString($request, 'sort') : self::DEFAULT_SORT;
        [$column, $direction] = self::SORTS[$sort];

        $base = Customer::query();
        if ($q !== '') {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q).'%';
            $base->where(function ($query) use ($like) {
                foreach (['name', 'company', 'email', 'phone'] as $field) {
                    $query->orWhereRaw("{$field} LIKE ? ESCAPE '!'", [$like]);
                }
            });
        }

        $counts = [
            'all' => (clone $base)->count(),
            'with_projects' => (clone $base)->has('projects')->count(),
            'no_projects' => (clone $base)->doesntHave('projects')->count(),
            'referred' => (clone $base)->whereNotNull('referrer_customer_id')->count(),
        ];

        $customers = (clone $base)
            ->when($tab === 'with_projects', fn ($query) => $query->has('projects'))
            ->when($tab === 'no_projects', fn ($query) => $query->doesntHave('projects'))
            ->when($tab === 'referred', fn ($query) => $query->whereNotNull('referrer_customer_id'))
            ->with('referrer:id,name,deleted_at')
            ->withCount('projects')
            ->orderBy($column, $direction)
            // Cùng ngày tạo (vd. nhiều khách cùng ngày bắt đầu dự án): theo id cùng chiều; sắp khác thì mới nhất trước
            ->orderBy('id', $column === 'created_at' ? $direction : 'desc')
            ->paginate(20)
            ->withQueryString();

        // Trang vượt quá số trang (vd vừa xóa dòng cuối của trang cuối): về trang cuối còn dữ liệu
        if ($customers->isEmpty() && $customers->currentPage() > 1) {
            return redirect($customers->url($customers->lastPage()));
        }

        $revenue = Customer::revenueMap();

        return view('backend.customers.index', [
            'metrics' => $this->metrics($revenue),
            'customers' => $customers,
            'q' => $q,
            'tab' => $tab,
            'sort' => $sort,
            'counts' => $counts,
            'projectCounts' => Customer::projectCountMap(),
            'revenue' => $revenue,
        ]);
    }

    /** Dải chỉ số đầu trang (toàn bộ khách hàng, không theo ô tìm / tab). */
    private function metrics(array $revenue): array
    {
        $topId = $revenue ? array_search(max($revenue), $revenue, true) : null;

        return [
            'total' => Customer::count(),
            'new_this_month' => Customer::query()->where('created_at', '>=', CarbonImmutable::today()->startOfMonth())->count(),
            'with_projects' => Customer::has('projects')->count(),
            'referred' => Customer::whereNotNull('referrer_customer_id')->count(),
            'referrers' => Customer::whereNotNull('referrer_customer_id')->distinct()->count('referrer_customer_id'),
            'revenue' => (float) array_sum($revenue),
            'top' => $topId ? ['customer' => Customer::withTrashed()->find($topId), 'amount' => $revenue[$topId]] : null,
        ];
    }

    public function create(): View
    {
        return view('backend.customers.form', $this->formData(new Customer));
    }

    public function store(CustomerRequest $request): RedirectResponse
    {
        $customer = Customer::create($request->validated());

        // Người chỉ có quyền thêm (không có quyền sửa) thì về danh sách thay vì gặp 403
        return ($request->user()->can('customer/edit')
            ? redirect()->route('backend.customers.edit', $customer)
            : redirect()->route('backend.customers.index'))->with('success', "Đã tạo khách hàng {$customer->name}.");
    }

    /**
     * Tạo nhanh khách hàng từ form dự án (fetch JSON), trả về {id, label}.
     */
    public function quickStore(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:191'],
        ], [], ['name' => 'tên khách hàng', 'phone' => 'điện thoại']);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $customer = Customer::create($validator->validated());

        return response()->json(['id' => $customer->id, 'label' => $customer->displayName()], 201);
    }

    public function edit(Customer $customer): View
    {
        $customer->load([
            'referrer' => fn ($q) => $q->withTrashed(),
            'referrals' => fn ($q) => $q->withCount('projects')->orderBy('name'),
            'projects' => fn ($q) => $q->with('category')->withSum(['payments as paid' => fn ($q) => $q->received()], 'amount')->orderByDesc('id'),
        ]);

        return view('backend.customers.form', $this->formData($customer));
    }

    public function update(CustomerRequest $request, Customer $customer): RedirectResponse
    {
        $customer->update($request->validated());

        return redirect()->route('backend.customers.edit', $customer)->with('success', 'Đã cập nhật khách hàng.');
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        if ($count = $customer->projects()->count()) {
            return back()->with('error', "Không xóa được \"{$customer->name}\": còn {$count} dự án. Hãy chuyển hoặc xóa dự án trước.");
        }

        $customer->delete();

        // Xóa từ trang sửa của chính khách này: trang đó không còn → về danh sách; xóa từ danh sách thì giữ trang / bộ lọc
        $back = url()->previous();
        $toList = $back === route('backend.customers.edit', $customer) || ! str_starts_with($back, route('backend.customers.index'));

        return ($toList ? redirect()->route('backend.customers.index') : back())->with('success', "Đã xóa khách hàng {$customer->name}.");
    }

    private function formData(Customer $customer): array
    {
        // Người giới thiệu: không gồm chính KH này và những người họ đã giới thiệu (chống vòng lặp)
        $excluded = $customer->exists ? [$customer->id, ...Customer::downlineIds($customer->id)] : [];

        return [
            'customer' => $customer,
            // Kèm người giới thiệu hiện tại dù đã bị xóa, để lưu form không làm mất thông tin
            'referrers' => Customer::withTrashed()
                ->where(fn ($q) => $q->whereNull('deleted_at')->orWhere('id', $customer->referrer_customer_id))
                ->whereNotIn('id', $excluded)->orderBy('name')->get(['id', 'name', 'company', 'deleted_at']),
            'projectCounts' => Customer::projectCountMap(),
        ];
    }
}
