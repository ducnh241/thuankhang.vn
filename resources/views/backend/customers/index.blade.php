@extends('layouts.backend')

@section('title', 'Khách hàng')

{{-- Template ProCMS không có trang khách hàng: dựng như trang Dự án (projects.html) — dải chỉ số, tab, ô tìm + nút sắp
     xếp, bảng ant-table, phân trang. Lọc / tìm / phân trang ở server (link thật);
     resources/admin/js/list.js mở danh sách chọn của nút sắp xếp và cho bấm cả dòng để mở trang sửa. --}}
@php
    use App\Http\Controllers\Backend\CustomerController;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $user = auth()->user();
    $canAdd = $user->can('customer/add');
    $canEdit = $user->can('customer/edit');
    $canDelete = $user->can('customer/delete');
    $canSeeProjects = $user->can('project');
    $tabs = ['all' => 'Tất cả', 'with_projects' => 'Có dự án', 'no_projects' => 'Chưa có dự án', 'referred' => 'Được giới thiệu'];
    $sorts = ['created:desc' => 'Mới nhất', 'created:asc' => 'Cũ nhất', 'name:asc' => 'Tên A–Z', 'projects:desc' => 'Nhiều dự án nhất'];

    // Link danh sách giữ ô tìm / tab / sắp xếp đang chọn, đổi vài tham số (null = bỏ), luôn về trang 1
    $current = array_filter(['q' => $q, 'tab' => $tab !== 'all' ? $tab : null, 'sort' => $sort !== CustomerController::DEFAULT_SORT ? $sort : null]);
    $url = fn (array $changes = []) => route('backend.customers.index', array_filter([...$current, ...$changes], fn ($v) => $v !== null && $v !== ''));
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $metricLink = fn (string $key) => $url(['tab' => $tab === $key ? null : $key]);
    $top = $metrics['top'];
@endphp

@section('actions')
  @if ($canAdd)
    <a href="{{ route('backend.customers.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm khách hàng</span></a>
  @endif
@endsection

