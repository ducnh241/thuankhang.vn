@extends('frontend.layouts.app')

{{-- Chi tiết lệnh nạp ví. Lệnh mới còn hạn: QR + thông tin chuyển khoản của công ty, ô ảnh bill bắt buộc + "Tôi đã chuyển khoản"
     (JS chung [data-bill-form] / [data-kyc-image] như trang đơn hàng), "Hủy lệnh". Chờ xác nhận / đã cộng ví / đã hủy: thông báo. --}}
@php
    use App\Models\MemberKyc;
    use App\Models\WalletTopup;
    use App\Support\Money;
    use App\Support\VietQr;

    $status = $topup->displayStatus();
    $hasBank = VietQr::configured($bank);
    $copy = 'rounded border border-slate-300 bg-white px-2 py-0.5 font-sans text-xs font-normal text-slate-700 hover:border-brand-500';
@endphp
@section('title', 'Lệnh nạp '.$topup->code)
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-3xl px-4 pt-8">
    <a href="{{ route('member.topups.index') }}" class="text-sm text-brand-700 hover:underline">‹ Nạp tiền vào ví</a>
    <div class="mt-2 flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-bold text-slate-900">Lệnh nạp <span class="font-mono">{{ $topup->code }}</span></h1>
      <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-sm font-medium text-slate-700" data-topup-status="{{ $status }}">{{ $topup->statusLabel() }}</span>
    </div>
    <p class="mt-1 text-sm text-slate-500">Tạo lúc {{ $topup->created_at->format('d/m/Y H:i') }} · Số tiền: <strong class="text-slate-900">{{ Money::format($topup->amount) }}đ</strong></p>

    @if ($topup->isAwaitingPayment())
      <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 md:p-6" data-payment>
        <h2 class="font-semibold text-amber-900">Chuyển khoản để nạp tiền vào ví</h2>
        <p class="mt-1 text-sm text-amber-800">Hạn chuyển khoản: <strong>{{ $topup->expires_at?->format('H:i d/m/Y') }}</strong>. Quá hạn lệnh sẽ tự hủy.</p>
        @if ($hasBank)
          <div class="mt-4 flex flex-col gap-6 md:flex-row md:items-start">
            @if ($qr)
              <figure class="mx-auto w-56 shrink-0 rounded-xl bg-white p-2 shadow-sm md:mx-0">
                <img src="{{ $qr }}" alt="Mã QR chuyển khoản lệnh {{ $topup->code }}" width="220" height="220" class="w-full" data-qr="{{ $qrIsStatic ? 'uploaded' : 'vietqr' }}" />
                <figcaption class="mt-1 text-center text-xs text-slate-500">{{ $qrIsStatic ? 'Quét bằng app ngân hàng, rồi nhập đúng số tiền và nội dung' : 'Quét bằng app ngân hàng' }}</figcaption>
              </figure>
            @endif
            <dl class="grid flex-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-[150px_minmax(0,1fr)]">
              <dt class="text-slate-600">Ngân hàng</dt><dd class="font-semibold text-slate-900">{{ $bank['label'] }}</dd>
              <dt class="text-slate-600">Số tài khoản</dt>
              <dd class="flex items-center gap-2 font-mono font-semibold text-slate-900">{{ $bank['account'] }} <button type="button" class="{{ $copy }}" data-copy="{{ $bank['account'] }}">Sao chép</button></dd>
              @if ($bank['name'])<dt class="text-slate-600">Chủ tài khoản</dt><dd class="font-semibold text-slate-900">{{ $bank['name'] }}</dd>@endif
              <dt class="text-slate-600">Số tiền</dt>
              <dd class="flex items-center gap-2 text-lg font-bold text-brand-700">{{ Money::format($topup->amount) }}đ <button type="button" class="{{ $copy }}" data-copy="{{ $topup->amount }}">Sao chép</button></dd>
              <dt class="text-slate-600">Nội dung chuyển khoản</dt>
              <dd class="flex items-center gap-2 font-mono text-lg font-bold text-slate-900">{{ $topup->code }} <button type="button" class="{{ $copy }}" data-copy="{{ $topup->code }}">Sao chép</button></dd>
            </dl>
          </div>
          <p class="mt-4 text-xs text-amber-800">Chuyển đúng số tiền và nội dung là mã lệnh để được xác nhận nhanh. Ví được cộng theo số tiền công ty thực nhận.</p>
        @else
          <p class="mt-3 text-sm text-amber-900">Vui lòng liên hệ hotline @if ($site['site_hotline'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="font-semibold underline">{{ $site['site_hotline'] }}</a>@endif để được hướng dẫn chuyển khoản cho lệnh <strong>{{ $topup->code }}</strong>.</p>
        @endif
        <div class="mt-5 border-t border-amber-200 pt-4">
          <form method="POST" action="{{ route('member.topups.transferred', $topup) }}" enctype="multipart/form-data" novalidate data-bill-form>
            @csrf
            <div class="grid gap-4 sm:grid-cols-[200px_minmax(0,1fr)] sm:items-center" data-kyc-image data-max-bytes="{{ MemberKyc::maxKilobytes() * 1024 }}" data-target-bytes="{{ 450 * 1024 }}">
              <div class="w-full max-w-[200px]">
                <label for="f_bill" @class(['relative flex aspect-[3/4] cursor-pointer flex-col items-center justify-center gap-1 overflow-hidden rounded-xl border-2 border-dashed bg-white px-3 text-center transition hover:border-brand-500',
                  'border-rose-300' => $errors->has('bill'), 'border-amber-300' => ! $errors->has('bill')])>
                  <svg class="size-8 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3.5h12v17l-2.5-1.5L13 20.5 10.5 19 8 20.5 6 19z" /><path stroke-linecap="round" d="M9 8h6M9 11.5h6M9 15h3" /></svg>
                  <span class="text-sm font-medium text-slate-700">Chọn ảnh bill</span>
                  <img alt="" class="absolute inset-0 size-full bg-white object-contain" hidden data-kyc-preview />
                </label>
                <input id="f_bill" name="bill" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" aria-labelledby="l_bill" required />
              </div>
              <div class="min-w-0 text-sm">
                <div class="font-semibold text-amber-900" id="l_bill">Ảnh bill chuyển khoản <span class="text-rose-500">*</span></div>
                <p class="mt-1 text-xs text-amber-800">Sau khi chuyển khoản, chụp màn hình giao dịch thành công trong ứng dụng ngân hàng rồi tải lên đây. Bắt buộc có ảnh bill mới bấm được “Tôi đã chuyển khoản”.</p>
                <p class="mt-1 text-xs break-words text-slate-500" hidden data-kyc-note></p>
                @error('bill')<p class="mt-1 text-sm text-rose-600" role="alert">{{ $message }}</p>@enderror
              </div>
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
              <button type="submit" class="rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white shadow hover:bg-brand-700 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:shadow-none" data-bill-submit>Tôi đã chuyển khoản</button>
              <button type="submit" form="cancel-topup-form" class="rounded-full px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-rose-600">Hủy lệnh</button>
              <span class="text-xs text-amber-800" hidden data-bill-hint>Chọn ảnh bill để bấm được nút.</span>
            </div>
          </form>
          <form id="cancel-topup-form" method="POST" action="{{ route('member.topups.cancel', $topup) }}" onsubmit="return confirm('Hủy lệnh nạp {{ $topup->code }}?')">
            @csrf
          </form>
        </div>
      </section>
    @elseif ($status === WalletTopup::STATUS_AWAITING)
      <div class="mt-6 rounded-2xl border border-yellow-200 bg-yellow-50 p-5 text-sm text-yellow-900" data-awaiting>
        <p class="font-semibold">Đang chờ kế toán xác nhận</p>
        <p class="mt-1">Bạn đã báo chuyển khoản lúc {{ $topup->confirm_requested_at?->format('H:i d/m/Y') }}. Kế toán sẽ đối chiếu với ngân hàng và cộng tiền vào ví sớm nhất. Số tiền: <strong>{{ Money::format($topup->amount) }}đ</strong>, nội dung: <strong class="font-mono">{{ $topup->code }}</strong>.</p>
        @if ($topup->hasBill())
          <a href="{{ route('member.topups.bill', $topup) }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-3 rounded-xl border border-yellow-300 bg-white p-2 pr-4 text-yellow-900 hover:border-yellow-500" data-bill-sent>
            <img src="{{ route('member.topups.bill', $topup) }}?v={{ $topup->confirm_requested_at?->timestamp }}" alt="Ảnh bill đã gửi" loading="lazy" class="size-16 rounded-lg border border-yellow-200 bg-white object-cover" />
            <span class="font-medium">Ảnh bill bạn đã gửi</span>
          </a>
        @endif
      </div>
    @elseif ($status === WalletTopup::STATUS_PAID)
      <div class="mt-6 rounded-2xl border border-brand-100 bg-brand-50 p-5 text-sm text-brand-900" data-topup-paid>
        <p class="font-semibold">Đã cộng <span class="tabular-nums">{{ Money::format($topup->paid_amount) }}đ</span> vào ví tái tiêu dùng</p>
        <p class="mt-1">Xác nhận lúc {{ $topup->paid_at?->format('H:i d/m/Y') }}. <a href="{{ route('member.wallet.history', ['vi' => 'reconsume']) }}" class="font-medium text-brand-700 underline">Xem giao dịch ví</a></p>
      </div>
    @elseif ($status === WalletTopup::STATUS_CANCELLED)
      <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700" data-topup-cancelled>
        Lệnh đã hủy{{ $topup->cancelReasonText() ? ' — '.$topup->cancelReasonText() : '' }}. Nếu bạn đã chuyển khoản, vui lòng liên hệ hotline để được hỗ trợ.
      </div>
    @endif
  </div>
@endsection
