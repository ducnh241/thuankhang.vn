@extends('layouts.backend')

{{-- Bảng đồng chia chi tiết của một tháng, để kế toán đối soát: trạng thái + nút Duyệt (chỉ ngày 1–5 tháng sau), tham số,
     tổng các nhóm; mỗi nhóm: cách tính quỹ từng bước, đơn vào gói thanh toán trong tháng (ai, lúc nào, bao nhiêu, vào nhóm lần
     đầu?), danh sách được chia (hạng, vào nhóm ngày nào, đã nhận trước, nhận tháng này, mức ra), hàng chờ, người bị khóa, người
     đã nhận đủ từ trước. Tháng chưa duyệt: tính trực tiếp từ dữ liệu hiện tại. Tháng đã duyệt: ảnh chụp lúc duyệt. --}}
@php
    use App\Http\Controllers\Backend\ProfitShareController;
    use App\Models\Commission;
    use App\Models\Order;
    use App\Support\Money;

    $muted = 'text-[rgba(0,0,0,0.45)]';
    $money = fn ($v) => Money::format((int) $v).' đ';
    $pct = fn ($v) => Commission::percentLabel($v);
    $label = ProfitShareController::label($period);
    [$from, $until] = $window;
    $tags = [
        'approved' => ['green', 'Đã duyệt'], 'open' => ['gold', 'Chờ duyệt'],
        'current' => ['blue', 'Đang diễn ra — tạm tính'], 'missed' => ['red', 'Quá hạn duyệt'],
    ];
    [$tagColor, $tagLabel] = $tags[$state];
    $canApprove = auth()->user()->can('profit_share/approve');
    $right = 'style="text-align: right"';
@endphp

@section('title', 'Đồng chia tháng '.$label)

@section('topbar')
  <div class="page-topbar-left">
    <a href="{{ route('backend.profit-share.index') }}" class="page-topbar-crumb" title="Về danh sách tháng">Đồng chia</a>
    <span class="page-topbar-sep" aria-hidden="true">/</span>
    <h1 class="page-topbar-title">Tháng {{ $label }}</h1>
    <span class="ant-tag ant-tag-{{ $tagColor }} m-0" id="profit-share-state">{{ $tagLabel }}</span>
  </div>
  <div class="page-topbar-right"></div>
@endsection

