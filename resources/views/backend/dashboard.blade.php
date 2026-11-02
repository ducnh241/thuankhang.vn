@extends('layouts.backend')

@section('title', 'Dashboard')

{{-- Tổng quan theo giao diện template ProCMS (Ant Design): template không có trang dashboard nên dựng từ các thành phần
     của template — thẻ chỉ số như revenue.html, dải chỉ số (product-metrics) như projects.html, bảng nhỏ ant-table,
     tag trạng thái theo màu của projects.js. Biểu đồ 6 tháng vẽ bằng resources/admin/js/dashboard.js (charts.js). --}}
@php
    use App\Http\Controllers\Backend\DashboardController;
    use App\Models\Project;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $strong = 'text-[rgba(0,0,0,0.85)]';
    // Màu Ant Design: xanh dương, xanh lá, tím, đỏ, cam (chữ / nền nhạt)
    $tones = [
        'blue' => ['#007bff', '#e6f7ff'], 'green' => ['#52c41a', '#f6ffed'], 'purple' => ['#722ed1', '#f9f0ff'],
        'red' => ['#f5222d', '#fff1f0'], 'orange' => ['#fa8c16', '#fff7e6'],
    ];
    // Ô icon ở hàng tiêu đề (như ChartCard của Ant Design Pro): số bên dưới có trọn bề rộng thẻ, số lớn không đè icon
    $chip = fn (string $icon, string $tone) => '<span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg text-base" style="color: '.$tones[$tone][0].'; background: '.$tones[$tone][1].'">'.ant_icon($icon).'</span>';
    // Tag trạng thái dự án theo màu của template (projects.js STATUS_TAGS)
    $dayTag = fn (int $days, int $urgent = 7) => '<span class="ant-tag m-0 '.($days < 0 || $days <= $urgent ? ($days <= 0 ? 'ant-tag-red' : 'ant-tag-orange') : 'ant-tag-blue').'">'
        .($days < 0 ? 'Quá hạn '.abs($days).' ngày' : ($days === 0 ? 'Hôm nay' : 'Còn '.$days.' ngày')).'</span>';

    $hour = (int) now()->format('G');
    $greeting = $hour < 11 ? 'Chào buổi sáng' : ($hour < 14 ? 'Chào buổi trưa' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'));
    $weekdays = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    $todoCount = ($renewals['upcoming_count'] ?? 0) + ($renewals['overdue_count'] ?? 0) + ($hostings?->count() ?? 0);
    $prevSpan = '1–'.min($today->day, $today->subMonthNoOverflow()->daysInMonth).'/'.$today->subMonthNoOverflow()->month;
    $soonTo = $today->addDays(DashboardController::SOON_DAYS);
    $renewalUrl = route('backend.renewals.index', ['period' => 'custom', 'from' => $today->toDateString(), 'to' => $soonTo->toDateString(), 'renewed' => 'not_renewed']);
    $tileCount = ($renewals ? 2 : 0) + ($hostings ? 1 : 0);
    $tileCols = [1 => 'sm:grid-cols-1', 2 => 'sm:grid-cols-2', 3 => 'sm:grid-cols-3'][$tileCount] ?? '';
    $tile = 'product-metric max-sm:border-r-0 max-sm:border-b max-sm:border-[#f0f0f0] max-sm:last:border-b-0 hover:bg-[#fafafa]';
@endphp

@section('topbar')
  <div class="page-topbar-left">
    <h1 class="page-topbar-title">{{ $greeting }}, {{ auth()->user()->name }}</h1>
    <span class="page-topbar-divider max-md:hidden" aria-hidden="true"></span>
    <span class="page-topbar-sub min-w-0 shrink truncate max-md:hidden">{{ $weekdays[$today->dayOfWeek] }}, {{ $today->format('d/m/Y') }}@if ($renewals || $hostings) · {{ $todoCount ? $todoCount.' việc cần chú ý' : 'Không có việc gì cần xử lý gấp' }}@endif</span>
  </div>
  @can('report')
    <div class="page-topbar-right">
      <a href="{{ route('backend.reports.revenue') }}" class="ant-btn"><span class="max-sm:hidden">Xem báo cáo doanh thu</span><span class="sm:hidden">Báo cáo</span>{{ ant_icon('right') }}</a>
    </div>
  @endcan
@endsection

@section('content')
<div id="dashboard" class="mx-auto flex w-full max-w-[1200px] flex-col gap-4">
  {{-- Chỉ số chính --}}
  @if ($revenue || $projectStats || $customerStats || $debt)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      @if ($revenue)
        <div class="ant-card ant-card-bordered" data-stat="revenue">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">Doanh thu tháng {{ $today->month }}</div>
              {!! $chip('fund', 'green') !!}
            </div>
            <div class="ant-statistic-content wrap-anywhere"><span class="ant-statistic-content-value">{{ Money::format($revenue['current']) }}</span></div>
            <div class="text-xs">
              @if ($revenue['current'] == 0 && $revenue['previous'] == 0)
                <span class="{{ $muted }}">Chưa có khoản thu ({{ $prevSpan }} cũng chưa có)</span>
              @elseif ($revenue['growth'] === null)
                <span class="text-[#007bff]">Mới so với cùng kỳ tháng trước</span>
              @elseif ($revenue['growth'] == 0)
                <span class="{{ $muted }}">Bằng cùng kỳ {{ $prevSpan }}</span>
              @else
                <span class="{{ $revenue['growth'] > 0 ? 'text-[#52c41a]' : 'text-[#f5222d]' }}">{{ ant_icon($revenue['growth'] > 0 ? 'arrow-up' : 'arrow-down') }} {{ ($revenue['growth'] > 0 ? '+' : '').number_format($revenue['growth'], 1, ',', '.') }}%</span>
                <span class="{{ $muted }}">so với {{ $prevSpan }}</span>
              @endif
            </div>
          </div>
        </div>
      @endif
      @if ($projectStats)
        <div class="ant-card ant-card-bordered" data-stat="projects">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">Dự án đang chạy</div>
              {!! $chip('project', 'blue') !!}
            </div>
            <div class="ant-statistic-content wrap-anywhere"><span class="ant-statistic-content-value">{{ $projectStats['running'] }}</span> <span class="text-sm {{ $muted }}">/ {{ $projectStats['total'] }}</span></div>
            <div class="text-xs {{ $muted }}">{{ $projectStats['in_progress'] }} đang triển khai · {{ $projectStats['new_this_month'] }} mới tháng này</div>
          </div>
        </div>
      @endif
      @if ($customerStats)
        <div class="ant-card ant-card-bordered" data-stat="customers">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">Khách hàng</div>
              {!! $chip('contacts', 'purple') !!}
            </div>
            <div class="ant-statistic-content wrap-anywhere"><span class="ant-statistic-content-value">{{ $customerStats['total'] }}</span></div>
            <div class="text-xs {{ $muted }}">{{ $customerStats['new_this_month'] ? '+'.$customerStats['new_this_month'].' khách mới tháng này' : 'Chưa có khách mới tháng này' }}</div>
          </div>
        </div>
      @endif
      @if ($debt)
        <div @class(['ant-card ant-card-bordered', 'bg-[#fff1f0]' => $debt['total'] > 0]) data-stat="debt">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">Công nợ</div>
              {!! $chip($debt['total'] > 0 ? 'exclamation-circle' : 'check-circle', $debt['total'] > 0 ? 'red' : 'green') !!}
            </div>
            <div class="ant-statistic-content wrap-anywhere"><span @class(['ant-statistic-content-value', 'text-[#f5222d]' => $debt['total'] > 0, 'text-[#52c41a]' => $debt['total'] <= 0])>{{ Money::format($debt['total']) }}</span></div>
            <div class="text-xs {{ $muted }}">{{ $debt['count'] ? $debt['count'].' dự án chưa thu đủ' : 'Mọi dự án đã thu đủ' }}</div>
          </div>
        </div>
      @endif
    </div>
  @endif

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-3">
    {{-- Cần xử lý: gia hạn sắp tới / quá hạn, hosting sắp hết hạn --}}
    @if ($renewals || $hostings)
      <div class="ant-card ant-card-bordered lg:col-span-2" data-todo>
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Cần xử lý</div>
            @if ($renewals)<div class="ant-card-extra"><a href="{{ $renewalUrl }}">Xem tất cả gia hạn</a></div>@endif
          </div>
        </div>
        <div class="product-metrics grid-cols-1 border-b border-[#f0f0f0] {{ $tileCols }}">
          @if ($renewals)
            <a href="{{ $renewalUrl }}" class="{{ $tile }}">
              <span class="product-metric-label">Gia hạn trong {{ DashboardController::SOON_DAYS }} ngày</span>
              <span class="product-metric-value text-[#fa8c16]">{{ $renewals['upcoming_count'] }} dự án</span>
              <span class="product-metric-sub">Dự kiến thu {{ Money::format($renewals['upcoming_amount']) }}</span>
            </a>
            <a href="{{ route('backend.renewals.index', ['period' => 'overdue', 'renewed' => 'not_renewed']) }}" class="{{ $tile }}">
              <span class="product-metric-label">Quá hạn gia hạn (năm nay)</span>
              <span @class(['product-metric-value', 'text-[#f5222d]' => $renewals['overdue_count'], 'text-[#52c41a]' => ! $renewals['overdue_count']])>{{ $renewals['overdue_count'] }} dự án</span>
              <span class="product-metric-sub" title="Chưa ghi nhận gia hạn (trừ đã hủy, đã đóng, khách tự gia hạn)">Chưa ghi nhận gia hạn (trừ đã hủy, đã đóng, khách tự gia hạn)</span>
            </a>
          @endif
          @if ($hostings)
            <a href="{{ route('backend.hostings.index') }}" class="{{ $tile }}">
              <span class="product-metric-label">Hosting sắp hết hạn</span>
              <span @class(['product-metric-value', 'text-[#722ed1]' => $hostings->count(), 'text-[#52c41a]' => $hostings->isEmpty()])>{{ $hostings->count() }} hosting</span>
              <span class="product-metric-sub">Trong {{ DashboardController::SOON_DAYS }} ngày hoặc đã quá hạn</span>
            </a>
          @endif
        </div>

        @if ($renewals)
          <div class="ant-table ant-table-small">
            <div class="ant-table-container">
              <div class="ant-table-content overflow-x-auto">
                <table style="table-layout: auto">
                  <thead class="ant-table-thead">
                    <tr>
                      <th class="ant-table-cell">Dự án đến hạn gia hạn</th>
                      {{-- Điện thoại: bỏ cột phí, ghi phí dưới tên khách (như bảng sản phẩm của template) --}}
                      <th class="ant-table-cell whitespace-nowrap max-sm:hidden" style="text-align: right">Phí gia hạn</th>
                      <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Hạn</th>
                    </tr>
                  </thead>
                  <tbody class="ant-table-tbody">
                    @forelse ($renewals['upcoming'] as $row)
                      <tr class="ant-table-row">
                        <td class="ant-table-cell">
                          @can('project')
                            <a href="{{ route('backend.projects.show', $row['project']) }}" class="font-medium wrap-anywhere">{{ $row['project']->name }}</a>
                          @else
                            <div class="font-medium wrap-anywhere {{ $strong }}">{{ $row['project']->name }}</div>
                          @endcan
                          <div class="text-xs {{ $muted }}">{{ $row['project']->customer?->name }}</div>
                          <div class="text-xs tabular-nums sm:hidden {{ $muted }}">Phí gia hạn {{ Money::format($row['project']->renewalTotal()) }}</div>
                        </td>
                        <td class="ant-table-cell tabular-nums whitespace-nowrap max-sm:hidden" style="text-align: right">{{ Money::format($row['project']->renewalTotal()) }}</td>
                        <td class="ant-table-cell whitespace-nowrap" style="text-align: right">
                          <div class="font-medium tabular-nums {{ $strong }}">{{ $row['due']->format('d/m') }}</div>
                          {!! $dayTag($row['days']) !!}
                        </td>
                      </tr>
                    @empty
                      <tr class="ant-table-placeholder"><td colspan="3" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Không có dự án nào đến hạn gia hạn trong {{ DashboardController::SOON_DAYS }} ngày tới.</p></div></td></tr>
                    @endforelse
                  </tbody>
                </table>
              </div>
            </div>
          </div>
          @if ($renewals['upcoming_count'] > count($renewals['upcoming']))
            <div class="border-t border-[#f0f0f0] px-4 py-2 text-xs {{ $muted }}">Còn {{ $renewals['upcoming_count'] - count($renewals['upcoming']) }} dự án nữa · <a href="{{ $renewalUrl }}">xem tất cả</a></div>
          @endif
        @endif

        @if ($hostings && $hostings->isNotEmpty())
          <div class="ant-table ant-table-small border-t border-[#f0f0f0]">
            <div class="ant-table-container">
              <div class="ant-table-content overflow-x-auto">
                <table style="table-layout: auto">
                  <thead class="ant-table-thead">
                    <tr>
                      <th class="ant-table-cell">Hosting sắp hết hạn</th>
                      <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Dự án</th>
                      <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Hết hạn</th>
                    </tr>
                  </thead>
                  <tbody class="ant-table-tbody">
                    @foreach ($hostings as $hosting)
                      <tr class="ant-table-row">
                        <td class="ant-table-cell">
                          @can('hosting/edit')
                            <a href="{{ route('backend.hostings.edit', $hosting) }}" class="font-medium wrap-anywhere">{{ $hosting->name }}</a>
                          @else
                            <div class="font-medium wrap-anywhere {{ $strong }}">{{ $hosting->name }}</div>
                          @endcan
                        </td>
                        <td class="ant-table-cell tabular-nums" style="text-align: right">{{ $hosting->projects_count }}</td>
                        <td class="ant-table-cell whitespace-nowrap" style="text-align: right">
                          <div class="font-medium tabular-nums {{ $strong }}">{{ $hosting->expire_date->format('d/m/Y') }}</div>
                          {!! $dayTag($hosting->daysLeft()) !!}
                        </td>
                      </tr>
                    @endforeach
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        @endif
      </div>
    @endif

    {{-- Doanh thu 6 tháng --}}
    @if ($revenue)
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Doanh thu 6 tháng</div>
            @can('report')<div class="ant-card-extra"><a href="{{ route('backend.reports.revenue') }}">Chi tiết</a></div>@endcan
          </div>
        </div>
        <div class="ant-card-body">
          <div id="dashboard-months"></div>
          <div class="mt-2 text-xs {{ $muted }}">Tháng trước cả tháng: <b class="{{ $strong }} tabular-nums">{{ Money::format($revenue['previous_full']) }}</b></div>
        </div>
      </div>
    @endif
  </div>

  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
    {{-- Khoản thu mới nhất --}}
    @if ($recentPayments)
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Khoản thu mới nhất</div>
            <div class="ant-card-extra"><a href="{{ route('backend.payments.index') }}">Tất cả</a></div>
          </div>
        </div>
        <div class="ant-table ant-table-small">
          <div class="ant-table-container">
            <div class="ant-table-content overflow-x-auto">
              <table style="table-layout: auto">
                <tbody class="ant-table-tbody">
                  @forelse ($recentPayments as $payment)
                    <tr class="ant-table-row">
                      <td class="ant-table-cell">
                        <div class="font-medium wrap-anywhere {{ $strong }}">{{ $payment->project->name }}</div>
                        <div class="text-xs {{ $muted }}">{{ $payment->paid_at?->format('d/m/Y') }} · {{ $payment->project->customer?->name }}</div>
                      </td>
                      <td class="ant-table-cell whitespace-nowrap" style="text-align: right">
                        <div class="font-semibold tabular-nums text-[#52c41a]">+{{ Money::format($payment->amount) }}</div>
                        <span class="ant-tag m-0 {{ \App\Models\Payment::TYPE_TAGS[$payment->type] ?? 'ant-tag-green' }}">{{ $payment->typeLabel() }}</span>
                      </td>
                    </tr>
                  @empty
                    <tr class="ant-table-placeholder"><td colspan="2" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có khoản thu nào.</p></div></td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    @endif

    {{-- Dự án mới nhất --}}
    @if ($recentProjects)
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-head">
          <div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Dự án mới nhất</div>
            <div class="ant-card-extra"><a href="{{ route('backend.projects.index') }}">Tất cả</a></div>
          </div>
        </div>
        <div class="ant-table ant-table-small">
          <div class="ant-table-container">
            <div class="ant-table-content overflow-x-auto">
              <table style="table-layout: auto">
                <tbody class="ant-table-tbody">
                  @forelse ($recentProjects as $project)
                    <tr class="ant-table-row">
                      <td class="ant-table-cell">
                        <a href="{{ route('backend.projects.show', $project) }}" class="font-medium wrap-anywhere">{{ $project->name }}</a>
                        <div class="text-xs {{ $muted }}">#{{ $project->id }} · {{ $project->customer?->name }}</div>
                      </td>
                      <td class="ant-table-cell whitespace-nowrap" style="text-align: right">
                        <div class="tabular-nums {{ $strong }}">{{ Money::format($project->price) }}</div>
                        <span class="ant-tag m-0 {{ Project::STATUS_TAGS[$project->status] ?? '' }}">{{ $project->statusLabel() }}</span>
                      </td>
                    </tr>
                  @empty
                    <tr class="ant-table-placeholder"><td colspan="2" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có dự án nào.</p></div></td></tr>
                  @endforelse
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    @endif
  </div>

  @if (! $revenue && ! $projectStats && ! $customerStats && ! $renewals && ! $hostings && ! $recentPayments)
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-body">
        <div class="ant-empty"><p class="ant-empty-description">Chào mừng bạn đến với {{ config('app.name') }}. Tài khoản của bạn chưa được cấp quyền xem mục nào — liên hệ quản trị viên nếu cần.</p></div>
      </div>
    </div>
  @endif
</div>

@if ($revenue)
  @php
      $monthStart = $today->startOfMonth();
      $chart = ['months' => array_map(fn ($row, $i) => [
          'label' => $row['label'],
          'title' => 'Tháng '.$monthStart->subMonthsNoOverflow(5 - $i)->format('n/Y'),
          'value' => round((float) $row['value'], 2),
          'current' => (bool) $row['current'],
      ], $revenue['months'], array_keys($revenue['months']))];
  @endphp
  <script type="application/json" id="dashboard-data">{!! json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
@endsection
