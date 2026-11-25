@extends('layouts.backend')

@section('title', 'Dashboard')

{{-- Dashboard (A1), mỗi khối theo quyền: việc cần xử lý, doanh thu (tiền hàng đơn đã thanh toán), thẻ số liệu (sản phẩm,
     đơn, đánh giá, thành viên, KYC), đơn theo trạng thái, thành viên theo cấp bậc, chỉ số hệ thống, hoạt động gần đây. --}}
@php
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $strong = 'text-[rgba(0,0,0,0.85)]';
    $count = fn ($n) => number_format((int) $n, 0, ',', '.');
    $hour = (int) now()->format('G');
    $greeting = $hour < 11 ? 'Chào buổi sáng' : ($hour < 14 ? 'Chào buổi trưa' : ($hour < 18 ? 'Chào buổi chiều' : 'Chào buổi tối'));
    $weekdays = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    $money = fn ($v) => \App\Support\Money::format((int) $v).' đ';
    $value = fn ($v) => is_int($v) ? $count($v) : $v;
    $nothing = ! $todo && ! $revenue && ! $compare && ! $cards && ! $stats && $recentLogs === null;
@endphp

@section('topbar')
  <div class="page-topbar-left">
    <h1 class="page-topbar-title">{{ $greeting }}, {{ auth()->user()->name }}</h1>
    <span class="page-topbar-divider max-md:hidden" aria-hidden="true"></span>
    <span class="page-topbar-sub min-w-0 shrink truncate max-md:hidden">{{ $weekdays[$today->dayOfWeek] }}, {{ $today->format('d/m/Y') }}</span>
  </div>
@endsection