@section('content')
<div class="mx-auto flex max-w-[1300px] flex-col gap-4">
  {{-- Trạng thái + duyệt --}}
  <div class="ant-card ant-card-bordered" data-card="status">
    <div class="ant-card-body flex flex-col gap-3">
      @if ($state === 'approved')
        <div class="ant-alert ant-alert-success"><div class="ant-alert-content"><div class="ant-alert-message">
          Đã duyệt lúc <strong>{{ $record->approved_at->format('H:i d/m/Y') }}</strong> bởi <strong>{{ $record->approver?->name ?? '—' }}</strong>:
          đã cộng {{ $money($record->distributed_total) }} vào ví tái tiêu dùng của {{ $record->paid_count }} lượt người. Bảng dưới là số liệu lúc duyệt.
        </div></div></div>
      @elseif ($state === 'open')
        <div class="ant-alert ant-alert-warning"><div class="ant-alert-content"><div class="ant-alert-message">
          Chờ duyệt — hạn duyệt <strong>đến hết {{ $until->format('d/m/Y') }}</strong>. Số liệu tính trực tiếp từ đơn hàng hiện tại; đối soát xong bấm Duyệt để cộng ví.
        </div></div></div>
      @elseif ($state === 'current')
        <div class="ant-alert ant-alert-info"><div class="ant-alert-content"><div class="ant-alert-message">
          Tháng đang diễn ra — số liệu <strong>tạm tính</strong> tới thời điểm này, còn thay đổi khi có đơn mới. Duyệt được từ {{ $from->format('d/m/Y') }} đến hết {{ $until->format('d/m/Y') }}.
        </div></div></div>
      @else
        <div class="ant-alert ant-alert-error"><div class="ant-alert-content"><div class="ant-alert-message">
          Đã quá hạn duyệt (hết {{ $until->format('d/m/Y') }}) — tháng này không được cộng ví. Bảng dưới là số liệu tính lại từ dữ liệu hiện tại để tham khảo.
        </div></div></div>
      @endif

      <dl class="grid grid-cols-2 gap-x-6 gap-y-3 m-0 md:grid-cols-5" data-summary>
        <div><dt class="text-xs {{ $muted }}">Đơn vào gói trong tháng</dt><dd class="m-0 text-lg font-semibold tabular-nums">{{ $money($sheet['order_total']) }}</dd></div>
        <div><dt class="text-xs {{ $muted }}">Tổng quỹ đồng chia</dt><dd class="m-0 text-lg font-semibold tabular-nums" data-pool-total>{{ $money($sheet['pool_total']) }}</dd></div>
        <div><dt class="text-xs {{ $muted }}">{{ $state === 'approved' ? 'Đã chia' : 'Sẽ chia' }}</dt><dd class="m-0 text-lg font-semibold tabular-nums text-[#389e0d]" data-distributed-total>{{ $money($sheet['distributed_total']) }}</dd></div>
        <div><dt class="text-xs {{ $muted }}">Lượt người được chia</dt><dd class="m-0 text-lg font-semibold tabular-nums" data-paid-count>{{ $sheet['paid_count'] }}</dd></div>
        <div><dt class="text-xs {{ $muted }}">Công ty giữ (dư, lẻ)</dt><dd class="m-0 text-lg font-semibold tabular-nums">{{ $money($sheet['pool_total'] - $sheet['distributed_total']) }}</dd></div>
      </dl>
      <div class="text-xs {{ $muted }}">
        Tham số: phí doanh nghiệp {{ $pct($sheet['fee_percent']) }} · tỉ lệ đồng chia {{ $pct($sheet['share_percent']) }} · chia tối đa top {{ $sheet['slots'] }} người mỗi nhóm
        · mốc gói nhỏ nhất {{ $money($sheet['min_amount']) }}. Tháng tính theo thời điểm đơn được xác nhận thanh toán (00:00 ngày 1 đến hết ngày cuối tháng).
      </div>

      @if ($state === 'open' && $canApprove)
        <form method="POST" action="{{ route('backend.profit-share.approve', $period) }}" id="approve-form" class="flex flex-wrap items-center gap-3 border-t border-[#f0f0f0] pt-3"
          onsubmit="return confirm('Duyệt đồng chia tháng {{ $label }}: cộng {{ $money($sheet['distributed_total']) }} vào ví tái tiêu dùng của {{ $sheet['paid_count'] }} lượt người. Không hoàn tác được. Tiếp tục?')">
          @csrf
          <input type="hidden" name="fingerprint" value="{{ $fingerprint }}" />
          <button type="submit" class="ant-btn ant-btn-primary">{{ ant_icon('check-circle') }}<span>Duyệt và cộng ví</span></button>
          <span class="text-xs {{ $muted }}">Không hoàn tác được. Nếu số liệu thay đổi sau khi mở trang (có đơn mới được xác nhận…), hệ thống từ chối và yêu cầu tải lại trang.</span>
        </form>
      @endif

      {{-- Ai được duyệt: chỉ nhóm có quyền "Duyệt bảng chia"; người chỉ có quyền xem (Giám đốc, Quản lý phòng ban…) không có nút duyệt --}}
      <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 border-t border-[#f0f0f0] pt-3 text-xs {{ $muted }}" data-approvers>
        @unless ($canApprove)
          <span class="ant-tag m-0" data-view-only>Chỉ xem</span>
          <span>Bạn chỉ có quyền xem bảng chia. Tiền chỉ được cộng vào ví tái tiêu dùng khi người có quyền duyệt bấm Duyệt.</span>
        @endunless
        <span>Người có quyền duyệt:
          @forelse ($approvers as $approver)
            <strong class="text-[rgba(0,0,0,0.85)]">{{ $approver->name }}</strong> ({{ $approver->group?->name }})@if (! $loop->last), @endif
          @empty
            <strong class="text-[#cf1322]">chưa có ai</strong> — vào Nhóm quyền, tick "Duyệt bảng chia" cho nhóm phụ trách.
          @endforelse
        </span>
      </div>
    </div>
  </div>

  {{-- Tổng các nhóm --}}
  <div class="ant-card ant-card-bordered" data-card="groups">
    <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Các nhóm</div></div></div>
    <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
      <table style="table-layout: auto">
        <thead class="ant-table-thead">
          <tr>
            <th class="ant-table-cell">Nhóm</th>
            <th class="ant-table-cell">Đơn vào gói</th>
            <th class="ant-table-cell" {!! $right !!}>Đơn trong tháng</th>
            <th class="ant-table-cell" {!! $right !!}>Quỹ</th>
            <th class="ant-table-cell" {!! $right !!}>Người trong nhóm</th>
            <th class="ant-table-cell" {!! $right !!}>Được chia</th>
            <th class="ant-table-cell" {!! $right !!}>Mỗi người</th>
            <th class="ant-table-cell" {!! $right !!}>{{ $state === 'approved' ? 'Đã chia' : 'Sẽ chia' }}</th>
          </tr>
        </thead>
        <tbody class="ant-table-tbody">
          @forelse ($sheet['groups'] as $g)
            <tr class="ant-table-row" data-group="{{ $g['id'] }}">
              <td class="ant-table-cell"><a href="#group-{{ $g['id'] }}" class="font-medium">{{ $g['name'] }}</a></td>
              <td class="ant-table-cell whitespace-nowrap tabular-nums">{{ $g['max'] === null ? 'từ '.$money($g['min']) : $money($g['min']).' – dưới '.$money($g['max']) }}</td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ $g['order_count'] }} đơn · {{ $money($g['order_total']) }}</td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ $money($g['pool']) }}</td>
              <td class="ant-table-cell tabular-nums" {!! $right !!}>{{ $g['member_count'] }}</td>
              <td class="ant-table-cell tabular-nums" {!! $right !!}>{{ $g['paid_count'] }}@if ($g['waiting']->isNotEmpty()) <span class="{{ $muted }}">(+{{ $g['waiting']->count() }} chờ)</span>@endif</td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ $money($g['per_head']) }}</td>
              <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}><strong>{{ $money($g['distributed']) }}</strong></td>
            </tr>
          @empty
            <tr><td colspan="8" class="ant-table-cell {{ $muted }}">Chưa có cấp nào có mốc từ {{ $money($sheet['min_amount']) }} (Affiliate → Levels).</td></tr>
          @endforelse
        </tbody>
      </table>
    </div></div></div>
  </div>

  {{-- Chi tiết từng nhóm --}}
  @foreach ($sheet['groups'] as $g)
    <div class="ant-card ant-card-bordered scroll-mt-20" id="group-{{ $g['id'] }}" data-card="group">
      <div class="ant-card-head"><div class="ant-card-head-wrapper">
        <div class="ant-card-head-title">Nhóm {{ $g['name'] }}
          <span class="ml-2 text-sm font-normal {{ $muted }}">đơn {{ $g['max'] === null ? 'từ '.$money($g['min']) : 'từ '.$money($g['min']).' đến dưới '.$money($g['max']) }} · nhận đủ {{ $money($g['cap']) }} thì ra khỏi nhóm</span>
        </div>
      </div></div>
      <div class="ant-card-body flex flex-col gap-5">
        {{-- Cách tính --}}
        <div data-calc>
          <div class="mb-2 font-medium">Cách tính</div>
          <dl class="m-0 grid max-w-2xl grid-cols-[1fr_auto] gap-x-6 gap-y-1 tabular-nums">
            <dt>Tổng tiền {{ $g['order_count'] }} đơn vào gói thanh toán trong tháng {{ $label }}</dt><dd class="m-0 text-right">{{ $money($g['order_total']) }}</dd>
            <dt>− {{ $pct($sheet['fee_percent']) }} phí doanh nghiệp</dt><dd class="m-0 text-right">−{{ $money($g['order_total'] - $g['base']) }}</dd>
            <dt>= Sau phí</dt><dd class="m-0 text-right">{{ $money($g['base']) }}</dd>
            <dt class="font-medium">Quỹ = {{ $money($g['base']) }} × {{ $pct($sheet['share_percent']) }}</dt><dd class="m-0 text-right font-semibold">{{ $money($g['pool']) }}</dd>
            <dt>Người còn trong nhóm (không bị khóa, chưa nhận đủ)</dt><dd class="m-0 text-right">{{ $g['member_count'] }} người</dd>
            <dt>Được chia: {{ $g['member_count'] > $sheet['slots'] ? 'top '.$sheet['slots'].' người vào trước' : 'tất cả' }}</dt><dd class="m-0 text-right">{{ $g['paid_count'] }} người</dd>
            <dt class="font-medium">Mỗi người = {{ $money($g['pool']) }} ÷ {{ max(1, $g['paid_count']) }} (làm tròn xuống)</dt><dd class="m-0 text-right font-semibold">{{ $money($g['per_head']) }}</dd>
            <dt>{{ $state === 'approved' ? 'Đã chia' : 'Sẽ chia' }} (người sắp đủ mức chỉ nhận phần còn thiếu)</dt><dd class="m-0 text-right">{{ $money($g['distributed']) }}</dd>
            <dt>Công ty giữ (phần lẻ + phần dư)</dt><dd class="m-0 text-right">{{ $money($g['remainder']) }}</dd>
          </dl>
          @if ($g['pool'] === 0)
            <div class="mt-2 text-xs text-[#d46b08]">Tháng này nhóm không có đơn vào gói mới nên quỹ bằng 0 — không ai được chia.</div>
          @endif
        </div>

        {{-- Đơn vào gói trong tháng --}}
        <div>
          <div class="mb-2 font-medium">Đơn vào gói thanh toán trong tháng ({{ $g['orders']->count() }})</div>
          @if ($g['orders']->isEmpty())
            <p class="m-0 {{ $muted }}">Không có đơn nào.</p>
          @else
            <div class="ant-table ant-table-small"><div class="ant-table-container"><div class="ant-table-content overflow-x-auto">
              <table style="table-layout: auto" data-orders>
                <thead class="ant-table-thead"><tr>
                  <th class="ant-table-cell">Mã đơn</th><th class="ant-table-cell">Thành viên</th><th class="ant-table-cell">Xác nhận thanh toán</th>
                  <th class="ant-table-cell">Hình thức</th><th class="ant-table-cell" {!! $right !!}>Tiền hàng</th><th class="ant-table-cell"></th>
                </tr></thead>
                <tbody class="ant-table-tbody">
                  @foreach ($g['orders'] as $o)
                    <tr class="ant-table-row">
                      <td class="ant-table-cell font-mono">@can('order')<a href="{{ route('backend.orders.show', $o['order']) }}">{{ $o['order']->code }}</a>@else{{ $o['order']->code }}@endcan</td>
                      <td class="ant-table-cell">{{ $o['member']?->full_name }} <span class="text-xs font-mono {{ $muted }}">{{ $o['member']?->referral_code }}</span></td>
                      <td class="ant-table-cell tabular-nums whitespace-nowrap">{{ $o['order']->paid_at?->format('d/m/Y H:i') }}</td>
                      <td class="ant-table-cell">{{ Order::PAYMENT_METHODS[$o['order']->payment_method] ?? $o['order']->payment_method }}</td>
                      <td class="ant-table-cell tabular-nums whitespace-nowrap" {!! $right !!}>{{ $money($o['order']->goods_amount) }}</td>
                      <td class="ant-table-cell">@if ($o['new_seat'])<span class="ant-tag ant-tag-blue m-0">Vào nhóm lần đầu</span>@else<span class="text-xs {{ $muted }}">Đã có suất từ đơn trước</span>@endif</td>
                    </tr>
                  @endforeach
                </tbody>
              </table>
            </div></div></div>
          @endif
        </div>

        {{-- Được chia --}}
        <div>
          <div class="mb-2 font-medium">{{ $state === 'approved' ? 'Đã được chia' : 'Được chia' }} — {{ $g['member_count'] > $sheet['slots'] ? 'top '.$sheet['slots'] : 'tất cả' }} ({{ $g['paid']->count() }} người)</div>
          @if ($g['paid']->isEmpty())
            <p class="m-0 {{ $muted }}">Chưa có ai trong nhóm.</p>
          @else
            @include('backend.profit_share._people', ['rows' => $g['paid'], 'mode' => 'paid'])
          @endif
        </div>

        @if ($g['waiting']->isNotEmpty())
          <div>
            <div class="mb-2 font-medium">Hàng chờ — ngoài top {{ $sheet['slots'] }}, chưa được chia ({{ $g['waiting']->count() }} người)</div>
            @include('backend.profit_share._people', ['rows' => $g['waiting'], 'mode' => 'waiting'])
          </div>
        @endif

        @if ($g['skipped']->isNotEmpty())
          <div>
            <div class="mb-2 font-medium">Không được chia tháng này — tài khoản bị khóa ({{ $g['skipped']->count() }} người)</div>
            @include('backend.profit_share._people', ['rows' => $g['skipped'], 'mode' => 'skipped'])
          </div>
        @endif

        @if ($g['exited']->isNotEmpty())
          <details>
            <summary class="cursor-pointer font-medium">Đã nhận đủ và ra khỏi nhóm từ các tháng trước ({{ $g['exited']->count() }} người)</summary>
            <div class="mt-2">@include('backend.profit_share._people', ['rows' => $g['exited'], 'mode' => 'exited'])</div>
          </details>
        @endif
      </div>
    </div>
  @endforeach

  @if ($history->isNotEmpty())
    <div class="ant-card ant-card-bordered" data-card="history">
      <div class="ant-card-head"><div class="ant-card-head-wrapper"><div class="ant-card-head-title">Lịch sử</div></div></div>
      <div class="ant-card-body">
        @foreach ($history as $log)
          <div @class(['flex flex-wrap gap-x-2 text-sm', 'mt-2' => ! $loop->first])>
            <span class="tabular-nums {{ $muted }}">{{ $log->created_at?->format('d/m/Y H:i') }}</span>
            <span class="font-medium">{{ $log->actorName() }}</span>
            <span>{{ $log->description }}</span>
          </div>
        @endforeach
      </div>
    </div>
  @endif
</div>
@endsection
