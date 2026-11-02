@extends('layouts.backend')

@section('title', 'Thanh toán')

{{-- Theo template ProCMS orders.html: dải chỉ số, tab loại (số khoản · tổng tiền), ô tìm + nút lọc phương thức + chọn
     khoảng ngày (có khoảng chọn nhanh), bảng chọn nhiều dòng (thanh "N đã chọn" phủ tiêu đề bảng) để xóa hàng loạt,
     phân trang. Tab "Chưa thu" = khoản phát sinh khách chưa trả (tính công nợ, chưa tính doanh thu), các tab còn lại là
     tiền đã thu. Lọc / tìm / phân trang ở server; JS: list.js (nút lọc, bấm dòng mở hộp sửa), bulk.js (chọn nhiều),
     payment-modal.js (hộp thêm / sửa khoản thu, ghi nhận đã thu), delete.js (xóa một khoản). --}}
@php
    use App\Models\Payment;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $user = auth()->user();
    $canAdd = $user->can('payment/add');
    $canEdit = $user->can('payment/edit');
    $canDelete = $user->can('payment/delete');
    $canSeeProjects = $user->can('project');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');

    // Link danh sách giữ các bộ lọc đang chọn, đổi vài tham số (null = bỏ), luôn về trang 1
    $current = array_filter(['q' => $filter['q'], 'type' => $filter['type'], 'status' => $filter['status'], 'method' => $filter['method'], 'from' => $filter['from'], 'to' => $filter['to']]);
    $url = fn (array $changes = []) => route('backend.payments.index', array_filter([...$current, ...$changes], fn ($v) => $v !== null && $v !== ''));
    $filtering = $filter['q'] !== '' || $filter['method'] !== '' || $filter['from'] !== '' || $filter['to'] !== '';

    // Khoảng ngày chọn nhanh (dưới lịch) và chỉ số: bấm lại khoảng đang chọn thì bỏ lọc ngày
    $presets = [
        ['label' => 'Tháng này', 'from' => $metrics['month']['from'], 'to' => $metrics['month']['to']],
        ['label' => 'Tháng trước', 'from' => $metrics['prev_month']['from'], 'to' => $metrics['prev_month']['to']],
        ['label' => 'Năm nay', 'from' => $metrics['year']['from'], 'to' => $metrics['year']['to']],
        ['label' => 'Năm trước', 'from' => $today->subYear()->startOfYear()->toDateString(), 'to' => $today->subYear()->endOfYear()->toDateString()],
    ];
    $isRange = fn (array $m) => $filter['from'] === $m['from'] && $filter['to'] === $m['to'];
    $rangeLink = fn (array $m) => $isRange($m) ? $url(['from' => null, 'to' => null]) : $url(['from' => $m['from'], 'to' => $m['to']]);
    $debt = $metrics['debt'];
    $unpaidTab = $filter['status'] === 'unpaid';
    // Còn phải thu: mở danh sách dự án còn công nợ (không có quyền xem dự án thì sang báo cáo)
    $debtUrl = $user->can('project') ? route('backend.projects.index', ['debt' => 1]) : ($user->can('report') ? route('backend.reports.revenue') : null);
@endphp

@section('actions')
  @if ($canAdd)
    <button type="button" class="ant-btn" data-payment-open data-payment="{{ json_encode(['type' => 'extra', 'status' => 'unpaid']) }}">{{ ant_icon('file-text') }}<span class="max-sm:hidden">Ghi nợ phát sinh</span></button>
    <button type="button" class="ant-btn ant-btn-primary" data-payment-open>{{ ant_icon('plus') }}<span>Thêm khoản thu</span></button>
  @endif
@endsection

