{{-- Thông báo sau thao tác (session success / error). --}}
@if (session('success') || session('error'))
  <div class="mx-auto max-w-7xl px-4 pt-4">
    @if (session('success'))
      <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-xl border border-brand-100 bg-brand-50 px-4 py-3 text-sm text-brand-800" role="status">
        <span>{{ session('success') }}</span>
        {{-- Vừa thêm vào giỏ: lối đi tiếp sang giỏ hàng / thanh toán --}}
        @if (session('cart_added'))
          <span class="flex shrink-0 items-center gap-2" data-cart-added>
            <a href="{{ route('shop.cart') }}" class="rounded-full border border-brand-600 px-3 py-1 font-semibold text-brand-700 hover:bg-white">Xem giỏ hàng</a>
            <a href="{{ route('shop.checkout') }}" class="rounded-full bg-brand-600 px-3 py-1 font-semibold text-white hover:bg-brand-700">Thanh toán</a>
          </span>
        @endif
      </div>
    @endif
    @if (session('error'))
      <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700" role="alert">{{ session('error') }}</div>
    @endif
  </div>
@endif
