@extends('frontend.layouts.app')

{{-- Thanh toán (S5): người nhận, hình thức nhận (giao tận nơi: tỉnh + địa chỉ, phí ship COD — không hiện giá; nhận tại VP: tỉnh → điểm,
     hiện địa chỉ / giờ mở cửa, miễn phí), ghi chú, tóm tắt đơn, nút "Thanh toán"
     tạo đơn rồi sang trang đơn có thông tin chuyển khoản + mã QR. JS: [data-checkout] trong frontend/js/app.js.
     Hình thức thanh toán: chuyển khoản, hoặc ví tái tiêu dùng (chỉ chọn được khi số dư ≥ tổng đơn — trừ ví, đơn thanh toán
     ngay). Đủ đơn chưa thanh toán thì chỉ còn trả bằng ví. --}}
@php
    use App\Models\Order;
    use App\Models\PickupPoint;
    use App\Support\Money;

    $delivery = old('delivery_type', Order::DELIVERY_SHIP);
    $walletEnough = $reconsume >= $subtotal;
    $payment = old('payment_method', $transferBlocked ? Order::PAYMENT_WALLET : Order::PAYMENT_TRANSFER);
    $pointId = (string) old('pickup_point_id', '');
    $pickupProvince = (string) old('pickup_province', $points->firstWhere('id', (int) $pointId)?->province ?? '');
    $error = fn (string $field) => $errors->has($field) ? '<p class="mt-1 text-sm text-rose-600">'.e($errors->first($field)).'</p>' : '';
    $input = 'w-full rounded-lg border bg-white px-3 py-2.5 text-sm outline-none transition focus:ring-2';
    $inputState = fn (string $field) => $errors->has($field) ? 'border-rose-400 focus:border-rose-500 focus:ring-rose-100' : 'border-slate-300 focus:border-brand-500 focus:ring-brand-100';
