@extends('layouts.backend')

@section('title', 'Báo cáo doanh thu')

{{-- Theo template ProCMS revenue.html. Số liệu tính ở ReportController; chữ, thẻ, bảng render ở đây, biểu đồ vẽ bằng
     resources/admin/js/revenue.js (charts.js của template) từ khối JSON #revenue-data cuối trang. --}}
@php
    use App\Http\Controllers\Backend\ReportController;
    use App\Support\Money;

    // Màu như template (src/js/revenue.js)
    $NEW = '#007bff';
    $RENEWAL = '#13c2c2';
    $PROJECT_VALUE = '#faad14';
    $muted = 'text-[rgba(0,0,0,0.45)]';
    $strong = 'text-[rgba(0,0,0,0.85)]';

    // Số gọn: 12,5tr / 1,2 tỷ / 500k
    $short = function (float $n): string {
        if ($n >= 1_000_000_000) return rtrim(rtrim(number_format($n / 1_000_000_000, 1, ',', '.'), '0'), ',').' tỷ';
        if ($n >= 1_000_000) return rtrim(rtrim(number_format($n / 1_000_000, 1, ',', '.'), '0'), ',').'tr';
        if ($n >= 1_000) return round($n / 1_000).'k';
        return Money::format($n);
    };
    $pct = fn (?float $g) => ($g > 0 ? '+' : '').number_format((float) $g, 1, ',', '.').'%';
    // % so với kỳ trước; null = kỳ trước bằng 0 nên không so được
    $growthOf = fn (float $cur, float $prev): ?float => $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : ($cur > 0 ? null : 0.0);
    // Tag xanh (tăng) / đỏ (giảm), kỳ trước bằng 0 → "mới" (template deltaTag)
    $tag = function (?float $g) use ($pct): string {
        if ($g === null) return '<span class="ant-tag m-0">mới</span>';
        if ($g == 0) return '<span class="ant-tag m-0">0%</span>';

        return '<span class="ant-tag m-0 '.($g > 0 ? 'ant-tag-green' : 'ant-tag-red').'">'.$pct($g).'</span>';
    };
    // Mũi tên + % + chênh lệch (template deltaLine)
    $deltaLine = function (float $cur, float $prev, ?float $g, string $suffix = 'so với kỳ trước') use ($short, $pct, $muted): string {
        if ($g === null) return '<span class="text-xs '.$muted.'">Kỳ trước chưa có số liệu</span>';
        if ($cur == $prev) return '<span class="text-xs '.$muted.'">Không đổi'.($suffix !== '' ? ' '.$suffix : '').'</span>';
        $up = $cur > $prev;

        return '<span class="text-xs '.($up ? 'text-[#52c41a]' : 'text-[#f5222d]').'">'.ant_icon($up ? 'arrow-up' : 'arrow-down').' '.$pct($g).'</span>'
            .' <span class="text-xs '.$muted.'">('.($up ? '+' : '−').$short(abs($cur - $prev)).')'.($suffix !== '' ? ' '.$suffix : '').'</span>';
    };
    // Chênh lệch số dự án: ▲ +2 / ▼ -1 (template countDelta)
    $countDelta = fn (int $c, int $p): string => $c === $p ? ''
        : '<span class="text-xs font-normal '.($c > $p ? 'text-[#52c41a]' : 'text-[#f5222d]').'">'.ant_icon($c > $p ? 'caret-up' : 'caret-down-filled').' '.($c > $p ? '+' : '').($c - $p).'</span>';
    // Thanh ngang mảnh: [[giá trị, màu], …] trên tổng $max (template meter)
    $meter = function (array $parts, float $max, string $class = ''): string {
        $html = '';
        foreach ($parts as [$value, $color]) {
            if ($value > 0) $html .= '<span style="width: '.min(100, round($value / ($max ?: 1) * 100, 2)).'%; background: '.$color.'"></span>';
        }

        return '<span class="chart-meter'.($class !== '' ? ' '.$class : '').'">'.$html.'</span>';
    };

    $periodName = ReportController::PERIODS[$period];
    $range = $from->format('d/m/Y').' – '.$to->format('d/m/Y');
    $prevRange = $prevFrom->format('d/m/Y').' – '.$prevTo->format('d/m/Y');
    $canCustomer = auth()->user()->can('customer');
    $canProject = auth()->user()->can('project');

    // Trung bình các kỳ trước kỳ đang xem; 3 kỳ doanh thu cao nhất (> 0) tô cam, bằng nhau thì kỳ trước đứng trước
    $trendAvg = count($trend) > 1 ? array_sum(array_column(array_slice($trend, 0, -1), 'revenue')) / (count($trend) - 1) : 0;
    $trendTop = collect($trend)->map(fn ($r, $i) => ['i' => $i, 'v' => (float) $r['revenue']])->filter(fn ($r) => $r['v'] > 0)
        ->sortBy([['v', 'desc'], ['i', 'asc']])->take(3)->pluck('i')->all();

    $mixTotal = $stats['revenue_new'] + $stats['revenue_renewal'];
    $renewalShare = $mixTotal > 0 ? (int) round($stats['revenue_renewal'] / $mixTotal * 100) : 0;
    $shares = ['revenue_new' => $mixTotal > 0 ? 100 - $renewalShare : 0, 'revenue_renewal' => $renewalShare];
    $avgValue = $stats['new_count'] ? $stats['new_value'] / $stats['new_count'] : null;
    $avgValuePrev = $stats['new_count_prev'] ? $stats['new_value_prev'] / $stats['new_count_prev'] : null;

    $keepPeriod = array_filter(['period' => $period === 'this_month' ? null : $period, 'from' => $period === 'custom' ? $from->toDateString() : null, 'to' => $period === 'custom' ? $to->toDateString() : null]);
    $yearTotal = array_sum(array_column($monthly, 'current'));
    $prevYearTotal = array_sum(array_column($monthly, 'previous'));
    $lastMonth = $year === $today->year ? $today->month : ($year > $today->year ? 0 : 12);

    $collected = $forecast['actual'];
    $fullYear = $forecast['total'];

    // Dữ liệu cho biểu đồ (resources/admin/js/revenue.js)
    $chart = [
        'trend' => array_map(fn ($row, $i) => [
            'label' => $row['label'],
            'range' => $row['from']->format('d/m/Y').' – '.$row['to']->format('d/m/Y'),
            'total' => round((float) $row['revenue'], 2),
            'new' => round((float) $row['new'], 2),
            'renewal' => round((float) $row['renewal'], 2),
            'running' => (bool) $row['ongoing'],
            'top' => in_array($i, $trendTop, true) ? array_search($i, $trendTop, true) + 1 : null,
        ], $trend, array_keys($trend)),
        'average' => round($trendAvg),
        'mix' => ['new' => round((float) $stats['revenue_new'], 2), 'renewal' => round((float) $stats['revenue_renewal'], 2)],
        // Tổng tiền cộng bằng số thực có thể lệch kiểu 75599999.99000001: làm tròn 2 chữ số cho dữ liệu biểu đồ
        'projectValues' => array_map(fn ($v) => round((float) $v, 2), array_column($trend, 'new_value')),
        'year' => $year,
        'months' => [
            'current' => array_map(fn ($m) => $m <= $lastMonth ? round((float) $monthly[$m]['current'], 2) : null, range(1, 12)),
            'previous' => array_map(fn ($m) => round((float) $monthly[$m]['previous'], 2), range(1, 12)),
            // Thu mới / gia hạn từng tháng của năm đang xem (tooltip)
            'new' => array_map(fn ($m) => $m <= $lastMonth ? round((float) $monthly[$m]['new'], 2) : null, range(1, 12)),
            'renewal' => array_map(fn ($m) => $m <= $lastMonth ? round((float) $monthly[$m]['renewal'], 2) : null, range(1, 12)),
            'active' => $year === $today->year ? $today->month - 1 : -1,
        ],
        'renewals' => [
            'collected' => array_map(fn ($m) => round((float) $forecast['months'][$m]['paid'], 2), range(1, 12)),
            'expected' => array_map(fn ($m) => round((float) $forecast['months'][$m]['expected'], 2), range(1, 12)),
            'today' => $today->month - 1,
        ],
    ];
@endphp

@section('topbar-sub'){{ $periodName }}: {{ $range }} · so với {{ $prevRange }}@endsection

@section('content')
<div id="revenue" class="mx-auto flex w-full max-w-[1200px] flex-col gap-4">
  {{-- Kỳ báo cáo --}}
  <div class="flex flex-wrap items-center gap-3">
    <div class="ant-radio-group ant-radio-group-outline ant-radio-group-small flex flex-wrap" role="group" aria-label="Kỳ báo cáo">
      @foreach (ReportController::PERIODS as $key => $label)
        <a href="{{ route('backend.reports.revenue', $key === 'this_month' ? [] : ['period' => $key]) }}" @class(['ant-radio-button-wrapper', 'ant-radio-button-wrapper-checked' => $period === $key]) @if ($period === $key) aria-current="page" @endif><span>{{ $label }}</span></a>
      @endforeach
    </div>
    @if ($period === 'custom')
      <div class="ant-picker ant-picker-range ant-picker-small" id="custom-range" data-picker="range" data-url="{{ route('backend.reports.revenue') }}">
        <div class="ant-picker-input ant-picker-input-active"><input placeholder="Từ ngày" readonly aria-label="Từ ngày" value="{{ $from->toDateString() }}" /></div>
        <div class="ant-picker-range-separator"><span class="ant-picker-separator">{{ ant_icon('swap-right') }}</span></div>
        <div class="ant-picker-input"><input placeholder="Đến ngày" readonly aria-label="Đến ngày" value="{{ $to->toDateString() }}" /></div>
        <div class="ant-picker-active-bar" style="left: 0px; width: 96px; position: absolute"></div>
        <span class="ant-picker-suffix">{{ ant_icon('calendar') }}</span>
      </div>
    @endif
  </div>

  {{-- Doanh thu kỳ đang xem + các kỳ gần nhất | cơ cấu doanh thu, giá trị dự án mới --}}
  <div class="grid grid-cols-1 items-stretch gap-4 lg:grid-cols-3">
    <div class="ant-card ant-card-bordered lg:col-span-2">
      <div class="ant-card-body">
        <div class="flex flex-wrap justify-between gap-4">
          <div>
            <div class="{{ $muted }}">Doanh thu · {{ mb_strtolower($periodName) }}</div>
            <div class="text-3xl font-semibold {{ $strong }} tabular-nums" data-stat="revenue">{{ Money::format($stats['revenue']) }}</div>
            <div>{!! $deltaLine($stats['revenue'], $stats['revenue_prev'], $stats['revenue_growth']) !!}</div>
          </div>
          <dl class="m-0 grid w-full grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-xs sm:w-auto sm:min-w-[260px]">
            <dt class="{{ $muted }}">Kỳ trước</dt><dd class="m-0 text-right font-medium tabular-nums">{{ Money::format($stats['revenue_prev']) }}</dd>
            <dt class="{{ $muted }}">Cùng kỳ năm trước</dt><dd class="m-0 text-right font-medium tabular-nums">{{ Money::format($stats['revenue_yoy']) }} {!! $tag($stats['revenue_yoy_growth']) !!}</dd>
            <dt class="{{ $muted }}">Trung bình {{ count($trend) - 1 }} kỳ trước</dt><dd class="m-0 text-right font-medium tabular-nums">{{ Money::format(round($trendAvg)) }}</dd>
            <dt class="{{ $muted }}">Số khoản thu</dt><dd class="m-0 text-right font-medium tabular-nums">{{ $stats['payment_count'] }} <span class="font-normal {{ $muted }}">(kỳ trước {{ $stats['payment_count_prev'] }})</span></dd>
          </dl>
        </div>
        <div class="mt-4" id="revenue-bars"></div>
        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-[rgba(0,0,0,0.65)]">
          <span><span class="chart-swatch bg-[#007bff]"></span> Thu mới</span>
          <span><span class="chart-swatch bg-[#13c2c2]"></span> Gia hạn</span>
          @if ($trendTop)<span><span class="chart-swatch bg-[#fa8c16]"></span> {{ count($trendTop) }} kỳ doanh thu cao nhất</span>@endif
          <span><span class="chart-swatch-line"></span> Trung bình các kỳ trước</span>
          <span><span class="chart-swatch chart-swatch-hatch"></span> Kỳ chưa kết thúc</span>
          <span class="{{ $muted }}">{{ count($trend) }} kỳ gần nhất · rê chuột lên cột để xem chi tiết</span>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-4">
      <div class="ant-card ant-card-bordered flex-1">
        {{-- Thẻ hẹp (cột phải ở màn 992–1250px): donut nằm trên, số liệu bên dưới thay vì bị cắt / tràn --}}
        <div class="ant-card-body @container">
          <div class="mb-3 text-xs {{ $muted }}">Cơ cấu doanh thu · {{ mb_strtolower($periodName) }}</div>
          <div class="flex flex-col gap-4 @min-[16rem]:flex-row @min-[16rem]:items-center">
            <div class="w-[120px] shrink-0 self-center" id="mix-donut"></div>
            <div class="flex min-w-0 flex-1 flex-col gap-3">
              {{-- Thu mới gồm cả phát sinh đã thu (tiền ngoài gia hạn) --}}
              @foreach ([['Thu mới', 'revenue_new', $NEW], ['Gia hạn', 'revenue_renewal', $RENEWAL]] as [$label, $key, $color])
                <div class="min-w-0">
                  <div class="flex items-center justify-between gap-2 text-xs {{ $muted }}"><span @if ($key === 'revenue_new') title="Gồm cả tiền phát sinh đã thu" @endif><span class="chart-swatch" style="background: {{ $color }}"></span> {{ $label }}</span><span class="font-medium {{ $strong }}">{{ $shares[$key] }}%</span></div>
                  <div class="font-semibold {{ $strong }} tabular-nums">{{ Money::format($stats[$key]) }}</div>
                  <div class="truncate">{!! $deltaLine($stats[$key], $stats[$key.'_prev'], $stats[$key.'_growth'], '') !!}</div>
                </div>
              @endforeach
            </div>
          </div>
        </div>
      </div>
      <div class="ant-card ant-card-bordered">
        {{-- Thẻ hẹp: sparkline xuống dưới số cho khỏi đè lên số --}}
        <div class="ant-card-body @container">
          <div class="flex flex-col gap-2 @min-[15rem]:flex-row @min-[15rem]:items-start @min-[15rem]:justify-between @min-[15rem]:gap-3">
            {{-- Hàng ngang: cột chữ co tới đúng bề rộng con số (min-width auto), dòng dưới tự xuống dòng --}}
            <div class="min-w-0 @min-[15rem]:min-w-[auto] @min-[15rem]:flex-1">
              <div class="text-xs {{ $muted }}"><span class="chart-swatch" style="background: {{ $PROJECT_VALUE }}"></span> Giá trị dự án mới</div>
              <div class="ant-statistic-content"><span class="ant-statistic-content-value"><span class="ant-statistic-content-value-int">{{ Money::format($stats['new_value']) }}</span></span></div>
              <div>{!! $deltaLine($stats['new_value'], $stats['new_value_prev'], $stats['new_value_growth'], '') !!}</div>
              <div class="text-xs {{ $muted }}">{{ $stats['new_count'] }} dự án bắt đầu · kỳ trước {{ Money::format($stats['new_value_prev']) }}</div>
            </div>
            <div class="h-12 w-full @min-[15rem]:w-28 @min-[15rem]:min-w-12" id="project-value-spark"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Chỉ số --}}
  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
    @foreach ([
        ['Dự án mới', $stats['new_count'].' dự án', $countDelta($stats['new_count'], $stats['new_count_prev']), 'Kỳ trước '.$stats['new_count_prev']],
        ['Bàn giao', $stats['delivered_count'].' dự án', $countDelta($stats['delivered_count'], $stats['delivered_count_prev']), 'Kỳ trước '.$stats['delivered_count_prev']],
        ['Giá trị trung bình / dự án', $avgValue !== null ? $short($avgValue) : '—', $avgValue !== null && $avgValuePrev !== null ? $tag($growthOf($avgValue, $avgValuePrev)) : '', 'Kỳ trước '.($avgValuePrev !== null ? Money::format(round($avgValuePrev)) : '—')],
    ] as [$label, $value, $delta, $foot])
      <div class="ant-card ant-card-bordered">
        <div class="ant-card-body">
          <div class="ant-statistic-title mb-0">{{ $label }}</div>
          <div class="ant-statistic-content flex items-center gap-2"><span class="ant-statistic-content-value">{{ $value }}</span>{!! $delta !!}</div>
          <div class="text-xs {{ $muted }}">{{ $foot }}</div>
        </div>
      </div>
    @endforeach
    <div class="ant-card ant-card-bordered bg-[#fff1f0]">
      <div class="ant-card-body">
        <div class="ant-statistic-title mb-0">Công nợ hiện tại</div>
        <div class="ant-statistic-content flex items-center gap-2"><span class="ant-statistic-content-value"><span class="text-[#f5222d]" data-stat="debt">{{ Money::format($debtTotal) }}</span></span></div>
        <div class="text-xs {{ $muted }}" title="Nợ hợp đồng + phát sinh chưa thu">@if ($canProject)<a href="{{ route('backend.projects.index', ['debt' => 1]) }}">{{ $debtCount }} dự án còn công nợ</a>@else{{ $debtCount }} dự án còn công nợ@endif<span> (không tính dự án đã hủy)</span></div>
      </div>
    </div>
  </div>

  {{-- Tuần / tháng / năm hiện tại so với kỳ trước --}}
  <div class="ant-card ant-card-bordered">
    <div class="grid grid-cols-1 divide-y divide-[rgba(0,0,0,0.06)] md:grid-cols-3 md:divide-x md:divide-y-0">
      @foreach ($quick as $label => $row)
        @php $quickMax = max($row['current'], $row['previous']); @endphp
        <div class="flex items-center justify-between gap-3 px-4 py-3">
          <div class="ant-statistic min-w-0 flex-1">
            <div class="ant-statistic-title mb-0">{{ $label }}</div>
            <div class="ant-statistic-content"><span class="ant-statistic-content-value"><span class="ant-statistic-content-value-int">{{ Money::format($row['current']) }}</span></span></div>
            <div class="mt-1 flex flex-col gap-1">{!! $meter([[$row['current'], $NEW]], $quickMax) !!}{!! $meter([[$row['previous'], '#d9d9d9']], $quickMax) !!}</div>
            <div class="mt-1 text-xs {{ $muted }}">Kỳ trước: {{ Money::format($row['previous']) }}</div>
          </div>
          {!! $tag($row['growth']) !!}
        </div>
      @endforeach
    </div>
  </div>

  {{-- Doanh thu theo tháng: năm đang xem so với năm trước --}}
  <div class="ant-card ant-card-bordered">
    <div class="ant-card-body">
      <div class="flex items-start justify-between gap-3">
        <div>
          <div class="text-base font-semibold {{ $strong }}">Doanh thu theo tháng năm {{ $year }}</div>
          <div class="{{ $muted }}">Tổng <b class="{{ $strong }}">{{ Money::format($yearTotal) }}</b> · năm {{ $year - 1 }}: {{ Money::format($prevYearTotal) }} {!! $tag($growthOf($yearTotal, $prevYearTotal)) !!}</div>
        </div>
        <div class="flex gap-1">
          @if ($year > $firstYear) {{-- lùi tới năm có khoản thu đầu tiên --}}
            <a href="{{ route('backend.reports.revenue', [...$keepPeriod, 'year' => $year - 1]) }}" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm trước" aria-label="Năm trước">{{ ant_icon('left') }}</a>
          @else
            <button type="button" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm trước" aria-label="Năm trước" disabled>{{ ant_icon('left') }}</button>
          @endif
          @if ($year < $today->year)
            <a href="{{ route('backend.reports.revenue', [...$keepPeriod, 'year' => $year + 1]) }}" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm sau" aria-label="Năm sau">{{ ant_icon('right') }}</a>
          @else
            <button type="button" class="ant-btn ant-btn-sm ant-btn-icon-only" title="Năm sau" aria-label="Năm sau" disabled>{{ ant_icon('right') }}</button>
          @endif
        </div>
      </div>
      <div class="mt-2 flex gap-4 text-xs text-[rgba(0,0,0,0.65)]">
        <span><span class="chart-swatch-line" style="border-top-style: solid; border-color: {{ $NEW }}"></span> Năm {{ $year }}</span>
        <span><span class="chart-swatch-line" style="border-color: #8c8c8c"></span> Năm {{ $year - 1 }}</span>
      </div>
      <div class="mt-3" id="year-chart"></div>
    </div>
  </div>

  {{-- Dòng tiền gia hạn năm nay --}}
  <div class="ant-card ant-card-bordered">
    <div class="ant-card-body">
      <div class="text-base font-semibold {{ $strong }}">Dòng tiền gia hạn năm {{ $today->year }}</div>
      <div class="{{ $muted }}">Đã thu: tiền gia hạn thực thu đến hôm nay. Dự kiến: phí gia hạn các dự án đến kỳ từ hôm nay tới cuối năm mà chưa gia hạn (bỏ dự án đã hủy gia hạn hoặc đã đóng, dự án dùng hosting của khách).</div>
      <div class="mt-4">
        <div class="flex flex-wrap items-end justify-between gap-2">
          <div><span class="text-2xl font-semibold text-[#389e0d] tabular-nums">{{ Money::format($collected) }}</span> <span class="{{ $muted }}">đã thu / {{ Money::format($fullYear) }} cả năm</span></div>
          <div class="font-semibold {{ $strong }}">{{ $fullYear > 0 ? (int) round($collected / $fullYear * 100) : 0 }}%</div>
        </div>
        {!! $meter([[$collected, '#52c41a'], [$forecast['upcoming'], '#d9f7be']], $fullYear, 'mt-2 !h-2.5') !!}
        <div class="mt-1 text-xs {{ $muted }}">Còn phải thu: <b class="{{ $strong }}">{{ Money::format($forecast['upcoming']) }}</b></div>
      </div>
      <div class="mt-4 flex flex-wrap gap-4 text-xs text-[rgba(0,0,0,0.65)]">
        <span><span class="chart-swatch bg-[#52c41a]"></span> Đã thu</span>
        <span><span class="chart-swatch chart-swatch-forecast"></span> Dự kiến</span>
      </div>
      <div class="mt-2" id="renewal-chart"></div>
    </div>
  </div>

  {{-- Bảng --}}
  <div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
    @php $topMax = (float) ($topCustomers->first()['total'] ?? 1); @endphp
    <div class="ant-card ant-card-bordered">
      <div class="ant-card-head">
        <div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">
            Khách hàng trả nhiều nhất trong kỳ
            <div class="text-xs font-normal {{ $muted }}">{{ $range }}</div>
          </div>
        </div>
      </div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content overflow-x-auto">
            <table style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Khách hàng</th>
                  <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Dự án</th>
                  <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Đã thu</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody">
                @forelse ($topCustomers as $row)
                  @php
                      $customer = $row['customer'];
                      $sub = array_filter([$customer->company, $customer->referrer ? 'Giới thiệu bởi '.$customer->referrer->name : null]);
                  @endphp
                  <tr class="ant-table-row">
                    <td class="ant-table-cell">
                      @if ($canCustomer && ! $customer->trashed())
                        <a href="{{ route('backend.customers.edit', $customer) }}" class="font-medium">{{ $customer->name }}</a>
                      @else
                        <div class="font-medium {{ $strong }}">{{ $customer->name }}</div>
                      @endif
                      @if ($sub)<div class="text-xs {{ $muted }}">{{ implode(' · ', $sub) }}</div>@endif
                    </td>
                    <td class="ant-table-cell" style="text-align: right">{{ $row['projects'] }}</td>
                    <td class="ant-table-cell min-w-[120px] font-medium tabular-nums sm:min-w-[140px]" style="text-align: right">{{ Money::format($row['total']) }}
                      <div class="mt-1 flex items-center gap-2"><span class="flex-1">{!! $meter([[$row['total'], $NEW]], $topMax) !!}</span><span class="w-8 text-xs font-normal {{ $muted }}">{{ $stats['revenue'] > 0 ? (int) round($row['total'] / $stats['revenue'] * 100) : 0 }}%</span></div>
                    </td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder"><td colspan="3" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Không có khoản thu nào trong kỳ.</p></div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="ant-card ant-card-bordered">
      <div class="ant-card-head">
        <div class="ant-card-head-wrapper">
          <div class="ant-card-head-title">
            Dự án còn nợ nhiều nhất
            <div class="text-xs font-normal {{ $muted }}">Giá dự án trừ tiền thu mới đã thu, cộng phát sinh chưa thu{{ $debtCount > $debts->count() ? ' · '.$debts->count().'/'.$debtCount.' dự án' : '' }}</div>
          </div>
        </div>
      </div>
      <div class="ant-table ant-table-small">
        <div class="ant-table-container">
          <div class="ant-table-content overflow-x-auto">
            <table style="table-layout: auto">
              <thead class="ant-table-thead">
                <tr>
                  <th class="ant-table-cell">Dự án</th>
                  <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Giá</th>
                  <th class="ant-table-cell whitespace-nowrap" style="text-align: right">Còn nợ</th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody">
                @forelse ($debts as $row)
                  @php
                      $project = $row['project'];
                      $price = (float) $project->price;
                      $contractDebt = max(0.0, $row['remaining'] - $row['extra']);
                      $paid = max(0.0, $price - $contractDebt);
                  @endphp
                  <tr class="ant-table-row">
                    <td class="ant-table-cell">
                      @if ($canProject)
                        <a href="{{ route('backend.projects.show', $project) }}" class="font-medium">{{ $project->name }}</a>
                      @else
                        <div class="font-medium {{ $strong }}">{{ $project->name }}</div>
                      @endif
                      <div class="text-xs {{ $muted }}">{{ $project->customer?->name ?? 'Không có khách hàng' }}@if ($price > 0) · đã thu {{ (int) round($paid / $price * 100) }}%@endif</div>
                      @if ($price > 0 && $contractDebt > 0)
                        {!! $meter([[$paid, '#52c41a'], [$contractDebt, '#ffa39e']], $price, 'mt-1 max-w-[220px]') !!}
                      @endif
                      @if ($row['extra'] > 0)
                        <div class="text-xs text-[#fa541c]">gồm phát sinh chưa thu {{ Money::format($row['extra']) }}</div>
                      @endif
                    </td>
                    <td class="ant-table-cell tabular-nums" style="text-align: right">{{ Money::format($price) }}</td>
                    <td class="ant-table-cell font-medium tabular-nums text-[#f5222d]" style="text-align: right">{{ Money::format($row['remaining']) }}</td>
                  </tr>
                @empty
                  <tr class="ant-table-placeholder"><td colspan="3" class="ant-table-cell"><div class="ant-empty ant-empty-small"><p class="ant-empty-description">Không có dự án nào còn nợ.</p></div></td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  </div>

  {{-- Top 20 khách theo giá trị mang lại (toàn thời gian) --}}
  @php
      $ltvTabs = ['total' => 'Tổng', 'direct' => 'Trực tiếp', 'downline' => 'Giới thiệu'];
      $DIRECT = '#597ef7';
      $REFERRAL = '#b37feb';
  @endphp
  <div class="ant-card ant-card-bordered" data-ranking>
    <div class="ant-card-body">
      <div class="text-base font-semibold {{ $strong }}">Top 20 khách hàng theo giá trị mang lại</div>
      <div class="{{ $muted }}">Toàn thời gian. Giới thiệu = doanh thu từ các khách do họ giới thiệu (cả nhiều tầng).</div>
      <div class="ant-radio-group ant-radio-group-outline ant-radio-group-small mt-3" id="ltv-tabs" data-value="total" role="radiogroup" aria-label="Cách tính">
        @foreach ($ltvTabs as $key => $label)
          <label @class(['ant-radio-button-wrapper', 'ant-radio-button-wrapper-checked' => $key === 'total'])
            ><span @class(['ant-radio-button', 'ant-radio-button-checked' => $key === 'total'])
              ><input type="radio" class="ant-radio-button-input" value="{{ $key }}" @checked($key === 'total') /><span class="ant-radio-button-inner"></span></span
            ><span>{{ $label }}</span></label
          >
        @endforeach
      </div>
      @foreach ($ranking as $key => $rows)
        @php $rankMax = max(1.0, (float) ($rows->first()[$key] ?? 0)); @endphp
        <div data-ranking-panel="{{ $key }}" @class(['hidden' => $key !== 'total'])>
          <div class="mt-3 flex flex-wrap gap-4 text-xs text-[rgba(0,0,0,0.65)]">
            @if ($key !== 'downline')<span><span class="chart-swatch" style="background: {{ $DIRECT }}"></span> Trực tiếp (khách tự trả)</span>@endif
            @if ($key !== 'direct')<span><span class="chart-swatch" style="background: {{ $REFERRAL }}"></span> Giới thiệu (khách do họ đưa tới)</span>@endif
          </div>
          <ol class="m-0 mt-3 flex list-none flex-col gap-2 p-0">
            @forelse ($rows as $i => $row)
              @php
                  $parts = match ($key) {
                      'total' => [[$row['direct'], $DIRECT], [$row['downline'], $REFERRAL]],
                      'direct' => [[$row['direct'], $DIRECT]],
                      default => [[$row['downline'], $REFERRAL]],
                  };
              @endphp
              <li class="grid grid-cols-[24px_minmax(0,160px)_1fr_auto] items-center gap-3">
                <span class="text-xs {{ $muted }}">{{ $i + 1 }}</span>
                @if ($canCustomer)
                  <a href="{{ route('backend.customers.edit', $row['customer']) }}" class="truncate" title="{{ $row['customer']->name }}">{{ $row['customer']->name }}</a>
                @else
                  <span class="truncate {{ $strong }}" title="{{ $row['customer']->name }}">{{ $row['customer']->name }}</span>
                @endif
                {!! $meter($parts, $rankMax, '!h-2') !!}
                <span class="text-right font-medium tabular-nums" title="Trực tiếp {{ Money::format($row['direct']) }} · Giới thiệu {{ Money::format($row['downline']) }}">{{ Money::format($row[$key]) }}</span>
              </li>
            @empty
              <li class="{{ $muted }}">Chưa có dữ liệu.</li>
            @endforelse
          </ol>
        </div>
      @endforeach
    </div>
  </div>
</div>

<script type="application/json" id="revenue-data">{!! json_encode($chart, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) !!}</script>
@endsection