@section('content')
<div id="dashboard" class="mx-auto flex w-full max-w-[1200px] flex-col gap-4">
  {{-- Việc cần xử lý --}}
  @if ($todo)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4" data-section="todo">
      @foreach ($todo as $key => [$label, $number, $hint, $url])
        <a href="{{ $url }}" @class(['ant-card ant-card-bordered block hover:bg-[#fafafa]', 'border-[#ffd591]! bg-[#fff7e6]' => $number > 0]) data-todo="{{ $key }}" data-count="{{ $number }}">
          <div class="ant-card-body flex items-center gap-3">
            <span @class(['inline-flex size-10 shrink-0 items-center justify-center rounded-full text-lg font-semibold tabular-nums',
              'bg-[#fa8c16] text-white' => $number > 0, 'bg-[#f5f5f5] text-[rgba(0,0,0,0.45)]' => $number === 0])>{{ $count($number) }}</span>
            <span class="min-w-0">
              <span class="block font-medium {{ $strong }}">{{ $label }}</span>
              <span class="block truncate text-xs {{ $muted }}">{{ $number > 0 ? $hint : 'Không có việc chờ' }}</span>
            </span>
          </div>
        </a>
      @endforeach
    </div>
  @endif

  {{-- Doanh thu --}}
  @if ($revenue)
    <div class="ant-card ant-card-bordered" data-section="revenue">
      <div class="ant-card-head"><div class="ant-card-head-wrapper">
        <div class="ant-card-head-title">Doanh thu</div>
        <div class="ant-card-extra text-xs {{ $muted }}">Tiền hàng các đơn đã thanh toán (gồm đơn trả bằng ví, không tính phí ship COD)</div>
      </div></div>
      <div class="ant-card-body"><div class="grid grid-cols-1 gap-6 lg:grid-cols-[1.3fr_1fr_1fr]">
        <div>
          <div class="ant-statistic-title mb-0">Tháng {{ $today->format('m/Y') }}</div>
          <div class="mt-1 text-3xl font-semibold tabular-nums {{ $strong }}" data-revenue-month>{{ $money($revenue['month']) }}</div>
          <div class="mt-1 text-sm tabular-nums">
            @if ($revenue['change'] !== null)
              <span @class(['font-medium', 'text-[#389e0d]' => $revenue['change'] >= 0, 'text-[#cf1322]' => $revenue['change'] < 0])>{{ $revenue['change'] >= 0 ? '▲ +' : '▼ ' }}{{ $revenue['change'] }}%</span>
              <span class="{{ $muted }}">so với cùng kỳ tháng trước ({{ $money($revenue['last_month_to_date']) }})</span>
            @else
              <span class="{{ $muted }}">Cùng kỳ tháng trước chưa có doanh thu</span>
            @endif
          </div>
          <div class="mt-1 text-xs {{ $muted }} tabular-nums">Cả tháng trước: {{ $money($revenue['last_month']) }}</div>
        </div>
        <dl class="m-0 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-sm tabular-nums">
          <dt class="{{ $muted }}">Hôm nay</dt><dd class="m-0 text-right font-medium" data-revenue-today>{{ $money($revenue['today']) }}</dd>
          <dt class="{{ $muted }}">Đơn đã thanh toán tháng này</dt><dd class="m-0 text-right font-medium">{{ $count($revenue['month_count']) }}</dd>
          <dt class="{{ $muted }}">Giá trị đơn trung bình</dt><dd class="m-0 text-right font-medium">{{ $money($revenue['average']) }}</dd>
          <dt class="{{ $muted }}">Từ trước tới nay</dt><dd class="m-0 text-right font-semibold" data-revenue-total>{{ $money($revenue['total']) }}</dd>
        </dl>
        <div class="text-sm">
          <div class="mb-2 {{ $muted }}">Tháng này theo hình thức thanh toán</div>
          @foreach ($revenue['by_method'] as $method => $row)
            @php $share = $revenue['month'] > 0 ? round($row['total'] / $revenue['month'] * 100) : 0; @endphp
            <div class="mb-2" data-method="{{ $method }}">
              <div class="flex justify-between gap-3 tabular-nums"><span>{{ $row['label'] }} <span class="text-xs {{ $muted }}">· {{ $count($row['count']) }} đơn</span></span><span class="font-medium">{{ $money($row['total']) }}</span></div>
              <div class="mt-1 h-1.5 overflow-hidden rounded bg-[#f0f0f0]"><div class="h-full {{ $method === 'wallet' ? 'bg-[#faad14]' : 'bg-[#1677ff]' }}" style="width: {{ $share }}%"></div></div>
            </div>
          @endforeach
        </div>
      </div></div>
    </div>
  @endif

  {{-- So sánh với kỳ trước: tháng / quý / năm --}}
  @if ($compare)
    <div class="ant-card ant-card-bordered" data-section="compare">
      <div class="ant-card-head"><div class="ant-card-head-wrapper">
        <div class="ant-card-head-title">So sánh với kỳ trước</div>
        <div class="ant-card-extra">
          <div class="ant-btn-group" role="group" aria-label="Chọn kỳ so sánh">
            @foreach (\App\Http\Controllers\Backend\DashboardController::PERIODS as $key => [$label])
              <a href="{{ route('backend.dashboard', $key === 'thang' ? [] : ['ky' => $key]) }}" @class(['ant-btn ant-btn-sm', 'ant-btn-primary' => $period === $key]) @if ($period === $key) aria-current="true" @endif data-period="{{ $key }}">{{ $label }}</a>
            @endforeach
          </div>
        </div>
      </div></div>
      @if ($trend)
        @php
            // Trục y: mốc tròn (1 / 2 / 2,5 / 5 × 10^n) ≥ giá trị lớn nhất; trục x: mỗi điểm một ngày (tháng / quý) hoặc một tháng (năm)
            $magnitude = 10 ** floor(log10(max(1, $trend['max'])));
            $niceMax = (int) (collect([1, 2, 2.5, 5, 10])->first(fn ($s) => $s * $magnitude >= $trend['max']) * $magnitude);
            $n = count($trend['points']);
            $x = fn (int $i) => $n > 1 ? round($i / ($n - 1) * 1000, 2) : 0;
            $y = fn (int $v) => round(200 - $v / max(1, $niceMax) * 200, 2);
            $line = function (int $col) use ($trend, $x, $y) {
                $d = '';
                foreach ($trend['points'] as $i => $p) {
                    if ($p[$col] !== null) {
                        $d .= ($d === '' ? 'M' : 'L').$x($i).' '.$y($p[$col]).' ';
                    }
                }

                return trim($d);
            };
            $lastCur = collect($trend['points'])->keys()->filter(fn ($i) => $trend['points'][$i][2] !== null)->last();
            $area = $lastCur !== null ? $line(2).' L'.$x($lastCur).' 200 L0 200 Z' : '';
            $short = fn (int $v) => match (true) {
                $v >= 1_000_000_000 => rtrim(rtrim(number_format($v / 1_000_000_000, 1, ',', '.'), '0'), ',').' tỷ',
                $v >= 1_000_000 => rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',').'tr',
                $v >= 1_000 => rtrim(rtrim(number_format($v / 1_000, 1, ',', '.'), '0'), ',').'k',
                default => (string) $v,
            };
        @endphp
        <div class="ant-card-body pb-2!" data-trend="{{ $period }}">
          <div class="flex flex-wrap items-center gap-x-5 gap-y-1 text-xs">
            <span class="font-medium {{ $strong }}">Doanh thu lũy kế</span>
            <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-5 rounded bg-[#1677ff]"></span>{{ $compare['current'] }}: <strong class="tabular-nums" data-trend-current>{{ $money($trend['current']) }}</strong></span>
            <span class="flex items-center gap-1.5"><span class="inline-block w-5 border-t-2 border-dashed border-[#8c8c8c]"></span>{{ $compare['previous'] }}: <strong class="tabular-nums" data-trend-previous>{{ $money($trend['previous']) }}</strong></span>
          </div>
          <div class="relative mt-3 h-56 pl-12 pb-5">
            @foreach ([1, 0.5, 0] as $f)
              <div class="pointer-events-none absolute right-0 left-12 border-t border-[#f0f0f0]" style="top: calc((100% - 1.25rem) * {{ 1 - $f }})"></div>
              <div class="pointer-events-none absolute left-0 w-10 -translate-y-1/2 text-right text-[11px] tabular-nums {{ $muted }}" style="top: calc((100% - 1.25rem) * {{ 1 - $f }})">{{ $short((int) ($niceMax * $f)) }}</div>
            @endforeach
            <div class="pointer-events-none absolute right-0 bottom-0 left-12 h-4">
              @foreach ($trend['points'] as $i => $p)
                @if ($p[0] !== '')
                  <span class="absolute -translate-x-1/2 text-[11px] whitespace-nowrap {{ $muted }}" style="left: {{ $x($i) / 10 }}%">{{ $p[0] }}</span>
                @endif
              @endforeach
            </div>
            <div class="absolute top-0 right-0 bottom-5 left-12">
              <svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 1000 200" preserveAspectRatio="none" role="img"
                   aria-label="Doanh thu lũy kế {{ $compare['current'] }} {{ $money($trend['current']) }}, {{ $compare['previous'] }} {{ $money($trend['previous']) }}">
                @if ($area)<path d="{{ $area }}" fill="#1677ff" fill-opacity="0.08" stroke="none" />@endif
                <path d="{{ $line(3) }}" fill="none" stroke="#8c8c8c" stroke-width="2" stroke-dasharray="6 5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                <path d="{{ $line(2) }}" fill="none" stroke="#1677ff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                {{-- Vùng rê chuột từng điểm: tooltip có sẵn của trình duyệt --}}
                @foreach ($trend['points'] as $i => $p)
                  <rect x="{{ max(0, $x($i) - 500 / max(1, $n - 1)) }}" y="0" width="{{ 1000 / max(1, $n - 1) }}" height="200" fill="transparent">
                    <title>{{ $p[1] }}&#10;{{ $compare['current'] }}: {{ $p[2] === null ? '—' : $money($p[2]) }}&#10;{{ $compare['previous'] }}: {{ $p[3] === null ? '—' : $money($p[3]) }}</title>
                  </rect>
                @endforeach
              </svg>
              @if ($lastCur !== null)
                <span class="pointer-events-none absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#1677ff] ring-2 ring-white" style="left: {{ $x($lastCur) / 10 }}%; top: {{ $y($trend['points'][$lastCur][2]) / 2 }}%"></span>
              @endif
            </div>
          </div>
        </div>
      @endif
      <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
        <table style="table-layout: auto" data-compare="{{ $period }}">
          <thead class="ant-table-thead">
            <tr>
              <th class="ant-table-cell min-w-[170px]">Chỉ số</th>
              <th class="ant-table-cell" style="text-align: right">{{ $compare['current'] }}<div class="text-xs font-normal {{ $muted }}">{{ $compare['current_range'] }}</div></th>
              <th class="ant-table-cell" style="text-align: right">Cùng kỳ {{ mb_strtolower($compare['previous']) }}<div class="text-xs font-normal {{ $muted }}">{{ $compare['previous_range'] }}</div></th>
              <th class="ant-table-cell max-sm:hidden" style="width: 22%">So sánh</th>
              <th class="ant-table-cell" style="text-align: right">Thay đổi</th>
              <th class="ant-table-cell max-md:hidden" style="text-align: right">Cả {{ mb_strtolower($compare['previous']) }}</th>
            </tr>
          </thead>
          <tbody class="ant-table-tbody">
            @foreach ($compare['rows'] as [$label, $current, $previous, $change, $whole, $type])
              @php $show = fn ($v) => $type === 'money' ? $money($v) : $count($v); @endphp
              <tr class="ant-table-row" data-metric="{{ $label }}">
                <td class="ant-table-cell">{{ $label }}</td>
                <td class="ant-table-cell tabular-nums whitespace-nowrap font-medium" style="text-align: right">{{ $show($current) }}</td>
                <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right">{{ $show($previous) }}</td>
                @php $scale = max(1, $current, $previous); @endphp
                <td class="ant-table-cell max-sm:hidden" data-bars title="{{ $compare['current'] }}: {{ $show($current) }} · Cùng kỳ {{ mb_strtolower($compare['previous']) }}: {{ $show($previous) }}">
                  <div class="h-2 rounded-r bg-[#1677ff]" style="width: {{ round($current / $scale * 100) }}%; min-width: {{ $current > 0 ? '2px' : '0' }}"></div>
                  <div class="mt-1 h-2 rounded-r bg-[#d9d9d9]" style="width: {{ round($previous / $scale * 100) }}%; min-width: {{ $previous > 0 ? '2px' : '0' }}"></div>
                </td>
                <td class="ant-table-cell tabular-nums whitespace-nowrap" style="text-align: right" data-change>
                  @if ($change === null)
                    <span class="{{ $muted }}">{{ $current > 0 ? 'Mới' : '—' }}</span>
                  @else
                    <span @class(['font-medium', 'text-[#389e0d]' => $change > 0, 'text-[#cf1322]' => $change < 0, $muted => $change == 0])>{{ $change > 0 ? '▲ +' : ($change < 0 ? '▼ ' : '') }}{{ str_replace('.', ',', (string) $change) }}%</span>
                  @endif
                </td>
                <td class="ant-table-cell tabular-nums whitespace-nowrap max-md:hidden {{ $muted }}" style="text-align: right">{{ $show($whole) }}</td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div></div></div>
      <div class="ant-card-body pt-2! text-xs {{ $muted }}">"Cùng kỳ" = từ đầu kỳ trước tới đúng thời điểm tương ứng hôm nay, để so công bằng khi kỳ này chưa hết. Cột So sánh: thanh xanh = kỳ này, thanh xám = cùng kỳ trước. Biểu đồ: rê chuột lên đường để xem số từng ngày. Doanh thu = tiền hàng đơn đã thanh toán (theo lúc xác nhận thanh toán).</div>
    </div>
  @endif

  @include('backend.dashboard._extended')

  {{-- Thẻ số liệu --}}
  @if ($cards)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 {{ [1 => 'xl:grid-cols-1', 2 => 'xl:grid-cols-2', 3 => 'xl:grid-cols-3', 4 => 'xl:grid-cols-4', 5 => 'xl:grid-cols-5'][min(5, count($cards))] }}" data-section="cards">
      @foreach ($cards as $key => [$label, $main, $icon, $url, $details])
        <a href="{{ $url }}" class="ant-card ant-card-bordered block hover:bg-[#fafafa]" data-card="{{ $key }}">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">{{ $label }}</div>
              <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f7ff] text-base text-[#007bff]">{{ ant_icon($icon) }}</span>
            </div>
            <div class="mt-2 text-2xl font-medium tabular-nums {{ $strong }}" data-main>{{ $count($main) }}</div>
            <dl class="m-0 mt-3 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 border-t border-[#f0f0f0] pt-3 text-xs tabular-nums">
              @foreach ($details as [$name, $number])
                <dt class="{{ $muted }}">{{ $name }}</dt><dd class="m-0 text-right font-medium {{ $strong }}">{{ $value($number) }}</dd>
              @endforeach
            </dl>
          </div>
        </a>
      @endforeach
    </div>
  @endif

  {{-- Đơn theo trạng thái + thành viên theo cấp bậc --}}
  @if ($orderStatuses || $levels)
    <div @class(['grid grid-cols-1 gap-4', 'lg:grid-cols-2' => $orderStatuses && $levels])>
      @if ($orderStatuses)
        @php $orderMax = max(1, max(array_column($orderStatuses, 'count'))); @endphp
        <div class="ant-card ant-card-bordered" data-section="order-statuses">
          <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Đơn hàng theo trạng thái</div></div></div>
          <div class="ant-card-body flex flex-col gap-3">
            @foreach ($orderStatuses as $status => $row)
              <a href="{{ $row['url'] }}" class="block text-[rgba(0,0,0,0.85)] hover:text-[#1677ff]" data-status="{{ $status }}">
                <div class="flex justify-between gap-3 text-sm tabular-nums"><span>{{ $row['label'] }}</span><span class="font-medium">{{ $count($row['count']) }}</span></div>
                <div class="mt-1 h-2 overflow-hidden rounded bg-[#f0f0f0]"><div class="h-full rounded bg-[#1677ff]" style="width: {{ round($row['count'] / $orderMax * 100) }}%"></div></div>
              </a>
            @endforeach
          </div>
        </div>
      @endif
      @if ($levels)
        <div class="ant-card ant-card-bordered" data-section="levels">
          <div class="ant-card-head"><div class="ant-card-head-wrapper">
            <div class="ant-card-head-title">Thành viên theo cấp bậc</div>
            <div class="ant-card-extra text-xs {{ $muted }}">Cấp hiện tại · % trên tổng thành viên</div>
          </div></div>
          <div class="ant-card-body flex flex-col gap-3">
            @foreach ($levels as $level)
              <div data-level="{{ $level['name'] }}" data-count="{{ $level['count'] }}">
                <div class="flex justify-between gap-3 text-sm tabular-nums">
                  <span>{{ $level['name'] }} <span class="text-xs {{ $muted }}">{{ $level['threshold'] > 1 ? '· từ '.$money($level['threshold']) : '' }}{{ $level['active'] ? '' : ' · đang tắt' }}</span></span>
                  <span><span class="font-medium">{{ $count($level['count']) }}</span> <span class="text-xs {{ $muted }}">({{ str_replace('.', ',', (string) $level['percent']) }}%)</span></span>
                </div>
                <div class="mt-1 h-2 overflow-hidden rounded bg-[#f0f0f0]"><div class="h-full rounded bg-[#52c41a]" style="width: {{ $level['percent'] }}%"></div></div>
              </div>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  @endif

  {{-- Thành viên chưa kích hoạt sau 30 ngày --}}
  @if ($inactive)
    <div class="ant-card ant-card-bordered" data-section="inactive">
      <div class="ant-card-head"><div class="ant-card-head-wrapper">
        <div class="ant-card-head-title">Chưa kích hoạt sau {{ \App\Services\DashboardMetrics::INACTIVE_DAYS }} ngày <span class="ml-1 ant-tag ant-tag-gold m-0" data-inactive-count>{{ $count($inactive['count']) }}</span></div>
        <div class="ant-card-extra text-xs {{ $muted }}">Đăng ký lâu nhất trước · nên liên hệ chăm sóc</div>
      </div></div>
      @if ($inactive['list']->isEmpty())
        <div class="ant-card-body {{ $muted }}">Không có thành viên nào.</div>
      @else
        <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
          <table style="table-layout: auto">
            <thead class="ant-table-thead"><tr>
              <th class="ant-table-cell min-w-[150px]">Thành viên</th><th class="ant-table-cell">Đăng ký</th><th class="ant-table-cell">Người giới thiệu</th>
            </tr></thead>
            <tbody class="ant-table-tbody">
              @foreach ($inactive['list'] as $m)
                <tr class="ant-table-row" data-inactive-member="{{ $m->id }}">
                  <td class="ant-table-cell"><a href="{{ route('backend.members.show', $m) }}">{{ $m->full_name }}</a><div class="text-xs {{ $muted }} tabular-nums">{{ $m->phone }} · <span class="font-mono">{{ $m->referral_code }}</span></div></td>
                  <td class="ant-table-cell tabular-nums whitespace-nowrap">{{ $m->created_at?->format('d/m/Y') }} <span class="text-xs {{ $muted }}">({{ (int) $m->created_at?->diffInDays(now()) }} ngày)</span></td>
                  <td class="ant-table-cell">{{ $m->referrer?->full_name ?? '—' }}@if ($m->referrer) <span class="text-xs font-mono {{ $muted }}">{{ $m->referrer->referral_code }}</span>@endif</td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div></div></div>
      @endif
    </div>
  @endif

  @if ($stats)
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
      @foreach ($stats as $key => [$label, $value, $icon, $url])
        <a href="{{ $url }}" class="ant-card ant-card-bordered block hover:bg-[#fafafa]" data-stat="{{ $key }}">
          <div class="ant-card-body">
            <div class="flex items-center justify-between gap-3">
              <div class="ant-statistic-title mb-0">{{ $label }}</div>
              <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#e6f7ff] text-base text-[#007bff]">{{ ant_icon($icon) }}</span>
            </div>
            <div class="mt-2 text-2xl font-medium tabular-nums {{ $strong }}">{{ $count($value) }}</div>
          </div>
        </a>
      @endforeach
    </div>
  @endif

  @if ($recentLogs)
    <div class="ant-card ant-card-bordered" data-card="audit">
      <div class="ant-card-head">
        <div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">Hoạt động gần đây</div>
          <div class="ant-card-extra"><a href="{{ route('backend.audit-logs.index') }}">Xem nhật ký</a></div>
        </div>
      </div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content overflow-x-auto">
            <table style="table-layout: auto">
              <tbody class="ant-table-tbody">
                @forelse ($recentLogs as $log)
                  <tr class="ant-table-row">
                    <td class="ant-table-cell">
                      <span class="font-medium {{ $strong }}">{{ $log->actorName() }}</span>
                      <span class="{{ $muted }}">· {{ $log->actionLabel() }}@if ($log->subjectLabel()) {{ mb_strtolower($log->subjectLabel()) }}@if ($log->auditable_id) #{{ $log->auditable_id }}@endif @endif</span>
                    </td>
                    <td class="ant-table-cell whitespace-nowrap text-xs tabular-nums {{ $muted }}" style="text-align: right" title="{{ $log->created_at?->format('d/m/Y H:i:s') }}">{{ $log->created_at?->diffForHumans() }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder"><td colspan="2" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Chưa có hoạt động nào.</p></div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  @endif

  @if ($nothing)
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-body">
        <div class="ant-empty"><p class="ant-empty-description">Chào mừng bạn đến với {{ config('app.name') }}. Tài khoản của bạn chưa được cấp quyền xem mục nào — liên hệ quản trị viên nếu cần.</p></div>
      </div>
    </div>
  @endif
</div>
@endsection