@endphp
@section('title', 'Thanh toán')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-5xl px-4 pt-8">
    <h1 class="text-2xl font-bold text-slate-900">Thanh toán</h1>

    <form method="POST" action="{{ route('shop.checkout.store') }}" class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]" novalidate
      data-checkout data-points="{{ $points->toJson() }}">
      @csrf
      <div class="space-y-6">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
          <h2 class="font-semibold text-slate-900">Người nhận</h2>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div>
              <label for="recipient_name" class="mb-1.5 block text-sm font-medium text-slate-700">Họ tên <span class="text-rose-500">*</span></label>
              <input id="recipient_name" name="recipient_name" value="{{ old('recipient_name', $member->full_name) }}" maxlength="100" autocomplete="name" class="{{ $input }} {{ $inputState('recipient_name') }}" />
              {!! $error('recipient_name') !!}
            </div>
            <div>
              <label for="recipient_phone" class="mb-1.5 block text-sm font-medium text-slate-700">Số điện thoại <span class="text-rose-500">*</span></label>
              <input id="recipient_phone" name="recipient_phone" type="tel" value="{{ old('recipient_phone', $member->phone) }}" maxlength="20" autocomplete="tel" class="{{ $input }} {{ $inputState('recipient_phone') }}" />
              {!! $error('recipient_phone') !!}
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
          <h2 class="font-semibold text-slate-900">Hình thức nhận hàng</h2>
          <div class="mt-4 grid gap-3 sm:grid-cols-2" role="radiogroup">
            <label class="flex cursor-pointer gap-3 rounded-xl border border-slate-300 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50">
              <input type="radio" name="delivery_type" value="{{ Order::DELIVERY_SHIP }}" class="mt-1 accent-brand-600" @checked($delivery === Order::DELIVERY_SHIP) data-delivery />
              <span><span class="block font-medium text-slate-900">Giao tận nơi</span><span class="block text-sm text-slate-500">Phí ship COD, thanh toán khi nhận hàng</span></span>
            </label>
            <label @class(['flex gap-3 rounded-xl border border-slate-300 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'cursor-pointer' => $points->isNotEmpty(), 'opacity-50' => $points->isEmpty()])>
              <input type="radio" name="delivery_type" value="{{ Order::DELIVERY_PICKUP }}" class="mt-1 accent-brand-600" @checked($delivery === Order::DELIVERY_PICKUP) @disabled($points->isEmpty()) data-delivery />
              <span><span class="block font-medium text-slate-900">Nhận tại VP tỉnh</span><span class="block text-sm text-slate-500">{{ $points->isEmpty() ? 'Chưa có điểm nhận hàng' : 'Miễn phí giao hàng' }}</span></span>
            </label>
          </div>
          {!! $error('delivery_type') !!}

          {{-- Giao tận nơi --}}
          <div class="mt-5 grid gap-4 sm:grid-cols-[220px_minmax(0,1fr)]" data-delivery-panel="{{ Order::DELIVERY_SHIP }}" @if ($delivery !== Order::DELIVERY_SHIP) hidden @endif>
            <div>
              <label for="province" class="mb-1.5 block text-sm font-medium text-slate-700">Tỉnh / thành <span class="text-rose-500">*</span></label>
              <select id="province" name="province" class="{{ $input }} {{ $inputState('province') }}">
                <option value="">Chọn tỉnh / thành</option>
                @foreach (PickupPoint::provinces() as $key => $name)
                  <option value="{{ $key }}" @selected(old('province') === $key)>{{ $name }}</option>
                @endforeach
              </select>
              {!! $error('province') !!}
            </div>
            <div>
              <label for="address" class="mb-1.5 block text-sm font-medium text-slate-700">Địa chỉ <span class="text-rose-500">*</span></label>
              <input id="address" name="address" value="{{ old('address') }}" maxlength="300" autocomplete="street-address" placeholder="Số nhà, đường, phường / xã" class="{{ $input }} {{ $inputState('address') }}" />
              {!! $error('address') !!}
            </div>
          </div>

          {{-- Nhận tại VP tỉnh: chọn tỉnh → điểm --}}
          <div class="mt-5 grid gap-4 sm:grid-cols-[220px_minmax(0,1fr)]" data-delivery-panel="{{ Order::DELIVERY_PICKUP }}" @if ($delivery !== Order::DELIVERY_PICKUP) hidden @endif>
            <div>
              <label for="pickup_province" class="mb-1.5 block text-sm font-medium text-slate-700">Tỉnh / thành</label>
              <select id="pickup_province" name="pickup_province" class="{{ $input }} border-slate-300" data-pickup-province>
                <option value="">Chọn tỉnh / thành</option>
                @foreach ($pointProvinces as $key => $name)
                  <option value="{{ $key }}" @selected($pickupProvince === $key)>{{ $name }}</option>
                @endforeach
              </select>
            </div>
            <div>
              <label for="pickup_point_id" class="mb-1.5 block text-sm font-medium text-slate-700">Điểm nhận hàng <span class="text-rose-500">*</span></label>
              <select id="pickup_point_id" name="pickup_point_id" class="{{ $input }} {{ $inputState('pickup_point_id') }}" data-pickup-point>
                <option value="">Chọn điểm nhận hàng</option>
                @foreach ($points as $point)
                  <option value="{{ $point->id }}" data-province="{{ $point->province }}" @selected($pointId === (string) $point->id) @if ($pickupProvince && $pickupProvince !== $point->province) hidden disabled @endif>{{ $point->name }}</option>
                @endforeach
              </select>
              {!! $error('pickup_point_id') !!}
              <div class="mt-2 rounded-lg bg-slate-50 p-3 text-sm text-slate-600" data-pickup-info hidden></div>
            </div>
          </div>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6" data-payment-methods>
          <h2 class="font-semibold text-slate-900">Hình thức thanh toán</h2>
          <div class="mt-4 grid gap-3 sm:grid-cols-2" role="radiogroup">
            <label @class(['flex gap-3 rounded-xl border border-slate-300 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'cursor-pointer' => ! $transferBlocked, 'opacity-50' => $transferBlocked])>
              <input type="radio" name="payment_method" value="{{ Order::PAYMENT_TRANSFER }}" class="mt-1 accent-brand-600" @checked($payment === Order::PAYMENT_TRANSFER) @disabled($transferBlocked) />
              <span>
                <span class="block font-medium text-slate-900">Chuyển khoản</span>
                <span class="block text-sm text-slate-500">{{ $transferBlocked ? 'Bạn đang có '.Order::MAX_OPEN_UNPAID.' đơn chưa thanh toán' : 'Quét mã QR, tải ảnh bill — kế toán xác nhận' }}</span>
              </span>
            </label>
            {{-- Đủ / thiếu ví đổi theo tổng đơn khi khách đổi số lượng (JS [data-checkout]: data-reconsume) --}}
            <label @class(['flex gap-3 rounded-xl border border-slate-300 p-4 has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50', 'cursor-pointer' => $walletEnough, 'opacity-60' => ! $walletEnough]) data-pay-wallet data-reconsume="{{ $reconsume }}">
              <input type="radio" name="payment_method" value="{{ Order::PAYMENT_WALLET }}" class="mt-1 accent-brand-600" @checked($payment === Order::PAYMENT_WALLET && $walletEnough) @disabled(! $walletEnough) />
              <span>
                <span class="block font-medium text-slate-900">Ví tái tiêu dùng</span>
                <span class="block text-sm text-slate-500">Số dư: <strong class="tabular-nums text-slate-700" data-wallet-reconsume>{{ Money::format($reconsume) }}đ</strong></span>
                <span class="block text-xs text-slate-500" data-wallet-ok{{ $walletEnough ? '' : ' hidden' }}>Trừ ví ngay, đơn được thanh toán luôn.</span>
                <span class="block text-xs text-rose-600" data-wallet-short{{ $walletEnough ? ' hidden' : '' }}>Còn thiếu <span data-wallet-short-amount>{{ Money::format(max(0, $subtotal - $reconsume)) }}đ</span>.
                  <a href="{{ route('member.topups.index') }}" class="font-medium text-brand-700 underline">Nạp tiền vào ví</a></span>
              </span>
            </label>
          </div>
          {!! $error('payment_method') !!}
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 md:p-6">
          <label for="note" class="block font-semibold text-slate-900">Ghi chú</label>
          <textarea id="note" name="note" rows="2" maxlength="500" placeholder="VD: Giao giờ hành chính" class="mt-3 {{ $input }} {{ $inputState('note') }}">{{ old('note') }}</textarea>
          {!! $error('note') !!}
        </section>
      </div>

      <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Đơn hàng (<span data-summary-count>{{ $lines->sum('qty') }}</span> sản phẩm)</h2>
        {{-- Đổi số lượng ngay tại đây (nút −/+): cập nhật giỏ bằng fetch, không tải lại trang — các ô đã nhập ở bên trái giữ nguyên --}}
        <ul class="mt-4 divide-y divide-slate-100 text-sm">
          @foreach ($lines as $line)
            @php $label = $line['product']->name.($line['variant'] ? ' — '.$line['variant']->name : ''); @endphp
            <li class="py-3 first:pt-0" data-cart-line="{{ $line['key'] }}">
              <span class="line-clamp-2 text-slate-700">{{ $label }}</span>
              <span class="block text-xs text-slate-400">{{ Money::format($line['unit_price']) }}đ</span>
              <div class="mt-2 flex items-center justify-between gap-3" data-cart-qty data-url="{{ route('shop.cart.update', $line['key']) }}">
                @include('frontend.partials.qty-stepper', ['id' => 'checkout-qty-'.str_replace(':', '-', $line['key']), 'name' => null, 'value' => $line['qty'],
                    'max' => ($line['variant'] ?? $line['product'])->maxOrderQty(), 'label' => $label])
                <span class="shrink-0 font-medium tabular-nums" data-line-total>{{ Money::format($line['line_amount']) }}đ</span>
              </div>
              <p class="mt-1 text-xs font-medium text-rose-600" data-line-message hidden></p>
            </li>
          @endforeach
        </ul>
        <dl class="mt-4 space-y-2 border-t border-slate-100 pt-4 text-sm">
          <div class="flex justify-between text-base"><dt class="font-semibold text-slate-900">Tổng thanh toán</dt><dd class="font-bold text-brand-700" data-summary-total>{{ Money::format($subtotal) }}đ</dd></div>
        </dl>
        <button type="submit" class="mt-5 w-full rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700" data-checkout-submit>Thanh toán</button>
        <p class="mt-3 text-xs text-slate-500">Chuyển khoản: bấm Thanh toán để nhận thông tin chuyển khoản và mã QR, chuyển xong bấm “Tôi đã chuyển khoản”. Ví tái tiêu dùng: bấm Thanh toán là trừ ví và hoàn tất đơn.</p>
        <a href="{{ route('shop.cart') }}" class="mt-3 block text-center text-sm text-brand-700 hover:underline">Quay lại giỏ hàng</a>
      </aside>
    </form>
  </div>
@endsection
