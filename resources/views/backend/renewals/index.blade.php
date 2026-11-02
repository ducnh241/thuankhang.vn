@extends('layouts.backend')

@section('title', 'Nhắc gia hạn')

{{-- Template ProCMS không có trang này: dựng như các trang danh sách (orders.html) — tab tình trạng, nút chọn kỳ + nút lọc
     hosting (của mình / của khách), bảng dự án đến kỳ gia hạn chia khối theo tình trạng (tiêu đề khối có chấm màu như tab,
     số dự án; khối "Chưa gia hạn" — việc mình phải làm — đóng khung vàng, ghi tiền dự kiến thu); hộp thoại Ant Design
     trong trang: soạn thông báo gửi khách, ghi nhận đã gia hạn, khách hủy gia hạn. Kỳ tùy chọn (from / to) chỉ vào qua link
     (dashboard, trang dự án). Nghiệp vụ ở RenewalController / App\Support\Renewal. JS: list.js (nút lọc), renewal.js. --}}
@php
    use App\Http\Controllers\Backend\RenewalController;
    use App\Models\Payment;
    use App\Models\Project;
    use App\Support\Money;
    use App\Support\Renewal;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $me = auth()->user();
    $canRenew = $me->can('renewal/renew');
    $canSeeProjects = $me->can('project');
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');

    // Link danh sách: giữ kỳ (và khoảng ngày nếu kỳ tùy chọn) + tab, đổi vài tham số
    $current = array_filter([
        'period' => $filter['period'] !== 'this_week' ? $filter['period'] : null,
        'from' => $filter['period'] === 'custom' ? $from->toDateString() : null,
        'to' => $filter['period'] === 'custom' ? $to->toDateString() : null,
        'renewed' => $filter['renewed'] ?: null,
        'hosting' => $filter['hosting'] ?: null,
    ]);
    $url = fn (array $changes = []) => route('backend.renewals.index', array_filter([...$current, ...$changes], fn ($v) => $v !== null && $v !== ''));

    // Nhóm tình trạng: chấm màu trên tab = chấm ở tiêu đề khối trong bảng (bảng chia khối theo nhóm, không tô màu dòng)
    $stateStyle = [
        'not_renewed' => ['Chưa gia hạn', '#faad14'],
        'self_renew' => ['Khách tự gia hạn', '#13c2c2'],
        'renewed' => ['Đã gia hạn', '#52c41a'],
        'cancelled' => ['Đã hủy gia hạn', '#ff4d4f'],
    ];
    $overdueEmpty = $filter['period'] === 'overdue' && $to->lt($from);
    $rangeText = match (true) {
        $overdueEmpty => 'Năm '.$today->year.' chưa có kỳ nào qua hạn',
        $filter['period'] === 'overdue' => 'Đã qua hạn từ '.$from->format('d/m/Y').' đến '.$to->format('d/m/Y'),
        default => 'Từ '.$from->format('d/m/Y').' đến '.$to->format('d/m/Y'),
    };
    $colspan = 6;
    // Tiêu đề từng khối trong bảng: nhóm nào cần làm gì
    $groupHints = [
        'not_renewed' => 'Mình lo gia hạn — nhắc khách và ghi nhận khi khách trả',
        'self_renew' => 'Chạy trên hosting của khách, khách tự gia hạn — chỉ để theo dõi',
        'renewed' => 'Đã ghi nhận gia hạn trong kỳ',
        'cancelled' => 'Khách đã hủy gia hạn / dự án đã đóng — để soát lại',
    ];
    $groups = $projects->groupBy(fn ($project) => $states[$project->id]);
    $group = null;
@endphp

@section('actions')
  @can('renewal/template')
    <a href="{{ route('backend.renewals.template') }}" class="ant-btn" aria-label="Mẫu thông báo">{{ ant_icon('file') }}<span class="max-sm:hidden">Mẫu thông báo</span></a>
  @endcan
@endsection

