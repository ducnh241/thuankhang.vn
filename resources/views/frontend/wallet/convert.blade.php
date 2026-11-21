@extends('frontend.layouts.app')

{{-- Chuyển từ ví rút tiền sang ví tái tiêu dùng (một chiều — không chuyển ngược lại được). --}}
@php
    use App\Services\Wallet;
    use App\Support\Money;

    $input = 'w-full rounded-lg border bg-white px-3 py-2.5 text-sm outline-none transition focus:ring-2';
    $state = $errors->has('amount') ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100';
@endphp
@section('title', 'Chuyển sang ví tái tiêu dùng')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-xl px-4 pt-8">
    <a href="{{ route('member.wallet') }}" class="text-sm text-brand-700 hover:underline">‹ Ví</a>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Chuyển sang ví tái tiêu dùng</h1>
    <p class="mt-1 text-sm text-slate-600">Chuyển tiền từ ví rút tiền sang ví tái tiêu dùng để mua hàng. <strong>Chỉ chuyển một chiều</strong> — tiền ở ví tái tiêu dùng không chuyển ngược lại và không rút ra được.</p>

    <div class="mt-5 grid grid-cols-2 gap-3">
      <div class="rounded-2xl border border-brand-100 bg-brand-50 p-4">
        <div class="text-xs font-medium tracking-wide text-brand-800 uppercase">Ví rút tiền</div>
        <div class="mt-1 text-xl font-bold text-brand-800 tabular-nums" data-wallet-balance>{{ Money::format($cash) }}đ</div>
      </div>
      <div class="rounded-2xl border border-amber-100 bg-amber-50 p-4">
        <div class="text-xs font-medium tracking-wide text-amber-800 uppercase">Ví tái tiêu dùng</div>
        <div class="mt-1 text-xl font-bold text-amber-800 tabular-nums" data-wallet-reconsume>{{ Money::format($reconsume) }}đ</div>
      </div>
    </div>

    <form method="POST" action="{{ route('member.wallet.convert.store') }}" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 md:p-6" novalidate data-convert-form
      onsubmit="return confirm('Chuyển sang ví tái tiêu dùng? Không chuyển ngược lại được.')">
      @csrf
      <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700">Số tiền (đ) <span class="text-rose-500">*</span></label>
      <input id="amount" name="amount" value="{{ old('amount') }}" inputmode="numeric" autocomplete="off" placeholder="Tối thiểu {{ Money::format(Wallet::MIN_CONVERT) }}" class="{{ $input }} {{ $state }} tabular-nums" />
      @error('amount')<p class="mt-1 text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
      <button type="submit" class="mt-4 rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow hover:bg-brand-700 disabled:bg-slate-300" @disabled($cash < Wallet::MIN_CONVERT)>Chuyển sang ví tái tiêu dùng</button>
      @if ($cash < Wallet::MIN_CONVERT)
        <p class="mt-2 text-xs text-slate-500">Ví rút tiền cần có ít nhất {{ Money::format(Wallet::MIN_CONVERT) }}đ.</p>
      @endif
    </form>
  </div>
@endsection
