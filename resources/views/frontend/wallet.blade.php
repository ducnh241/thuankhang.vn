@extends('frontend.layouts.app')

{{-- Ví hoa hồng của tôi (S9): số dư ví có thể rút + ví tái tiêu dùng; lịch sử hoa hồng theo kỳ (tuần này / tháng này / tháng
     trước / năm nay) với tổng kỳ so với kỳ trước + biểu đồ lũy kế hai kỳ; mỗi dòng gọn, bấm mở popup chi tiết cách tính.
     Không hiện số điện thoại / email của người mua. --}}
@php
    use App\Http\Controllers\Frontend\WalletController;
    use App\Models\Commission;
    use App\Support\Money;

    $money = fn ($amount) => Money::format($amount);
    $pct = fn ($percent) => Commission::percentLabel($percent);
    [$periodLabel, $previousLabel] = WalletController::PERIODS[$period];
    $change = $totals['previous'] > 0 ? round(($totals['amount'] - $totals['previous']) / $totals['previous'] * 100) : null;

    // Trục y: mốc tròn (1 / 2 / 2,5 / 5 × 10^n) ≥ giá trị lớn nhất, chia 2 vạch
    $niceMax = 0;
    if ($chart['max'] > 0) {
        $magnitude = 10 ** floor(log10($chart['max']));
        foreach ([1, 2, 2.5, 5, 10] as $step) {
            if ($step * $magnitude >= $chart['max']) {
                $niceMax = (int) ($step * $magnitude);
                break;
            }
        }
    }
    $short = fn (int $value) => match (true) {
        $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1, ',', '.'), '0'), ',').'tr',
        $value >= 1_000 => rtrim(rtrim(number_format($value / 1_000, 1, ',', '.'), '0'), ',').'k',
        default => (string) $value,
    };
    $n = count($chart['points']);
    $x = fn (int $i) => $n > 1 ? round($i / ($n - 1) * 100, 3) : 0;
    $y = fn (int $value) => $niceMax ? round(100 - $value / $niceMax * 100, 3) : 100;
    $path = function (int $col) use ($chart, $x, $y) {
        $d = '';
        foreach ($chart['points'] as $i => $point) {
            if ($point[$col] !== null) {
                $d .= ($d === '' ? 'M' : 'L').$x($i).' '.$y($point[$col]).' ';
            }
        }

        return trim($d);
    };
    $labelEvery = $n > 12 ? 5 : 1;
