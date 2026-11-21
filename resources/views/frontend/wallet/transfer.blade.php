@extends('frontend.layouts.app')

{{-- Chuyển ví tái tiêu dùng cho thành viên khác theo mã giới thiệu. Hai bước: (1) nhập mã + số tiền; (2) $preview — xác nhận
     tên rút gọn người nhận + số tiền, nhập mật khẩu đăng nhập rồi chuyển. Chưa duyệt KYC: khóa form, dẫn sang trang KYC. --}}
@php
    use App\Services\Wallet;
    use App\Support\Money;

    $input = 'w-full rounded-lg border bg-white px-3 py-2.5 text-sm outline-none transition focus:ring-2';
    $state = fn (string $field) => $errors->has($field) ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100';
@endphp
@section('title', 'Chuyển tiền ví tái tiêu dùng')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-xl px-4 pt-8">
    <a href="{{ route('member.wallet') }}" class="text-sm text-brand-700 hover:underline">‹ Ví</a>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Chuyển tiền cho thành viên khác</h1>
    <p class="mt-1 text-sm text-slate-600">Chuyển từ ví tái tiêu dùng của bạn sang ví tái tiêu dùng của người nhận, theo mã giới thiệu của họ. Không mất phí, tiền vào ví người nhận ngay.</p>

    <div class="mt-5 rounded-2xl border border-amber-100 bg-amber-50 p-5">
      <div class="text-xs font-medium tracking-wide text-amber-800 uppercase">Ví tái tiêu dùng</div>
      <div class="mt-1 text-2xl font-bold text-amber-800 tabular-nums" data-wallet-reconsume>{{ Money::format($balance) }}đ</div>
    </div>

    @if (! $kycApproved)
      <div class="mt-5 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700" data-kyc-required>
        Bạn cần được <strong>duyệt KYC</strong> trước khi chuyển tiền cho thành viên khác.
        <a href="{{ route('member.kyc') }}" class="font-semibold text-brand-700 underline">Xác minh tài khoản (KYC)</a>
      </div>
    @elseif ($preview)
      {{-- Bước 2: xác nhận --}}
      <form method="POST" action="{{ route('member.wallet.transfer.store') }}" class="mt-5 rounded-2xl border border-slate-200 bg-white p-5 md:p-6" novalidate data-transfer-confirm>
        @csrf
        <input type="hidden" name="code" value="{{ $preview['receiver']->referral_code }}" />
        <input type="hidden" name="amount" value="{{ $preview['amount'] }}" />
        <h2 class="font-semibold text-slate-900">Xác nhận chuyển tiền</h2>
        <dl class="mt-4 grid grid-cols-[120px_minmax(0,1fr)] gap-x-4 gap-y-2 text-sm">
          <dt class="text-slate-500">Người nhận</dt>
          <dd class="font-semibold text-slate-900" data-receiver>{{ $preview['receiver']->shortName() }} <span class="font-mono font-normal text-slate-500">({{ $preview['receiver']->referral_code }})</span></dd>
          <dt class="text-slate-500">Số tiền</dt>
          <dd class="text-lg font-bold text-brand-700 tabular-nums" data-amount>{{ Money::format($preview['amount']) }}đ</dd>
          <dt class="text-slate-500">Số dư sau khi chuyển</dt>
          <dd class="tabular-nums">{{ Money::format($balance - $preview['amount']) }}đ</dd>
        </dl>
        <div class="mt-5">
          <label for="password" class="mb-1.5 block text-sm font-medium text-slate-700">Mật khẩu đăng nhập <span class="text-rose-500">*</span></label>
          <input id="password" name="password" type="password" autocomplete="current-password" autofocus class="{{ $input }} {{ $state('password') }}" />
          @error('password')<p class="mt-1 text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
        </div>
        <p class="mt-3 text-xs text-slate-500">Kiểm tra kỹ tên người nhận. Tiền đã chuyển không lấy lại được.</p>
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button type="submit" class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow hover:bg-brand-700">Chuyển {{ Money::format($preview['amount']) }}đ</button>
          <a href="{{ route('member.wallet.transfer') }}" class="px-3 py-2.5 text-sm font-medium text-slate-600 hover:text-slate-900">Sửa lại</a>
        </div>
      </form>
    @else
      {{-- Bước 1: mã giới thiệu + số tiền --}}
      <form method="POST" action="{{ route('member.wallet.transfer.preview') }}" class="mt-5 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 md:p-6" novalidate data-transfer-form>
        @csrf
        <div>
          <label for="code" class="mb-1.5 block text-sm font-medium text-slate-700">Mã giới thiệu người nhận <span class="text-rose-500">*</span></label>
          <input id="code" name="code" value="{{ old('code') }}" maxlength="20" autocomplete="off" placeholder="VD: AB12CDE" class="{{ $input }} {{ $state('code') }} font-mono uppercase" />
          @error('code')<p class="mt-1 text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
        </div>
        <div>
          <label for="amount" class="mb-1.5 block text-sm font-medium text-slate-700">Số tiền (đ) <span class="text-rose-500">*</span></label>
          <input id="amount" name="amount" value="{{ old('amount') }}" inputmode="numeric" autocomplete="off" placeholder="Tối thiểu {{ Money::format(Wallet::MIN_TRANSFER) }}" class="{{ $input }} {{ $state('amount') }} tabular-nums" />
          @error('amount')<p class="mt-1 text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
        </div>
        <button type="submit" class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow hover:bg-brand-700">Tiếp tục</button>
      </form>
    @endif
  </div>
@endsection