@section('content')
<div id="renewals" class="mx-auto max-w-[1200px]" data-list>
  @if ($undated->isNotEmpty())
    {{-- Không có cả ngày gia hạn lẫn ngày bàn giao: không xếp được vào kỳ nào → báo để không bị sót --}}
    <div class="ant-alert ant-alert-warning ant-alert-with-description mb-4" role="alert">
      <span class="ant-alert-icon">{{ ant_icon('exclamation-circle') }}</span>
      <div class="ant-alert-content">
        <div class="ant-alert-message">{{ $undated->count() }} dự án chưa có ngày gia hạn lẫn ngày bàn giao nên không hiện ở kỳ nào</div>
        <div class="ant-alert-description">
          @foreach ($undated->take(10) as $item)@if ($canSeeProjects)<a href="{{ route('backend.projects.show', $item) }}">{{ $item->name }}</a>@else{{ $item->name }}@endif{{ $loop->last ? '' : ', ' }}@endforeach{{ $undated->count() > 10 ? '…' : '' }}. Hãy nhập ngày trong dự án.
        </div>
      </div>
    </div>
  @endif

  <div class="ant-card ant-card-bordered product-index">
    {{-- Tab tình trạng (số dự án trong kỳ đang xem) --}}
    <div class="product-index-bar">
      <div class="product-views" role="tablist" aria-label="Tình trạng gia hạn">
        <a href="{{ $url(['renewed' => null]) }}" role="tab" aria-selected="{{ $filter['renewed'] === '' ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['renewed'] === ''])>Tất cả <span class="product-view-count">{{ $count($counts['all']) }}</span></a>
        @foreach ($stateStyle as $key => [$label, $dot])
          <a href="{{ $url(['renewed' => $key]) }}" role="tab" aria-selected="{{ $filter['renewed'] === $key ? 'true' : 'false' }}" @class(['product-view', 'is-active' => $filter['renewed'] === $key])><span class="renewal-dot" style="background: {{ $dot }}" aria-hidden="true"></span>{{ $label }} <span class="product-view-count">{{ $count($counts[$key]) }}</span></a>
        @endforeach
      </div>
    </div>

    {{-- Kỳ gia hạn + lọc hosting (số trên tab và tiền dự kiến theo bộ lọc đang chọn) --}}
    <div class="product-search">
      <div class="filter-pills flex flex-wrap items-center gap-2" id="filter-pills">
        <button type="button" class="filter-pill" data-filter="period">Kỳ: {{ RenewalController::PERIODS[$filter['period']] }}{{ ant_icon('down') }}</button>
        @if ($filter['hosting'] !== '')
          <span class="filter-pill is-applied"><button type="button" class="filter-pill-label" data-filter="hosting">Hosting: {{ RenewalController::HOSTINGS[$filter['hosting']] }}</button><a href="{{ $url(['hosting' => null]) }}" class="filter-pill-clear" aria-label="Bỏ lọc hosting">{{ ant_icon('close') }}</a></span>
        @else
          <button type="button" class="filter-pill" data-filter="hosting">Hosting{{ ant_icon('down') }}</button>
        @endif
        @if ($filter['renewed'] !== '' || $filter['hosting'] !== '' || $filter['period'] !== 'this_week')
          <a href="{{ route('backend.renewals.index') }}" class="ml-1 whitespace-nowrap" id="clear-filters">Xóa bộ lọc</a>
        @endif
      </div>
      <template id="filter-hosting">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach (RenewalController::HOSTINGS as $key => $label)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['hosting'] === $key]) role="menuitem"><a href="{{ $url(['hosting' => $key]) }}">{{ $label }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
      <template id="filter-period">
        <div class="ant-dropdown">
          <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical" role="menu">
            @foreach (collect(RenewalController::PERIODS)->except('custom') as $key => $label)
              <li @class(['ant-dropdown-menu-item', 'ant-dropdown-menu-item-selected' => $filter['period'] === $key]) role="menuitem"><a href="{{ $url(['period' => $key === 'this_week' ? null : $key, 'from' => null, 'to' => null]) }}">{{ $label }}</a></li>
            @endforeach
          </ul>
        </div>
      </template>
    </div>

    <div class="ant-table-wrapper">
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content" style="overflow: auto hidden">
            <table class="product-table renewal-table" style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell" data-col="due">Hạn gia hạn</th>
                  <th class="ant-table-cell">Dự án</th>
                  <th class="ant-table-cell max-xl:hidden" data-col="hosting">Hosting</th>
                  <th class="ant-table-cell max-md:hidden whitespace-nowrap" data-col="fee" style="text-align: right">Phí gia hạn</th>
                  <th class="ant-table-cell" data-col="state">Tình trạng</th>
                  <th class="ant-table-cell product-actions-col" style="text-align: right">Thao tác</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody" id="renewal-rows">
                @forelse ($projects as $project)
                  @php
                      $date = $project->renewalDate(); // next_renewal_date, chưa có thì theo ngày bàn giao
                      $occurrence = $due[$project->id];
                      $state = $states[$project->id];
                      $days = Renewal::daysLeft($occurrence, $today);
                      $tag = fn (string $label, string $class) => '<span class="ant-tag m-0 '.$class.'">'.e($label).'</span>';
                      // Chỉ khối "Chưa gia hạn" (việc mình phải làm) dùng tag màu theo độ gấp; khối khác đã có tiêu đề riêng
                      $stateTag = match (true) {
                          // Đã hủy / đã đóng: chữ xám (cả dòng đã mờ), vẫn phân biệt "hủy gia hạn" với "đóng dự án"
                          $state === 'cancelled' => '<span class="text-xs '.$muted.'">'.e($project->statusLabel()).'</span>',
                          // Khách tự gia hạn: không báo đỏ "quá hạn" vì không phải việc của mình — chỉ ghi số ngày bằng chữ xám
                          $state === 'self_renew' => '<span class="text-xs tabular-nums '.$muted.'">'.($days < 0 ? 'Quá '.abs($days).' ngày' : ($days === 0 ? 'Hôm nay' : 'Còn '.$days.' ngày')).'</span>',
                          $state === 'renewed' => '<span class="text-xs text-[#52c41a]">'.ant_icon('check-circle').' Đã gia hạn</span>',
                          $days < 0 => $tag('Quá hạn '.abs($days).' ngày', 'ant-tag-red'),
                          $days === 0 => $tag('Hết hạn hôm nay', 'ant-tag-red'),
                          default => $tag('Còn '.$days.' ngày', $days <= 7 ? 'ant-tag-orange' : 'ant-tag-blue'),
                      };
                      // Trạng thái dự án chỉ ghi khi khác thường (đang đàm phán / đang triển khai); đã bàn giao, đang bảo trì là bình thường
                      $statusTag = in_array($project->status, ['lead', 'in_progress'], true) ? $tag($project->statusLabel(), Project::STATUS_TAGS[$project->status] ?? '') : '';
                      $renewData = [
                          'id' => $project->id,
                          'name' => $project->name,
                          'hosting' => (float) $project->renewal_hosting_amount,
                          'domain' => (float) $project->renewal_domain_amount,
                          'due' => $occurrence->toDateString(),
                          'due_label' => $occurrence->format('d/m/Y'),
                          'current' => $date->format('d/m/Y'),
                          'next' => Renewal::nextDate($project, $occurrence)->format('d/m/Y'),
                      ];
                      $fee = Money::format($project->renewalTotal());
                  @endphp
                  @if ($state !== $group)
                    @php $group = $state; @endphp
                    <tr class="renewal-group" data-group="{{ $state }}">
                      <td colspan="{{ $colspan }}" class="ant-table-cell">
                        <span class="renewal-dot" style="background: {{ $stateStyle[$state][1] }}" aria-hidden="true"></span><span class="renewal-group-title">{{ $stateStyle[$state][0] }}</span>
                        <span class="renewal-group-count">{{ $count($groups[$state]->count()) }} dự án</span>
                        @if ($state === 'not_renewed')
                          <span class="renewal-group-sum" id="renewal-expected" title="Tổng phí gia hạn các dự án trong khối này">Dự kiến thu <strong class="tabular-nums">{{ Money::format($expected) }}</strong></span>
                        @endif
                        <span class="renewal-group-hint">{{ $groupHints[$state] }}</span>
                      </td>
                    </tr>
                  @endif
                  {{-- Khối "Chưa gia hạn" đóng khung: hàng cuối khối mang lớp renewal-box-end (vẽ cạnh dưới khung) --}}
                  <tr data-renewal-row="{{ $state }}" data-row-key="{{ $project->id }}" @class(['ant-table-row', 'renewal-row-'.$state, 'renewal-box-end' => $state === 'not_renewed' && $groups[$state]->last()->is($project)])>
                    <td class="ant-table-cell" data-col="due">
                      <div class="max-w-[124px] whitespace-normal">
                      <div class="font-medium tabular-nums">{{ $occurrence->format('d/m/Y') }}</div>
                      @if ($project->renewalDateFromDelivery())
                        <div class="text-xs tabular-nums {{ $muted }}" data-tooltip="Dự án chưa có ngày gia hạn: kỳ gia hạn tính theo ngày bàn giao">Theo ngày bàn giao: {{ $project->deliver_date->format('d/m/Y') }}</div>
                      @elseif (! $date->isSameDay($occurrence))
                        <div class="text-xs tabular-nums {{ $muted }}" data-tooltip="Ngày gia hạn kế tiếp đang lưu trong dự án">{{ $state === 'renewed' ? 'Kế tiếp' : 'Đang lưu' }}: {{ $date->format('d/m/Y') }}</div>
                      @endif
                      </div>
                    </td>
                    <td class="ant-table-cell">
                      <div class="min-w-[140px] max-w-[320px] whitespace-normal max-[576px]:min-w-0">
                        @if ($canSeeProjects)
                          <a href="{{ route('backend.projects.show', $project) }}" class="product-name wrap-anywhere">{{ $project->name }}</a>
                        @else
                          <span class="product-name wrap-anywhere">{{ $project->name }}</span>
                        @endif
                        {{-- Domain bấm được: mở website ở tab mới (domain không hợp lệ thì chỉ hiện chữ) --}}
                        <div class="text-xs {{ $muted }} wrap-anywhere">@if ($project->primary_domain)@if ($siteUrl = $project->domainUrl())<a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer" class="renewal-domain" title="Mở {{ $siteUrl }} ở tab mới">{{ $project->primary_domain }}</a>@else{{ $project->primary_domain }}@endif{{ $project->customer?->name ? ' · ' : '' }}@endif{{ $project->customer?->name }}</div>
                        {{-- Điện thoại: hạn, tình trạng, phí ghi dưới tên --}}
                        <div class="product-row-meta">
                          <span class="tabular-nums">{{ $occurrence->format('d/m/Y') }}</span> {!! $stateTag !!} {!! $statusTag !!}
                          <div class="tabular-nums {{ $muted }}">{{ $fee }}</div>
                        </div>
                      </div>
                    </td>
                    <td class="ant-table-cell max-xl:hidden" data-col="hosting">
                      @forelse ($project->hostings as $hosting)
                        <div @class(['whitespace-nowrap', $muted => $hosting->ownership !== 'mine']) @if ($hosting->ownership !== 'mine') data-tooltip="Hosting của khách" @endif>{{ $hosting->name }}</div>
                      @empty
                        <span class="{{ $muted }}">—</span>
                      @endforelse
                    </td>
                    <td class="ant-table-cell max-md:hidden" data-col="fee" style="text-align: right">
                      <div class="font-medium tabular-nums">{{ $fee }}</div>
                      <div class="text-xs tabular-nums whitespace-normal {{ $muted }}">H {{ Money::format($project->renewal_hosting_amount) }} · D {{ Money::format($project->renewal_domain_amount) }}</div>
                    </td>
                    <td class="ant-table-cell" data-col="state">
                      <div class="flex flex-col items-start gap-1">
                        {!! $stateTag !!}
                        {!! $statusTag !!}
                      </div>
                    </td>
                    <td class="ant-table-cell product-actions-col" style="text-align: right">
                      <div class="product-row-actions">
                        @isset($messages[$project->id])
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" data-message-open="{{ $project->id }}" data-message-kind="hosting" data-tooltip="Thông báo gia hạn hosting" aria-label="Thông báo gia hạn hosting">{{ ant_icon('cloud-server') }}</button>
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only" data-message-open="{{ $project->id }}" data-message-kind="full" data-tooltip="Thông báo gia hạn hosting + domain" aria-label="Thông báo gia hạn hosting + domain">{{ ant_icon('mail') }}</button>
                        @endisset
                        {{-- Ghi nhận gia hạn: chỉ dự án mình lo. Khách tự gia hạn không thu tiền qua mình, nhưng khách vẫn có thể
                             báo hủy. Đã hủy / đã đóng: không thao tác ở đây (đổi trạng thái dự án trước nếu khách quay lại) --}}
                        @if ($canRenew && $state === 'not_renewed')
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only renewal-renew-btn" data-renew-open="{{ json_encode($renewData) }}" data-tooltip="Ghi nhận đã gia hạn" aria-label="Ghi nhận đã gia hạn">{{ ant_icon('check-circle') }}</button>
                        @endif
                        @if ($canRenew && in_array($state, ['not_renewed', 'self_renew'], true))
                          <button type="button" class="ant-btn ant-btn-text ant-btn-icon-only ant-btn-dangerous" data-cancel-open="{{ json_encode(['id' => $project->id, 'name' => $project->name]) }}" data-tooltip="Khách hủy gia hạn" aria-label="Khách hủy gia hạn">{{ ant_icon('close-circle') }}</button>
                        @endif
                      </div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder">
                    <td colspan="{{ $colspan }}" class="ant-table-cell">
                      <div class="ant-empty ant-empty-normal">
                        <div class="ant-empty-image">{{ ant_icon('sync', '', 'font-size: 40px; color: rgba(0, 0, 0, 0.25)') }}</div>
                        <p class="ant-empty-description"><strong class="block text-[rgba(0,0,0,0.85)]">Không có dự án nào cần gia hạn</strong>{{ $filter['renewed'] !== '' ? 'Thử chọn tab "Tất cả" hoặc kỳ khác.' : 'Chọn kỳ khác để xem các dự án sắp đến hạn.' }}</p>
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

    <div class="product-index-footer">
      <span class="{{ $muted }}">{{ $rangeText }} · {{ $count($projects->count()) }} dự án</span>
    </div>
  </div>
</div>

@include('backend.renewals.dialogs')
<script type="application/json" id="renewal-messages">@json($messages)</script>
@endsection
