@extends('frontend.layouts.app')

{{-- Chi tiết đơn của tôi (S7). Đơn mới còn hạn: mã QR (VietQR) + thông tin chuyển khoản, nút "Tôi đã chuyển khoản"
     (→ Chờ xác nhận thanh toán) và "Hủy đơn". Chờ xác nhận: thông báo đang đối chiếu. Đã hủy: lý do. --}}
@php
    use App\Models\Order;
    use App\Support\Money;

    $status = $order->displayStatus();
    $hasBank = \App\Support\VietQr::configured($bank);
    $copy = 'rounded border border-slate-300 bg-white px-2 py-0.5 font-sans text-xs font-normal text-slate-700 hover:border-brand-500';
@endphp
@section('title', 'Đơn '.$order->code)
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <a href="{{ route('member.orders.index') }}" class="text-sm text-brand-700 hover:underline">‹ Đơn hàng của tôi</a>
    <div class="mt-2 flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-bold text-slate-900">Đơn <span class="font-mono">{{ $order->code }}</span></h1>
      @include('frontend.orders.status', ['order' => $order])
    </div>
    <p class="mt-1 text-sm text-slate-500">Đặt lúc {{ $order->created_at->format('d/m/Y H:i') }}</p>

    @if ($order->isAwaitingPayment())
      <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 md:p-6" data-payment>
        <h2 class="font-semibold text-amber-900">Chuyển khoản để hoàn tất đơn hàng</h2>
        <p class="mt-1 text-sm text-amber-800">Hạn thanh toán: <strong>{{ $order->expires_at?->format('H:i d/m/Y') }}</strong>. Quá hạn đơn sẽ tự hủy.</p>
        @if ($hasBank)
          <div class="mt-4 flex flex-col gap-6 md:flex-row md:items-start">
            @if ($qr)
              <figure class="mx-auto w-56 shrink-0 rounded-xl bg-white p-2 shadow-sm md:mx-0">
                <img src="{{ $qr }}" alt="Mã QR chuyển khoản đơn {{ $order->code }}" width="220" height="220" class="w-full" data-qr="{{ $qrIsStatic ? 'uploaded' : 'vietqr' }}" />
                <figcaption class="mt-1 text-center text-xs text-slate-500">{{ $qrIsStatic ? 'Quét bằng app ngân hàng, rồi nhập đúng số tiền và nội dung' : 'Quét bằng app ngân hàng' }}</figcaption>
              </figure>
            @endif
            <dl class="grid flex-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-[150px_minmax(0,1fr)]">
              <dt class="text-slate-600">Ngân hàng</dt><dd class="font-semibold text-slate-900">{{ $bank['label'] }}</dd>
              <dt class="text-slate-600">Số tài khoản</dt>
              <dd class="flex items-center gap-2 font-mono font-semibold text-slate-900">{{ $bank['account'] }} <button type="button" class="{{ $copy }}" data-copy="{{ $bank['account'] }}">Sao chép</button></dd>
              @if ($bank['name'])<dt class="text-slate-600">Chủ tài khoản</dt><dd class="font-semibold text-slate-900">{{ $bank['name'] }}</dd>@endif
              <dt class="text-slate-600">Số tiền</dt>
              <dd class="flex items-center gap-2 text-lg font-bold text-brand-700">{{ Money::format($order->total_amount) }}đ <button type="button" class="{{ $copy }}" data-copy="{{ $order->total_amount }}">Sao chép</button></dd>
              <dt class="text-slate-600">Nội dung chuyển khoản</dt>
              <dd class="flex items-center gap-2 font-mono text-lg font-bold text-slate-900">{{ $order->code }} <button type="button" class="{{ $copy }}" data-copy="{{ $order->code }}">Sao chép</button></dd>
            </dl>
          </div>
          <p class="mt-4 text-xs text-amber-800">Chuyển đúng số tiền và nội dung là mã đơn để được xác nhận nhanh.</p>
        @else
          <p class="mt-3 text-sm text-amber-900">Vui lòng liên hệ hotline @if ($site['site_hotline'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="font-semibold underline">{{ $site['site_hotline'] }}</a>@endif để được hướng dẫn thanh toán đơn <strong>{{ $order->code }}</strong>.</p>
        @endif
        {{-- Bắt buộc tải ảnh bill chuyển khoản rồi mới bấm được "Tôi đã chuyển khoản" (JS bật nút khi đã chọn ảnh; máy chủ
             kiểm tra lại). Ô ảnh dùng chung JS [data-kyc-image]: xem trước + thu nhỏ ảnh trước khi gửi. --}}
        <div class="mt-5 border-t border-amber-200 pt-4">
          <form method="POST" action="{{ route('member.orders.transferred', $order) }}" enctype="multipart/form-data" novalidate data-bill-form>
            @csrf
            <div class="grid gap-4 sm:grid-cols-[200px_minmax(0,1fr)] sm:items-center" data-kyc-image data-max-bytes="{{ \App\Models\MemberKyc::maxKilobytes() * 1024 }}" data-target-bytes="{{ 450 * 1024 }}">
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
              <button type="submit" form="cancel-order-form" class="rounded-full px-4 py-2.5 text-sm font-medium text-slate-600 hover:text-rose-600">Hủy đơn</button>
              <span class="text-xs text-amber-800" hidden data-bill-hint>Chọn ảnh bill để bấm được nút.</span>
            </div>
          </form>
          <form id="cancel-order-form" method="POST" action="{{ route('member.orders.cancel', $order) }}" onsubmit="return confirm('Hủy đơn {{ $order->code }}?')">
            @csrf
          </form>
        </div>
      </section>
    @elseif ($status === Order::STATUS_AWAITING)
      <div class="mt-6 rounded-2xl border border-yellow-200 bg-yellow-50 p-5 text-sm text-yellow-900" data-awaiting>
        <p class="font-semibold">Đang chờ xác nhận thanh toán</p>
        <p class="mt-1">Bạn đã báo chuyển khoản lúc {{ $order->confirm_requested_at?->format('H:i d/m/Y') }}. Chúng tôi sẽ đối chiếu với ngân hàng và xác nhận sớm nhất. Số tiền: <strong>{{ Money::format($order->total_amount) }}đ</strong>, nội dung: <strong class="font-mono">{{ $order->code }}</strong>.</p>
        @if ($order->hasBill())
          <a href="{{ route('member.orders.bill', $order) }}" target="_blank" rel="noopener" class="mt-3 inline-flex items-center gap-3 rounded-xl border border-yellow-300 bg-white p-2 pr-4 text-yellow-900 hover:border-yellow-500" data-bill-sent>
            <img src="{{ route('member.orders.bill', $order) }}?v={{ $order->confirm_requested_at?->timestamp }}" alt="Ảnh bill đã gửi" loading="lazy" class="size-16 rounded-lg border border-yellow-200 bg-white object-cover" />
            <span class="font-medium">Ảnh bill bạn đã gửi</span>
          </a>
        @endif
      </div>
    @elseif ($status === Order::STATUS_CANCELLED)
      <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">
        Đơn đã hủy{{ $order->cancelReasonText() ? ' — '.$order->cancelReasonText() : '' }}.
        @if ($order->isOverdue() || $order->cancel_source === Order::CANCEL_EXPIRED) Nếu bạn đã chuyển khoản, vui lòng liên hệ hotline để được hỗ trợ.@endif
      </div>
    @endif

    <div class="mt-6 grid gap-6 md:grid-cols-[minmax(0,1fr)_300px]">
      <section class="rounded-2xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-100 px-5 py-4 font-semibold text-slate-900">Sản phẩm</h2>
        <ul class="divide-y divide-slate-100">
          @foreach ($order->items as $item)
            <li class="flex gap-4 px-5 py-4">
              <span class="size-14 shrink-0 overflow-hidden rounded-lg border border-slate-100 bg-white">@php $thumb = $item->variant?->image ?? $item->product?->cover; @endphp @if ($thumb)<img src="{{ $thumb->url() }}" alt="" class="size-full object-contain" />@endif</span>
              <div class="min-w-0 flex-1 text-sm">
                <div class="font-medium text-slate-900">{{ $item->product_name }}</div>
                @if ($item->variant_name)<div class="text-slate-600" data-item-variant>{{ $item->variant_name }}</div>@endif
                <div class="text-slate-500">{{ Money::format($item->unit_price) }}đ × {{ $item->qty }}</div>
              </div>
              <div class="shrink-0 text-sm font-semibold">{{ Money::format($item->line_amount) }}đ</div>
            </li>
          @endforeach
        </ul>
        <dl class="space-y-2 border-t border-slate-100 px-5 py-4 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">Tiền hàng</dt><dd>{{ Money::format($order->goods_amount) }}đ</dd></div>
          @if ($order->shipping_fee > 0){{-- chỉ đơn cũ (trước 19/11/2026) có thu phí ship --}}
            <div class="flex justify-between"><dt class="text-slate-500">Phí giao hàng</dt><dd>{{ Money::format($order->shipping_fee) }}đ</dd></div>
          @endif
          <div class="flex justify-between text-base"><dt class="font-semibold">Tổng thanh toán</dt><dd class="font-bold text-brand-700">{{ Money::format($order->total_amount) }}đ</dd></div>
        </dl>
      </section>

      <section class="h-fit rounded-2xl border border-slate-200 bg-white p-5 text-sm">
        <h2 class="font-semibold text-slate-900">{{ Order::DELIVERIES[$order->delivery_type] }}</h2>
        <p class="mt-3 font-medium text-slate-900">{{ $order->recipient_name }} · {{ $order->recipient_phone }}</p>
        @if ($order->isPickup() && $order->pickupPoint)
          <p class="mt-2 text-slate-900">{{ $order->pickupPoint->name }}</p>
          <p class="text-slate-600">{{ $order->pickupPoint->address }}, {{ $order->pickupPoint->provinceName() }}</p>
          @if ($order->pickupPoint->opening_hours)<p class="text-slate-500">Giờ mở cửa: {{ $order->pickupPoint->opening_hours }}</p>@endif
          @if ($order->pickupPoint->phone)<p class="text-slate-500">Điện thoại: {{ $order->pickupPoint->phone }}</p>@endif
        @else
          <p class="mt-2 text-slate-600">{{ $order->shippingAddress() }}</p>
        @endif
        @if ($order->note)<p class="mt-3 text-slate-500">Ghi chú: {{ $order->note }}</p>@endif
      </section>
    </div>
  </div>
@endsection