@endphp
@section('title', 'Ví hoa hồng')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <div class="flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold text-slate-900">Ví hoa hồng</h1>
      <a href="{{ route('member.account') }}" class="text-sm text-brand-700 hover:underline">‹ Tài khoản</a>
    </div>
    <p class="mt-1 text-sm text-slate-600">
      Hoa hồng được cộng ngay khi đơn của người bạn giới thiệu được xác nhận thanh toán, chia
      {{ $pct($split['cash_percent']) }} vào ví có thể rút, {{ $pct($split['reconsume_percent']) }} vào ví tái tiêu dùng và
      khấu trừ {{ $pct($split['tax_percent']) }} thuế thu nhập cá nhân.
    </p>

    <div class="mt-5 grid gap-4 sm:grid-cols-2">
      <div class="rounded-2xl border border-brand-100 bg-brand-50 p-5">
        <div class="text-xs font-medium tracking-wide text-brand-800 uppercase">Ví có thể rút</div>
        <div class="mt-2 text-2xl font-bold text-brand-800 tabular-nums" data-wallet-balance>{{ $money($wallet['balance']) }}đ</div>
        <p class="mt-1 text-xs text-brand-800/80">Tháng này: <strong data-wallet-month>+{{ $money($wallet['month_cash']) }}đ</strong></p>
      </div>
      <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5">
        <div class="text-xs font-medium tracking-wide text-amber-800 uppercase">Ví tái tiêu dùng</div>
        <div class="mt-2 text-2xl font-bold text-amber-800 tabular-nums" data-wallet-reconsume>{{ $money($wallet['reconsume']) }}đ</div>
        <p class="mt-1 text-xs text-amber-800/80">Dùng để mua hàng, không rút ra tiền mặt.</p>
      </div>
    </div>

    <section class="mt-8">
      <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 class="text-lg font-bold text-slate-900">Lịch sử hoa hồng</h2>
        <nav class="flex flex-wrap gap-1.5" aria-label="Chọn kỳ" data-period-filter>
          @foreach (WalletController::PERIODS as $key => [$label])
            <a href="{{ route('member.wallet', $key === WalletController::DEFAULT_PERIOD ? [] : ['ky' => $key]) }}"
               @class(['rounded-full border px-3 py-1 text-sm', 'border-brand-600 bg-brand-600 font-medium text-white' => $period === $key, 'border-slate-200 bg-white text-slate-600 hover:border-brand-300' => $period !== $key])
               @if ($period === $key) aria-current="page" @endif>{{ $label }}</a>
          @endforeach
        </nav>
      </div>

      {{-- Tổng kỳ + so sánh kỳ trước + biểu đồ lũy kế --}}
      <div class="mt-3 rounded-2xl border border-slate-200 bg-white p-4 sm:p-5" data-period-summary>
        <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
          <div>
            <div class="text-xs text-slate-500">Hoa hồng {{ mb_strtolower($periodLabel) }}</div>
            <div class="mt-1 text-2xl font-bold text-slate-900 tabular-nums" data-period-total>{{ $money($totals['amount']) }}đ</div>
            <div class="mt-1 text-xs text-slate-500 tabular-nums">
              Rút được <strong class="text-slate-700">{{ $money($totals['cash']) }}đ</strong> · tái tiêu dùng <strong class="text-slate-700">{{ $money($totals['reconsume']) }}đ</strong> · thuế TNCN <strong class="text-slate-700">{{ $money($totals['tax']) }}đ</strong>
            </div>
          </div>
          <div class="text-right text-sm tabular-nums" data-period-compare>
            <div class="text-slate-500">{{ $previousLabel }}: <span class="text-slate-700">{{ $money($totals['previous']) }}đ</span></div>
            @if ($change !== null)
              <div @class(['font-semibold', 'text-brand-700' => $change >= 0, 'text-rose-700' => $change < 0])>{{ $change >= 0 ? '▲ +' : '▼ ' }}{{ $change }}% so với {{ mb_strtolower($previousLabel) }}</div>
            @endif
          </div>
        </div>

        @if ($chart['max'] > 0)
          <div class="mt-4 flex items-center gap-4 text-xs text-slate-600" aria-hidden="true">
            <span class="flex items-center gap-1.5"><span class="inline-block h-0.5 w-4 rounded bg-brand-600"></span>{{ $periodLabel }}</span>
            <span class="flex items-center gap-1.5"><span class="inline-block w-4 border-t-2 border-dashed border-slate-400"></span>{{ $previousLabel }}</span>
            <span class="ml-auto text-slate-400">Lũy kế</span>
          </div>
          <div class="relative mt-2 h-44 pl-11 pb-5" data-wallet-chart
               data-points='@json($chart['points'])' data-current-label="{{ $periodLabel }}" data-previous-label="{{ $previousLabel }}">
            {{-- Trục y + lưới --}}
            @foreach ([1, 0.5, 0] as $fraction)
              <div class="pointer-events-none absolute right-0 left-11 border-t border-slate-100" style="top: calc((100% - 1.25rem) * {{ 1 - $fraction }})"></div>
              <div class="pointer-events-none absolute left-0 w-10 -translate-y-1/2 text-right text-[11px] text-slate-400 tabular-nums" style="top: calc((100% - 1.25rem) * {{ 1 - $fraction }})">{{ $short((int) ($niceMax * $fraction)) }}</div>
            @endforeach
            {{-- Trục x --}}
            <div class="pointer-events-none absolute right-0 bottom-0 left-11 h-4">
              @foreach ($chart['points'] as $i => $point)
                @if ($i % $labelEvery === 0 || $i === $n - 1)
                  <span class="absolute -translate-x-1/2 text-[11px] text-slate-400" style="left: {{ $x($i) }}%">{{ $point[0] }}</span>
                @endif
              @endforeach
            </div>
            <div class="absolute inset-0 top-0 right-0 bottom-5 left-11" data-chart-plot>
              <svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" role="img"
                   aria-label="Hoa hồng lũy kế {{ mb_strtolower($periodLabel) }} {{ $money($totals['amount']) }}đ, {{ mb_strtolower($previousLabel) }} {{ $money($totals['previous']) }}đ">
                <path d="{{ $path(3) }}" fill="none" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 4" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
                <path d="{{ $path(2) }}" fill="none" stroke="#059669" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" vector-effect="non-scaling-stroke" />
              </svg>
              <div class="pointer-events-none absolute top-0 bottom-0 hidden w-px bg-slate-300" data-chart-crosshair></div>
              <div class="pointer-events-none absolute hidden size-2.5 -translate-1/2 rounded-full bg-slate-400 ring-2 ring-white" data-chart-dot="previous"></div>
              <div class="pointer-events-none absolute hidden size-2.5 -translate-1/2 rounded-full bg-brand-600 ring-2 ring-white" data-chart-dot="current"></div>
              <div class="pointer-events-none absolute top-0 z-10 hidden min-w-40 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs shadow-lg tabular-nums" data-chart-tip></div>
            </div>
          </div>
        @else
          <p class="mt-4 text-sm text-slate-500">Chưa có hoa hồng trong {{ mb_strtolower($periodLabel) }} và {{ mb_strtolower($previousLabel) }}.</p>
        @endif
      </div>

      @if ($lines->isEmpty())
        <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center text-slate-500">
          Không có hoa hồng nào trong {{ mb_strtolower($periodLabel) }}. Hoa hồng tính theo cấp của bạn lúc đơn được thanh toán — cấp hiện tại: <strong class="text-slate-700">{{ $member->level?->name }}</strong>.
        </div>
      @else
        <div class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white" data-wallet-rows>
          @foreach ($lines as $line)
            @php $isSplit = $line->tax_percent !== null; @endphp
            <button type="button" class="flex w-full items-start gap-x-4 p-4 text-left hover:bg-slate-50 focus-visible:bg-slate-50" data-commission="{{ $line->id }}"
                    data-dialog-open="commission-{{ $line->id }}" aria-haspopup="dialog">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2">
                  <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-brand-50 text-brand-800' => $line->tier === 'F1', 'bg-slate-100 text-slate-700' => $line->tier !== 'F1'])>{{ $line->tier }}</span>
                  <span class="font-medium text-slate-900">{{ $line->buyer?->full_name }}</span>
                </div>
                <div class="mt-1 text-xs text-slate-500 tabular-nums">
                  {{ $line->created_at?->format('d/m/Y H:i') }} · đơn <span class="font-mono">{{ $line->order?->code }}</span>
                </div>
              </div>
              <div class="text-right">
                <div class="font-semibold whitespace-nowrap text-brand-700 tabular-nums" data-line-cash>+{{ $money($isSplit ? $line->cash_amount : $line->amount) }}đ</div>
                <div class="text-xs text-slate-500">vào ví có thể rút</div>
              </div>
            </button>
          @endforeach
        </div>

        {{-- Popup chi tiết cách tính của từng dòng --}}
        @foreach ($lines as $line)
          @php
              $isSplit = $line->tax_percent !== null;
              $fee = $line->goods_amount - $line->base_amount;
          @endphp
          <dialog id="commission-{{ $line->id }}" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl p-0 text-slate-700 shadow-xl backdrop:bg-slate-900/50" aria-labelledby="commission-{{ $line->id }}-title">
            <div class="flex items-start justify-between gap-4 border-b border-slate-100 px-5 py-4">
              <div>
                <h3 id="commission-{{ $line->id }}-title" class="flex flex-wrap items-center gap-x-2 font-bold text-slate-900">
                  <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-brand-50 text-brand-800' => $line->tier === 'F1', 'bg-slate-100 text-slate-700' => $line->tier !== 'F1'])>{{ $line->tier }}</span>
                  {{ $line->buyer?->full_name }}
                </h3>
                <p class="mt-1 text-xs text-slate-500 tabular-nums">{{ $line->created_at?->format('d/m/Y H:i') }} · đơn <span class="font-mono">{{ $line->order?->code }}</span> · {{ $line->tier === 'F1' ? 'F1 trực tiếp' : 'F2 gián tiếp' }}</p>
              </div>
              <button type="button" class="-m-1 rounded-full p-1 text-xl leading-none text-slate-400 hover:text-slate-700" data-dialog-close aria-label="Đóng">×</button>
            </div>
            <dl class="grid grid-cols-[1fr_auto] gap-x-4 gap-y-1.5 px-5 py-4 text-sm tabular-nums" data-line-breakdown>
              <dt>Tiền hàng <span class="text-xs text-slate-400">(không tính phí giao hàng)</span></dt>
              <dd class="text-right">{{ $money($line->goods_amount) }}đ</dd>
              @if ($line->order?->items->isNotEmpty())
                <dd class="col-span-2 -mt-0.5 mb-1">
                  <ul class="space-y-0.5 border-l-2 border-slate-100 pl-3 text-xs text-slate-500">
                    @foreach ($line->order->items as $item)
                      <li class="flex justify-between gap-3"><span>{{ $item->product_name }}{{ $item->variant_name ? ' — '.$item->variant_name : '' }} × {{ $item->qty }}</span><span>{{ $money($item->line_amount) }}đ</span></li>
                    @endforeach
                  </ul>
                </dd>
              @endif

              <dt>− {{ $pct($line->fee_percent) }} phí doanh nghiệp</dt>
              <dd class="text-right">−{{ $money($fee) }}đ</dd>

              <dt class="font-medium text-slate-900">= Cơ sở tính hoa hồng</dt>
              <dd class="text-right font-medium text-slate-900">{{ $money($line->base_amount) }}đ</dd>

              <dt class="font-medium text-slate-900">Hoa hồng = {{ $money($line->base_amount) }}đ × {{ $pct($line->rate_percent) }} <span class="font-normal text-slate-400">(cấp {{ $line->level_name }})</span></dt>
              <dd class="text-right font-semibold text-slate-900" data-line-amount>{{ $money($line->amount) }}đ</dd>

              @if ($isSplit)
                <dt class="mt-2 border-t border-slate-100 pt-2.5 text-brand-800">→ Ví có thể rút ({{ $pct($line->cashPercent()) }})</dt>
                <dd class="mt-2 border-t border-slate-100 pt-2.5 text-right font-semibold text-brand-800" data-line-cash-amount>+{{ $money($line->cash_amount) }}đ</dd>

                <dt class="text-amber-800">→ Ví tái tiêu dùng ({{ $pct($line->reconsume_percent) }})</dt>
                <dd class="text-right font-medium text-amber-800" data-line-reconsume>+{{ $money($line->reconsume_amount) }}đ</dd>

                <dt class="text-rose-700">→ Thuế thu nhập cá nhân ({{ $pct($line->tax_percent) }})</dt>
                <dd class="text-right font-medium text-rose-700" data-line-tax>−{{ $money($line->tax_amount) }}đ</dd>
              @endif
            </dl>
          </dialog>
        @endforeach

        {{ $lines->links('frontend.partials.pagination') }}
      @endif
      <p class="mt-4 text-xs text-slate-500">
        Cách tính: cơ sở = tiền hàng của đơn (giá bán đã gồm thuế, không tính phí giao hàng) trừ {{ $pct($feePercent) }} phí doanh nghiệp;
        hoa hồng = cơ sở × tỉ lệ theo cấp của bạn lúc đơn được thanh toán (làm tròn xuống). Hoa hồng được chia:
        {{ $pct($split['cash_percent']) }} vào ví có thể rút, {{ $pct($split['reconsume_percent']) }} vào ví tái tiêu dùng,
        {{ $pct($split['tax_percent']) }} khấu trừ thuế thu nhập cá nhân.
        F1: đơn của người bạn trực tiếp giới thiệu. F2: đơn của người do F1 của bạn giới thiệu.
      </p>
    </section>
  </div>
@endsection
