@extends('frontend.layouts.app')

{{-- Nạp tiền vào ví tái tiêu dùng: form số tiền (tạo lệnh → trang lệnh có QR) + danh sách lệnh của tôi. --}}
@php
    use App\Models\WalletTopup;
    use App\Support\Money;

    $input = 'w-full rounded-lg border bg-white px-3 py-2.5 text-sm outline-none transition focus:ring-2';
    $state = $errors->has('amount') ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100';
    $tag = ['new' => 'bg-orange-50 text-orange-700', 'awaiting_confirmation' => 'bg-yellow-50 text-yellow-800', 'paid' => 'bg-brand-50 text-brand-800', 'cancelled' => 'bg-rose-50 text-rose-700'];
@endphp
@section('title', 'Nạp tiền vào ví')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-2xl px-4 pt-8">
    <a href="{{ route('member.wallet') }}" class="text-sm text-brand-700 hover:underline">‹ Ví</a>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Nạp tiền vào ví tái tiêu dùng</h1>
    <p class="mt-1 text-sm text-slate-600">Chuyển khoản vào tài khoản công ty, tải ảnh bill lên. Kế toán xác nhận xong, số tiền thực nhận được cộng vào ví tái tiêu dùng để mua hàng.</p>

    <div class="mt-5 rounded-2xl border border-amber-100 bg-amber-50 p-5">
      <div class="text-xs font-medium tracking-wide text-amber-800 uppercase">Ví tái tiêu dùng</div>
      <div class="mt-1 text-2xl font-bold text-amber-800 tabular-nums" data-wallet-reconsume>{{ Money::format($balance) }}đ</div>
    </div>

    @if ($limitReached)
      <div class="mt-5 rounded-2xl border border-amber-200 bg-white p-5 text-sm text-amber-900" data-topup-limit>
        Bạn đang có {{ WalletTopup::MAX_OPEN }} lệnh nạp chưa xong. Hoàn tất hoặc hủy bớt lệnh cũ trước khi tạo lệnh mới.
      </div>
    @else
      <form method="POST" action="{{ route('member.topups.store') }}" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 md:p-6" novalidate data-topup-form>
        @csrf
        <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700">Số tiền muốn nạp (đ) <span class="text-rose-500">*</span></label>
        <input id="amount" name="amount" value="{{ old('amount') }}" inputmode="numeric" autocomplete="off" placeholder="Tối thiểu {{ Money::format(WalletTopup::MIN_AMOUNT) }}" class="{{ $input }} {{ $state }} tabular-nums" />
        @error('amount')<p class="mt-1 text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
        <button type="submit" class="mt-4 rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow hover:bg-brand-700">Tạo lệnh nạp</button>
      </form>
    @endif

    <h2 class="mt-8 text-lg font-bold text-slate-900">Lệnh nạp của tôi</h2>
    @if ($topups->isEmpty())
      <div class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-10 text-center text-slate-500">Chưa có lệnh nạp nào.</div>
    @else
      <div class="mt-3 divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200 bg-white" data-topup-rows>
        @foreach ($topups as $topup)
          <a href="{{ route('member.topups.show', $topup) }}" class="flex items-center gap-4 p-4 hover:bg-slate-50" data-topup="{{ $topup->code }}">
            <div class="min-w-0 flex-1">
              <div class="font-mono font-medium text-slate-900">{{ $topup->code }}</div>
              <div class="mt-0.5 text-xs text-slate-500 tabular-nums">{{ $topup->created_at->format('d/m/Y H:i') }}</div>
            </div>
            <div class="text-right">
              <div class="font-semibold tabular-nums text-slate-900">{{ Money::format($topup->paid_amount ?? $topup->amount) }}đ</div>
              <span class="mt-0.5 inline-block rounded-full px-2 py-0.5 text-xs {{ $tag[$topup->displayStatus()] ?? '' }}">{{ $topup->statusLabel() }}</span>
            </div>
          </a>
        @endforeach
      </div>
      {{ $topups->links('frontend.partials.pagination') }}
    @endif
  </div>
@endsection
