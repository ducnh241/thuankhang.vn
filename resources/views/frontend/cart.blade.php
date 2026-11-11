@extends('frontend.layouts.app')

{{-- Giỏ hàng (S4): sửa số lượng (tự gửi khi đổi), xóa dòng, tổng tạm tính, sang thanh toán. --}}
@php use App\Support\Money; @endphp
@section('title', 'Giỏ hàng')
@section('robots', 'noindex, nofollow')

@section('content')
  <div class="mx-auto max-w-5xl px-4 pt-8">
    <h1 class="text-2xl font-bold text-slate-900">Giỏ hàng</h1>

    @if ($lines->isEmpty())
      <div class="mt-6 rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center">
        <p class="text-slate-500">Giỏ hàng đang trống.</p>
        <a href="{{ route('home') }}" class="mt-4 inline-block rounded-full bg-brand-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Tiếp tục mua sắm</a>
      </div>
    @else
      <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div class="divide-y divide-slate-100 rounded-2xl border border-slate-200 bg-white">
          @foreach ($lines as $line)
            @php
                $product = $line['product'];
                $owner = $line['variant'] ?? $product; // nơi giữ tồn kho
                $slug = str_replace(':', '-', $line['key']);
            @endphp
            <div class="flex gap-4 p-4" data-cart-line="{{ $line['key'] }}">
              <a href="{{ $product->url() }}" class="size-20 shrink-0 overflow-hidden rounded-xl border border-slate-100 bg-white">
                @php $thumb = $line['variant']?->image ?? $product->cover; @endphp
                @if ($thumb)<img src="{{ $thumb->url() }}" alt="" class="size-full object-contain" />@endif
              </a>
              <div class="min-w-0 flex-1">
                <a href="{{ $product->url() }}" class="line-clamp-2 font-medium text-slate-900 hover:text-brand-700">{{ $product->name }}</a>
                @if ($line['variant'])<div class="mt-0.5 text-sm text-slate-600" data-cart-variant>{{ $product->variant_label ?: 'Phân loại' }}: <span class="font-medium">{{ $line['variant']->name }}</span></div>@endif
                <div class="mt-1 text-sm text-slate-500">{{ Money::format($line['unit_price']) }}đ</div>
                @if ($owner->tracksStock() && $line['qty'] > $owner->stock)
                  <div class="mt-1 text-xs font-medium text-rose-600" data-stock-warning>{{ $owner->stock > 0 ? 'Chỉ còn '.$owner->stock.' sản phẩm — giảm số lượng để đặt hàng' : 'Đã hết hàng — xóa khỏi giỏ để đặt hàng' }}</div>
                @endif
                <div class="mt-3 flex flex-wrap items-center gap-3">
                  <form method="POST" action="{{ route('shop.cart.update', $line['key']) }}" class="flex items-center gap-2">
                    @csrf
                    @method('PATCH')
                    <label for="qty-{{ $slug }}" class="sr-only">Số lượng</label>
                    <input id="qty-{{ $slug }}" type="number" name="qty" value="{{ $line['qty'] }}" min="1" max="{{ max(1, $owner->maxOrderQty()) }}" data-autosubmit
                      class="w-20 rounded-lg border border-slate-300 px-2 py-1.5 text-center text-sm outline-none focus:border-brand-500" />
                    <noscript><button type="submit" class="text-sm text-brand-700">Cập nhật</button></noscript>
                  </form>
                  <form method="POST" action="{{ route('shop.cart.destroy', $line['key']) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="text-sm text-slate-500 hover:text-rose-600">Xóa</button>
                  </form>
                </div>
              </div>
              <div class="shrink-0 text-right font-semibold text-slate-900">{{ Money::format($line['line_amount']) }}đ</div>
            </div>
          @endforeach
        </div>

        <aside class="h-fit rounded-2xl border border-slate-200 bg-white p-5">
          <div class="flex justify-between text-sm"><span class="text-slate-500">Tạm tính</span><span class="font-semibold text-slate-900" data-cart-subtotal>{{ Money::format($subtotal) }}đ</span></div>
          <p class="mt-2 text-xs text-slate-500">Giá đã gồm VAT. Phí giao hàng tính ở bước thanh toán (nhận tại VP tỉnh: miễn phí).</p>
          <a href="{{ route('shop.checkout') }}" class="mt-5 block rounded-full bg-brand-600 px-6 py-3 text-center text-sm font-semibold text-white shadow hover:bg-brand-700">Thanh toán</a>
          <a href="{{ route('home') }}" class="mt-3 block text-center text-sm text-brand-700 hover:underline">Tiếp tục mua sắm</a>
        </aside>
      </div>
    @endif
  </div>
@endsection
