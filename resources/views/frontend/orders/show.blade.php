@extends('frontend.layouts.app')

{{-- Chi tiết đơn của tôi (S7): trạng thái, hướng dẫn chuyển khoản khi còn chờ thanh toán (mã QR động làm ở Step 6),
     sản phẩm, nhận hàng, tổng tiền. --}}
@php
    use App\Models\Order;
    use App\Support\Money;

    $status = $order->displayStatus();
    $canPay = $status === Order::STATUS_PENDING;
    $hasBank = $bank['code'] && $bank['account'];
@endphp
@section('title', 'Đơn '.$order->code)
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-4xl px-4 pt-8">
    <a href="{{ route('member.orders.index') }}" class="text-sm text-brand-700 hover:underline">‹ Đơn hàng của tôi</a>
    <div class="mt-2 flex flex-wrap items-center gap-3">
      <h1 class="text-2xl font-bold text-slate-900">Đơn <span class="font-mono">{{ $order->code }}</span></h1>
      @include('frontend.orders.status', ['status' => $status])
    </div>
    <p class="mt-1 text-sm text-slate-500">Đặt lúc {{ $order->created_at->format('d/m/Y H:i') }}</p>

    @if ($canPay)
      <section class="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 md:p-6" data-payment>
        <h2 class="font-semibold text-amber-900">Chuyển khoản để hoàn tất đơn hàng</h2>
        <p class="mt-1 text-sm text-amber-800">Hạn thanh toán: <strong>{{ $order->expires_at?->format('H:i d/m/Y') }}</strong>. Quá hạn đơn sẽ tự hủy.</p>
        @if ($hasBank)
          <dl class="mt-4 grid gap-x-6 gap-y-3 text-sm sm:grid-cols-[160px_minmax(0,1fr)]">
            <dt class="text-slate-600">Ngân hàng</dt><dd class="font-semibold text-slate-900">{{ $bank['code'] }}</dd>
            <dt class="text-slate-600">Số tài khoản</dt>
            <dd class="flex items-center gap-2 font-mono font-semibold text-slate-900">{{ $bank['account'] }} <button type="button" class="rounded border border-slate-300 bg-white px-2 py-0.5 font-sans text-xs font-normal hover:border-brand-500" data-copy="{{ $bank['account'] }}">Sao chép</button></dd>
            @if ($bank['name'])<dt class="text-slate-600">Chủ tài khoản</dt><dd class="font-semibold text-slate-900">{{ $bank['name'] }}</dd>@endif
            <dt class="text-slate-600">Số tiền</dt>
            <dd class="flex items-center gap-2 text-lg font-bold text-brand-700">{{ Money::format($order->total_amount) }}đ <button type="button" class="rounded border border-slate-300 bg-white px-2 py-0.5 text-xs font-normal text-slate-700 hover:border-brand-500" data-copy="{{ $order->total_amount }}">Sao chép</button></dd>
            <dt class="text-slate-600">Nội dung chuyển khoản</dt>
            <dd class="flex items-center gap-2 font-mono text-lg font-bold text-slate-900">{{ $order->code }} <button type="button" class="rounded border border-slate-300 bg-white px-2 py-0.5 font-sans text-xs font-normal hover:border-brand-500" data-copy="{{ $order->code }}">Sao chép</button></dd>
          </dl>
          <p class="mt-4 text-xs text-amber-800">Chuyển đúng số tiền và nội dung để hệ thống tự xác nhận.</p>
        @else
          <p class="mt-3 text-sm text-amber-900">Vui lòng liên hệ hotline @if ($site['site_hotline'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="font-semibold underline">{{ $site['site_hotline'] }}</a>@endif để được hướng dẫn thanh toán đơn <strong>{{ $order->code }}</strong>.</p>
        @endif
      </section>
    @elseif ($status === Order::STATUS_EXPIRED)
      <div class="mt-6 rounded-2xl border border-slate-200 bg-slate-50 p-5 text-sm text-slate-600">Đơn đã quá hạn thanh toán. Nếu bạn đã chuyển khoản, vui lòng liên hệ hotline để được hỗ trợ.</div>
    @elseif ($status === Order::STATUS_CANCELLED)
      <div class="mt-6 rounded-2xl border border-rose-200 bg-rose-50 p-5 text-sm text-rose-700">Đơn đã hủy{{ $order->cancel_reason ? ': '.$order->cancel_reason : '' }}.</div>
    @endif

    <div class="mt-6 grid gap-6 md:grid-cols-[minmax(0,1fr)_300px]">
      <section class="rounded-2xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-100 px-5 py-4 font-semibold text-slate-900">Sản phẩm</h2>
        <ul class="divide-y divide-slate-100">
          @foreach ($order->items as $item)
            <li class="flex gap-4 px-5 py-4">
              <span class="size-14 shrink-0 overflow-hidden rounded-lg bg-slate-100">@if ($item->product?->cover)<img src="{{ $item->product->cover->url() }}" alt="" class="size-full object-cover" />@endif</span>
              <div class="min-w-0 flex-1 text-sm">
                <div class="font-medium text-slate-900">{{ $item->product_name }}</div>
                <div class="text-slate-500">{{ Money::format($item->unit_price) }}đ × {{ $item->qty }}</div>
              </div>
              <div class="shrink-0 text-sm font-semibold">{{ Money::format($item->line_amount) }}đ</div>
            </li>
          @endforeach
        </ul>
        <dl class="space-y-2 border-t border-slate-100 px-5 py-4 text-sm">
          <div class="flex justify-between"><dt class="text-slate-500">Tiền hàng (gồm VAT {{ Money::format($order->vat_amount) }}đ)</dt><dd>{{ Money::format($order->goods_amount) }}đ</dd></div>
          <div class="flex justify-between"><dt class="text-slate-500">Phí giao hàng</dt><dd>{{ Money::format($order->shipping_fee) }}đ</dd></div>
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
