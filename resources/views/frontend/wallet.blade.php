@extends('frontend.layouts.app')

{{-- Ví hoa hồng của tôi (S9): số dư, hoa hồng tháng này, tổng theo tầng F1 / F2, lịch sử từng dòng đã cộng ví (ngày, người
     mua, mã đơn, cơ sở tính × tỉ lệ = số tiền). Không hiện số điện thoại / email của người mua. Rút tiền làm ở Step 9. --}}
@php
    use App\Models\Commission;
    use App\Support\Money;

    $money = fn ($amount) => Money::format($amount);
@endphp
@section('title', 'Ví hoa hồng')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <div class="flex items-center justify-between gap-4">
      <h1 class="text-2xl font-bold text-slate-900">Ví hoa hồng</h1>
      <a href="{{ route('member.account') }}" class="text-sm text-brand-700 hover:underline">‹ Tài khoản</a>
    </div>
    <p class="mt-1 text-sm text-slate-600">Hoa hồng được cộng vào ví ngay khi đơn của người bạn giới thiệu được xác nhận thanh toán.</p>

    <div class="mt-5 grid grid-cols-2 gap-4 md:grid-cols-4">
      <div class="col-span-2 rounded-2xl border border-brand-100 bg-brand-50 p-5">
        <div class="text-xs font-medium tracking-wide text-brand-800 uppercase">Số dư ví</div>
        <div class="mt-2 text-2xl font-bold text-brand-800 tabular-nums" data-wallet-balance>{{ $money($wallet['balance']) }}đ</div>
        <p class="mt-1 text-xs text-brand-800/80">Tháng này: <strong data-wallet-month>+{{ $money($wallet['month']) }}đ</strong></p>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">F1 trực tiếp</div>
        <div class="mt-2 text-xl font-bold text-slate-900 tabular-nums" data-wallet-f1>{{ $money($wallet['f1']) }}đ</div>
      </div>
      <div class="rounded-2xl border border-slate-200 bg-white p-5">
        <div class="text-xs font-medium tracking-wide text-slate-500 uppercase">F2 gián tiếp</div>
        <div class="mt-2 text-xl font-bold text-slate-900 tabular-nums" data-wallet-f2>{{ $money($wallet['f2']) }}đ</div>
      </div>
    </div>

    <section class="mt-6">
      <h2 class="text-lg font-bold text-slate-900">Lịch sử hoa hồng</h2>
      @if ($lines->isEmpty())
        <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center text-slate-500">
          Bạn chưa có hoa hồng nào. Hoa hồng tính theo cấp của bạn lúc đơn được thanh toán — cấp hiện tại: <strong class="text-slate-700">{{ $member->level?->name }}</strong>.
        </div>
      @else
        <div class="mt-3 divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white" data-wallet-rows>
          @foreach ($lines as $line)
            <div class="flex flex-wrap items-center gap-x-4 gap-y-1 p-4" data-commission="{{ $line->id }}">
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-x-2">
                  <span @class(['rounded-full px-2 py-0.5 text-xs font-semibold', 'bg-brand-50 text-brand-800' => $line->tier === 'F1', 'bg-slate-100 text-slate-700' => $line->tier !== 'F1'])>{{ $line->tier }}</span>
                  <span class="font-medium text-slate-900">{{ $line->buyer?->full_name }}</span>
                </div>
                <div class="mt-1 text-xs text-slate-500 tabular-nums">
                  {{ $line->created_at?->format('d/m/Y H:i') }} · đơn <span class="font-mono">{{ $line->order?->code }}</span>
                </div>
                <div class="mt-0.5 text-xs text-slate-500 tabular-nums">
                  {{ $money($line->base_amount) }}đ × {{ Commission::percentLabel($line->rate_percent) }} <span class="text-slate-400">(cấp {{ $line->level_name }})</span>
                </div>
              </div>
              <div class="text-right font-semibold whitespace-nowrap text-brand-700 tabular-nums">+{{ $money($line->amount) }}đ</div>
            </div>
          @endforeach
        </div>
        {{ $lines->links('frontend.partials.pagination') }}
      @endif
      <p class="mt-4 text-xs text-slate-500">
        Cách tính: hoa hồng = cơ sở × tỉ lệ theo cấp của bạn lúc đơn được thanh toán. Cơ sở = tiền hàng của đơn, trừ {{ Commission::percentLabel($feePercent) }} phí doanh nghiệp, không tính phí giao hàng.
        F1: đơn của người bạn trực tiếp giới thiệu. F2: đơn của người do F1 của bạn giới thiệu.
      </p>
    </section>
  </div>
@endsection