@section('content')
<div id="payments" class="mx-auto max-w-[1200px]" data-list>
  {{-- Chỉ số (cả hệ thống, không theo bộ lọc); bấm để lọc đúng khoảng ngày đó --}}
  <div class="ant-card ant-card-bordered mb-4">
    <div class="product-metrics" id="payment-metrics">
      @foreach ([['Tháng '.$today->month, $metrics['month'], ' (tới hôm nay)'], ['Tháng '.$today->subMonthNoOverflow()->month, $metrics['prev_month'], ''], ['Năm '.$today->year, $metrics['year'], '']] as [$label, $m, $note])
        <a href="{{ $rangeLink($m) }}" @class(['product-metric', 'is-active' => $isRange($m)]) title="{{ \Carbon\Carbon::parse($m['from'])->format('d/m/Y') }} – {{ \Carbon\Carbon::parse($m['to'])->format('d/m/Y') }}{{ $note }}">
          <span class="product-metric-label">{{ $label }}</span>
          <span class="product-metric-value">{{ Money::format($m['total']) }}</span>
          <span class="product-metric-sub">{{ $count($m['count']) }} khoản{{ $m['renewal'] > 0 ? ' · gia hạn '.Money::format($m['renewal']) : '' }}</span>
        </a>
      @endforeach
      @if ($debtUrl)<a href="{{ $debtUrl }}" class="product-metric" data-metric="debt" title="Nợ hợp đồng + phát sinh chưa thu">@else<div class="product-metric" data-metric="debt">@endif
        <span class="product-metric-label">Còn phải thu</span>
        <span @class(['product-metric-value', 'text-[#f5222d]' => $debt['total'] > 0])>{{ Money::format($debt['total']) }}</span>
        <span class="product-metric-sub">{{ $debt['count'] ? $count($debt['count']).' dự án còn công nợ' : 'Mọi dự án đã thu đủ' }}</span>
      @if ($debtUrl)</a>@else</div>@endif
    </div>
  </div>

  <div class="ant-card ant-card-bordered product-index">
    {{-- Tab: tiền đã thu theo loại + "Chưa thu" (phát sinh khách chưa trả) — số khoản · tổng tiền theo tìm kiếm / bộ lọc --}}
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Loại khoản thu">
        @foreach (['' => 'Đã thu', 'new' => 'Thu mới', 'renewal' => 'Gia hạn', 'extra' => 'Phát sinh'] as $key => $label)
          @php $stat = $summary[$key === '' ? 'all' : $key]; $active = ! $unpaidTab && $filter['type'] === $key; @endphp
          <a href="{{ $url(['type' => $key === '' ? null : $key, 'status' => null]) }}" role="tab" aria-selected="{{ $active ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $active])>{{ $label }} <span class="product-view-count">{{ $count($stat['count']) }} · {{ Money::format($stat['total']) }}</span></a>
        @endforeach
        <a href="{{ $url(['status' => 'unpaid', 'type' => null]) }}" role="tab" aria-selected="{{ $unpaidTab ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $unpaidTab]) title="Phát sinh khách chưa trả: tính công nợ, chưa tính doanh thu">Chưa thu <span @class(['product-view-count', 'text-[#f5222d]' => $summary['unpaid']['count'] > 0])>{{ $count($summary['unpaid']['count']) }} · {{ Money::format($summary['unpaid']['total']) }}</span></a>
      </div>
    </div>

    {{-- Tìm kiếm (Enter để tìm) + nút lọc phương thức + khoảng ngày thu --}}
    <div class="product-search">
      <form method="GET" action="{{ route('backend.payments.index') }}" role="search">
        @foreach (\Illuminate\Support\Arr::except($current, ['q']) as $name => $value)
          <input type="hidden" name="{{ $name }}" value="{{ $value }}" />
        @endforeach
        <span class="ant-input-affix-wrapper">
          <span class="ant-input-prefix">{{ ant_icon('search') }}</span>
          <input type="text" name="q" value="{{ $filter['q'] }}" class="ant-input" id="payment-search" placeholder="Tìm theo dự án, domain, khách hàng, ghi chú" aria-label="Tìm khoản thu" />
          @if ($filter['q'] !== '')
            <span class="ant-input-suffix"><a href="{{ $url(['q' => null]) }}" class="ant-input-clear-icon" aria-label="Xóa từ khóa">{{ ant_icon('close-circle') }}</a></span>
          @endif
        </span>
      </form>
      <div class="filter-pills mt-2 flex flex-wrap items-center gap-2" id="filter-pills">
        @if ($filter['method'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="method">Phương thức: {{ Payment::METHODS[$filter['method']] }}</button><a href="{{ $url(['method' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc phương thức">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="method">Phương thức{{ ant_icon('down') }}</button>
        @endif
        {{-- Khoảng ngày thu: chọn xong (hoặc bấm khoảng chọn nhanh) thì tải lại danh sách; ⓧ bỏ lọc ngày --}}
        <div @class(['ant-picker ant-picker-range ant-picker-small payment-range', 'is-applied' => $filter['from'] !== '' || $filter['to'] !== '']) id="payment-range" data-picker="range"
          data-presets="{{ json_encode($presets, JSON_UNESCAPED_UNICODE) }}" data-url="{{ $url(['from' => null, 'to' => null]) }}">
          <div class="ant-picker-input ant-picker-input-active"><input placeholder="Từ ngày" readonly aria-label="Thu từ ngày" value="{{ $filter['from'] }}" /></div>
          <div class="ant-picker-range-separator"><span class="ant-picker-separator">{{ ant_icon('swap-right') }}</span></div>
          <div class="ant-picker-input"><input placeholder="Đến ngày" readonly aria-label="Thu đến ngày" value="{{ $filter['to'] }}" /></div>
          <div class="ant-picker-active-bar" style="left: 0px; width: 96px; position: absolute"></div>
          <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
          @if ($filter['from'] !== '' || $filter['to'] !== '')<span class="ant-picker-clear" role="button" aria-label="Bỏ lọc ngày">{{ ant_icon('close-circle') }}</span>@endif
        </div>
        @if ($filtering)
          <a href="{{ $url(['q' => null, 'method' => null, 'from' => null, 'to' => null]) }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-method">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach (Payment::METHODS as $key => $label)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['method'] === $key]) role="menuitem"><a href="{{ $url(['method' => $key]) }}">{{ $label }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <form method="POST" action="{{ route('backend.payments.bulk-destroy') }}" id="payment-bulk-form" @if ($canDelete) data-bulk-form @endif>
      @csrf
      <div class="relative">
        @if ($canDelete)
          {{-- Thanh thao tác hàng loạt: phủ lên tiêu đề bảng khi có dòng được chọn --}}
          <div class="product-bulk hidden" data-bulk-bar>
            <label class="ant-checkbox-wrapper"><span class="ant-checkbox ant-checkbox-indeterminate"><input type="checkbox" class="ant-checkbox-input" data-bulk-clear aria-label="Bỏ chọn tất cả" /><span class="ant-checkbox-inner"></span></span></label>
            <span class="whitespace-nowrap font-medium"><span data-bulk-count>0</span> đã chọn <span class="font-normal {{ $muted }} max-sm:hidden">· <span class="tabular-nums" data-bulk-total>0</span></span></span>
            <div class="ml-auto flex gap-2">
              <button type="button" class="ant-btn ant-btn-sm ant-btn-dangerous" data-bulk-delete data-title="Xóa %d khoản thu đã chọn?" data-content="Trạng thái thanh toán của các dự án liên quan sẽ được tính lại.">{{ ant_icon('delete') }}<span>Xóa</span></button>
            </div>
          </div>
        @endif

        <div class="ant-table-wrapper">
          <div class="ant-table ant-table-small">
            <div class="ant-table-container">
              <div class="ant-table-content" style="overflow: auto hidden">
                <table @class(['product-table payment-table', 'min-w-0' => $payments->isEmpty()]) style="table-layout: auto">
                  @if ($canDelete)<colgroup><col class="ant-table-selection-col" /></colgroup>@endif
                  <thead class="ant-table-thead">
                    <tr>
                      @if ($canDelete)
                        <th class="ant-table-cell ant-table-selection-column">
                          <div class="ant-table-selection">
                            <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" data-select-page aria-label="Chọn cả trang" @disabled($payments->isEmpty()) /><span class="ant-checkbox-inner"></span></span></label>
                          </div>
                        </th>
                      @endif
                      <th class="ant-table-cell whitespace-nowrap" data-col="date">{{ $unpaidTab ? 'Ngày phát sinh' : 'Ngày thu' }}</th>
                      <th class="ant-table-cell">Dự án</th>
                      <th class="ant-table-cell" data-col="type">Loại</th>
                      <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Số tiền (VND)</th>
                      <th class="ant-table-cell max-lg:hidden" data-col="method">Phương thức</th>
                      <th class="ant-table-cell max-xl:hidden" data-col="note">Ghi chú</th>
                      {{-- Điện thoại: không cột thao tác — chạm dòng để sửa, chọn dòng + "Xóa" ở thanh trên để xóa --}}
                      <th class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">Thao tác</th>
                    </tr>
                  </thead>
                  <tbody class="ant-table-tbody" id="payment-rows">
                    @forelse ($payments as $payment)
                      @php
                          $project = $payment->project; // (hộp thoại khoản thu nhận fixedProject riêng, không dùng biến này)
                          $typeTag = '<span class="ant-tag m-0 '.(Payment::TYPE_TAGS[$payment->type] ?? 'ant-tag-green').'">'.e($payment->typeLabel()).'</span>'
                              .($payment->isUnpaid() ? ' <span class="ant-tag ant-tag-red m-0">Chưa thu</span>' : '');
                          $data = ['id' => $payment->id, 'project_id' => $payment->project_id, 'amount' => $payment->amount, 'paid_at' => $payment->paid_at?->toDateString(), 'type' => $payment->type, 'status' => $payment->status, 'method' => $payment->method, 'note' => $payment->note];
                      @endphp
                      <tr class="ant-table-row ant-table-row-level-0 product-row" data-row-key="{{ $payment->id }}" @if ($canEdit) data-row-click="[data-payment-edit]" @endif>
                        @if ($canDelete)
                          <td class="ant-table-cell ant-table-selection-column">
                            <label class="ant-checkbox-wrapper"><span class="ant-checkbox"><input type="checkbox" class="ant-checkbox-input" name="ids[]" value="{{ $payment->id }}" data-row-check data-amount="{{ $payment->amount }}" aria-label="Chọn khoản thu {{ Money::format($payment->amount) }} của {{ $project->name }}" /><span class="ant-checkbox-inner"></span></span></label>
                          </td>
                        @endif
                        <td class="ant-table-cell tabular-nums" data-col="date">{{ $payment->paid_at?->format('d/m/Y') }}</td>
                        <td class="ant-table-cell">
                          <div class="min-w-[140px] max-w-[300px] max-sm:min-w-0">
                            @if ($canSeeProjects)
                              <a href="{{ route('backend.projects.show', $payment->project_id) }}" class="product-name wrap-anywhere">{{ $project->name }}</a>
                            @else
                              <span class="product-name wrap-anywhere">{{ $project->name }}</span>
                            @endif
                            {{-- Tablet (cột phương thức ẩn dưới 992px): ghi phương thức sau tên khách --}}
                            {{-- line-clamp + wrap-anywhere thay cho truncate: tên khách dài không giữ cột rộng (truncate làm bảng cuộn ngang ~1000px) --}}
                            <div class="line-clamp-1 whitespace-normal wrap-anywhere text-xs {{ $muted }} max-sm:line-clamp-none" @if ($project->customer) title="{{ $project->customer->name }}" @endif>{{ $project->customer?->name }}@if ($payment->methodLabel() !== '')<span class="max-sm:hidden lg:hidden">{{ $project->customer ? ' · ' : '' }}{{ $payment->methodLabel() }}</span>@endif</div>
                            {{-- Điện thoại: ngày, loại, phương thức ghi dưới tên dự án --}}
                            <div class="product-row-meta">{!! $typeTag !!} <span class="tabular-nums {{ $muted }}">{{ $payment->paid_at?->format('d/m/Y') }}{{ $payment->methodLabel() !== '' ? ' · '.$payment->methodLabel() : '' }}</span></div>
                          </div>
                        </td>
                        <td class="ant-table-cell" data-col="type"><div class="flex flex-col items-start gap-1">{!! $typeTag !!}</div></td>
                        <td class="ant-table-cell tabular-nums" style="text-align: right">
                          <span @class(['font-medium', 'text-[#fa541c]' => $payment->isUnpaid()]) @if ($payment->isUnpaid()) title="Chưa thu — đang tính vào công nợ" @endif>{{ Money::format($payment->amount) }}</span>
                          {{-- Khách trả khoản phát sinh: ghi nhận đã thu (dưới số tiền để có cả trên điện thoại) --}}
                          @if ($canEdit && $payment->isUnpaid())
                            <div class="mt-1"><button type="button" class="ant-btn ant-btn-sm" aria-label="Ghi nhận đã thu {{ Money::format($payment->amount) }} của {{ $project->name }}"
                              data-payment-open data-payment-receive data-payment="{{ json_encode($data) }}">{{ ant_icon('check') }}<span>Đã thu</span></button></div>
                          @endif
                        </td>
                        <td class="ant-table-cell max-lg:hidden" data-col="method">{!! $payment->methodLabel() !== '' ? e($payment->methodLabel()) : '<span class="'.$muted.'">—</span>' !!}</td>
                        <td class="ant-table-cell max-xl:hidden" data-col="note"><div class="payment-note {{ $muted }}" @if ($payment->note) title="{{ $payment->note }}" @endif>{{ $payment->note }}</div></td>
                        <td class="ant-table-cell product-actions-col max-sm:hidden" style="text-align: right">
                          <div class="product-row-actions">
                            @if ($canEdit)
                              <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" data-tooltip="Sửa" aria-label="Sửa khoản thu {{ Money::format($payment->amount) }}"
                                data-payment-open data-payment-edit data-payment="{{ json_encode($data) }}">{{ ant_icon('edit') }}</button>
                            @endif
                            @if ($canDelete)
                              <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-tooltip="Xóa" aria-label="Xóa khoản thu {{ Money::format($payment->amount) }}"
                                data-delete-url="{{ route('backend.payments.destroy', $payment) }}" data-delete-title="Xóa khoản thu {{ Money::format($payment->amount) }} của “{{ $project->name }}”?"
                                data-delete-content="Trạng thái thanh toán của dự án sẽ được tính lại.">{{ ant_icon('delete') }}</button>
                            @endif
                          </div>
                        </td>
                      </tr>
                    @empty
                      <tr class="ant-table-placeholder">
                        <td colspan="{{ $canDelete ? 8 : 7 }}" class="ant-table-cell">
                          <div class="ant-empty ant-empty-normal">
                            <div class="ant-empty-image">{{ ant_icon('search', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                            @if ($unpaidTab && ! $filtering)
                              <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có khoản phát sinh nào chưa thu</strong>Ghi nợ phát sinh khi khách đặt thêm việc ngoài hợp đồng.</p>
                              @if ($canAdd)<div class="ant-empty-footer"><button type="button" class="ant-btn" data-payment-open data-payment="{{ json_encode(['type' => 'extra', 'status' => 'unpaid']) }}">{{ ant_icon('file-text') }}<span>Ghi nợ phát sinh</span></button></div>@endif
                            @elseif ($filtering || $filter['type'] !== '' || $unpaidTab)
                              <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có khoản thu nào</strong>Thử khoảng ngày khác hoặc bỏ bớt bộ lọc.</p>
                              <div class="ant-empty-footer"><a href="{{ route('backend.payments.index') }}" class="ant-btn"><span>Xóa bộ lọc</span></a></div>
                            @else
                              <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Chưa có khoản thu nào</strong>Khoản thu ghi ở đây hoặc ở trang chi tiết dự án.</p>
                              @if ($canAdd)<div class="ant-empty-footer"><button type="button" class="ant-btn ant-btn-primary" data-payment-open>{{ ant_icon('plus') }}<span>Thêm khoản thu</span></button></div>@endif
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
      </div>
    </form>

    @if ($payments->total())
      <div class="product-index-footer">
        <span class="{{ $muted }}">Hiển thị {{ $count($payments->firstItem()) }}–{{ $count($payments->lastItem()) }} / {{ $count($payments->total()) }} khoản thu</span>
        {{ $payments->links('backend.partials.pagination') }}
      </div>
    @endif
  </div>
</div>

@if ($canAdd || $canEdit)
  @include('backend.payments.dialog', ['projects' => $projects, 'fixedProject' => null])
@endif
@endsection