@section('content')
<div id="customers" class="mx-auto max-w-[1200px]" data-list>
  {{-- Chỉ số: Có dự án / Được giới thiệu mở tab tương ứng (bấm lại để về Tất cả) --}}
  <div class="ant-card ant-card-bordered mb-4">
    <div class="product-metrics" id="customer-metrics">
      <div class="product-metric">
        <span class="product-metric-label">Khách hàng</span>
        <span class="product-metric-value">{{ $count($metrics['total']) }}</span>
        <span class="product-metric-sub">+{{ $count($metrics['new_this_month']) }} trong tháng {{ now()->month }}</span>
      </div>
      <a href="{{ $metricLink('with_projects') }}" @class(['product-metric', 'is-active' => $tab === 'with_projects'])>
        <span class="product-metric-label">Có dự án</span>
        <span class="product-metric-value">{{ $count($metrics['with_projects']) }}</span>
        <span class="product-metric-sub">{{ $count($metrics['total'] - $metrics['with_projects']) }} chưa có dự án</span>
      </a>
      <a href="{{ $metricLink('referred') }}" @class(['product-metric', 'is-active' => $tab === 'referred'])>
        <span class="product-metric-label">Được giới thiệu</span>
        <span class="product-metric-value">{{ $count($metrics['referred']) }}</span>
        <span class="product-metric-sub">từ {{ $count($metrics['referrers']) }} người giới thiệu</span>
      </a>
      <div class="product-metric">
        <span class="product-metric-label">Đã thu từ khách</span>
        <span class="product-metric-value">{{ Money::format($metrics['revenue']) }}</span>
        <span class="product-metric-sub" @if ($top) title="{{ $top['customer']?->name }} · {{ Money::format($top['amount']) }}" @endif>{{ $top ? 'Nhiều nhất: '.($top['customer']?->name ?? '#?').' · '.Money::format($top['amount']) : 'Chưa có khoản thu' }}</span>
      </div>
    </div>
  </div>

  <div class="ant-card ant-card-bordered product-index">
    {{-- Tab (số đếm theo ô tìm) --}}
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Lọc khách hàng">
        @foreach ($tabs as $key => $label)
          <a href="{{ $url(['tab' => $key === 'all' ? null : $key]) }}" role="tab" aria-selected="{{ $tab === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $tab === $key])>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    {{-- Tìm kiếm (Enter để tìm) + sắp xếp --}}
    <div class="product-search">
      <form method="GET" action="{{ route('backend.customers.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except($current, ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $q }}" class="ant-input" id="customer-search" placeholder="Tìm theo tên, công ty, email, điện thoại" aria-label="Tìm khách hàng" />
          @if ($q !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($q !== '' || $tab !== 'all')
          <a href="{{ $url(['q' => null, 'tab' => null]) }}" class="whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
        <button type="button" class="filter-pill ml-auto" data-filter="sort">Sắp xếp: {{ $sorts[$sort] }}{{ ant_icon('down') }}</button>
      </div>
      <template id="filter-sort">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach ($sorts as $key => $label)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $sort === $key]) role="menuitem"><a href="{{ $url(['sort' => $key === CustomerController::DEFAULT_SORT ? null : $key]) }}">{{ $label }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table @class(['product-table customer-table', 'min-w-0' => $customers->isEmpty()]) style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Khách hàng</th>
                  <th class="ant-table-cell max-md:hidden" data-col="referrer">Người giới thiệu</th>
                  <th class="ant-table-cell" data-col="projects" style="text-align: right">Dự án</th>
                  <th class="ant-table-cell" data-col="revenue" style="text-align: right">Đã thu (VND)</th>
                  <th class="ant-table-cell max-md:hidden" data-col="created">Ngày tạo</th>
                  <th class="ant-table-cell product-actions-col" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="customer-rows">
                @forelse ($customers as $customer)
                  @php
                      $projects = $projectCounts[$customer->id] ?? ['direct' => 0, 'total' => 0];
                      $referred = $projects['total'] - $projects['direct'];
                      $paid = $revenue[$customer->id] ?? null;
                      $editUrl = route('backend.customers.edit', $customer);
                      $contact = collect([$customer->company, $customer->phone, $customer->email])->filter()->implode(' · ');
                  @endphp
                  <tr class="ant-table-row ant-table-row-level-0 product-row" data-row-key="{{ $customer->id }}" @if ($canEdit) data-href="{{ $editUrl }}" @endif>
                    <td class="ant-table-cell">
                      <div class="min-w-[130px] max-w-[320px] max-sm:min-w-0">
                        @if ($canEdit)
                          <a class="product-name wrap-anywhere" href="{{ $editUrl }}">{{ $customer->name }}</a>
                        @else
                          <span class="product-name wrap-anywhere">{{ $customer->name }}</span>
                        @endif
                        <div class="truncate text-xs {{ $muted }} max-sm:whitespace-normal max-sm:wrap-anywhere">#{{ $customer->id }}{{ $contact !== '' ? ' · '.$contact : '' }}</div>
                        {{-- Điện thoại: các cột phụ ẩn, ghi gọn dưới tên (dưới 768px còn ẩn người giới thiệu, ngày tạo) --}}
                        <div class="product-row-meta {{ $muted }}">{{ $projects['direct'] }} dự án{{ $paid ? ' · Đã thu '.Money::format($paid) : '' }}</div>
                      </div>
                    </td>
                    <td class="ant-table-cell max-md:hidden" data-col="referrer">
                      @if ($customer->referrer)
                        @if ($canEdit && ! $customer->referrer->trashed())
                          <a href="{{ route('backend.customers.edit', $customer->referrer) }}" class="project-link block max-w-[180px] truncate max-xl:max-w-[120px]" title="{{ $customer->referrer->name }}">{{ $customer->referrer->name }}</a>
                        @else
                          <span class="block max-w-[180px] truncate max-xl:max-w-[120px]" title="{{ $customer->referrer->name }}">{{ $customer->referrer->name }}</span>
                        @endif
                        @if ($customer->referrer->trashed())<span class="text-xs text-[#f5222d]">(đã xóa)</span>@endif
                      @else
                        <span class="{{ $muted }}">—</span>
                      @endif
                    </td>
                    <td class="ant-table-cell tabular-nums" data-col="projects" style="text-align: right">
                      @if ($projects['direct'] > 0 && $canSeeProjects)
                        <a href="{{ route('backend.projects.index', ['customer_id' => $customer->id]) }}" class="project-link" data-tooltip="Xem dự án của khách hàng">{{ $projects['direct'] }}</a>
                      @else
                        <span @class([$muted => ! $projects['direct']])>{{ $projects['direct'] }}</span>
                      @endif
                      @if ($referred > 0)<div class="text-xs {{ $muted }}" title="Dự án của những khách hàng được người này giới thiệu (cả nhiều cấp)">+{{ $referred }} giới thiệu</div>@endif
                    </td>
                    <td class="ant-table-cell tabular-nums" data-col="revenue" style="text-align: right">{!! $paid !== null ? e(Money::format($paid)) : '<span class="'.$muted.'">—</span>' !!}</td>
                    <td class="ant-table-cell tabular-nums {{ $muted }} max-md:hidden" data-col="created" @if ($customer->created_at) title="{{ $customer->created_at->format('d/m/Y H:i') }}" @endif>{{ $customer->created_at?->format('d/m/Y') ?? '—' }}</td>
                    <td class="ant-table-cell product-actions-col" style="text-align: right">
                      <div class="product-row-actions">
                        @if ($canEdit)
                          <a href="{{ $editUrl }}" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa {{ $customer->name }}">{{ ant_icon('edit') }}</a>
                        @endif
                        @if ($canDelete && $customer->projects_count > 0)
                          <span data-tooltip="Còn {{ $customer->projects_count }} dự án, không xóa được"><button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" disabled aria-label="Không xóa được {{ $customer->name }}">{{ ant_icon('delete') }}</button></span>
                        @elseif ($canDelete)
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa {{ $customer->name }}"
                            data-delete-url="{{ route('backend.customers.destroy', $customer) }}" data-delete-title="Xóa khách hàng “{{ $customer->name }}”?">{{ ant_icon('delete') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="6" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('search', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        @if ($q !== '' || $tab !== 'all')
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không tìm thấy khách hàng nào</strong>Thử từ khóa khác hoặc bỏ lọc.</p>
                          <div class="ant-empty-footer"><a href="{{ route('backend.customers.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                        @else
                          <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có khách hàng nào</strong>Khách hàng bạn thêm sẽ hiện ở đây.</p>
                          @if ($canAdd)<div class="ant-empty-footer"><a href="{{ route('backend.customers.create') }}" class="ant-btn ant-btn-primary">{{ ant_icon('plus') }}<span>Thêm khách hàng</span></a></div>@endif
                        @endif
                      </div>
                    </td>
                  </tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    @if ($customers->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($customers->firstItem()) }}–{{ $count($customers->lastItem()) }} / {{ $count($customers->total()) }} khách hàng</span>
        {{ $customers->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>
@endsection
