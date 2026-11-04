@extends('frontend.layouts.app')

{{-- Chi tiết sản phẩm (S2): ảnh (bấm ảnh nhỏ đổi ảnh chính), giá đã gồm VAT, mô tả ngắn, liên hệ đặt hàng, mô tả chi tiết
     (đã lọc HTML), sản phẩm cùng danh mục. Giỏ hàng / nút "Thêm vào giỏ" làm ở Step 5; nút "Sao chép link giới thiệu sản
     phẩm" cho thành viên đã kích hoạt làm ở Step 3. --}}
@php
    use App\Support\Money;
    use App\Support\Percent;
    use App\Support\RichText;

    $images = $product->images;
    $cover = $images->first()?->url();
    $hotline = $site['site_hotline'] ?? '';
    $description = RichText::clean($product->description);
@endphp

@section('title', $product->name)
@section('description', (string) $product->short_description)
@section('image', (string) $cover)

@if ($preview)
  @section('robots', 'noindex, nofollow')
@endif

@section('content')
  @if ($preview)
    {{-- Xem trước: sản phẩm đang ẩn, người xem có quyền sản phẩm --}}
    <div class="border-b border-amber-200 bg-amber-50 text-sm text-amber-900" data-preview-banner>
      <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
        <span class="font-semibold">Đang xem trước</span>
        <span>Sản phẩm đang ẩn với khách (tắt "Đang bán" hoặc danh mục đang ẩn) — khách mở link này sẽ báo không tìm thấy; bạn xem được vì đang đăng nhập quản trị.</span>
        <span class="ml-auto flex gap-3">
          @if ($preview['editUrl'])<a href="{{ $preview['editUrl'] }}" class="font-medium underline underline-offset-2">Sửa sản phẩm</a>@endif
        </span>
      </div>
    </div>
  @endif
  <div class="mx-auto max-w-7xl px-4 pt-6">
    @include('frontend.partials.breadcrumb', ['trail' => $trail, 'current' => $product->name])

    <div class="mt-6 grid gap-8 lg:grid-cols-2">
      {{-- Ảnh --}}
      <div>
        <div class="aspect-square overflow-hidden rounded-2xl border border-slate-200 bg-white">
          @if ($cover)
            <img src="{{ $cover }}" alt="{{ $product->name }}" class="size-full object-cover" data-gallery-main />
          @else
            <div class="flex size-full items-center justify-center text-slate-300">Chưa có ảnh</div>
          @endif
        </div>
        @if ($images->count() > 1)
          <div class="mt-3 grid grid-cols-5 gap-3">
            @foreach ($images as $image)
              <button type="button" class="overflow-hidden rounded-xl border-2 border-transparent bg-white aria-[current=true]:border-brand-500" data-gallery-thumb
                data-src="{{ $image->url() }}" data-alt="{{ $product->name }}" aria-current="{{ $loop->first ? 'true' : 'false' }}" aria-label="Xem ảnh {{ $loop->iteration }}">
                <img src="{{ $image->url() }}" alt="" loading="lazy" class="aspect-square w-full object-cover" />
              </button>
            @endforeach
          </div>
        @endif
      </div>

      {{-- Thông tin --}}
      <div>
        <h1 class="text-2xl leading-snug font-bold text-slate-900 md:text-3xl">{{ $product->name }}</h1>
        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
          @if ($product->sku)<span>Mã: <span class="font-medium text-slate-700">{{ $product->sku }}</span></span>@endif
          @if ($product->category)<span>Danh mục: <a href="{{ $product->category->url() }}" class="font-medium text-brand-700 hover:underline">{{ $product->category->name }}</a></span>@endif
        </div>

        <div class="mt-5 rounded-2xl bg-brand-50 p-5">
          <div class="text-3xl font-bold text-brand-700">{{ Money::format($product->price) }}đ</div>
          <div class="mt-1 text-xs text-slate-500">Giá đã gồm VAT {{ Percent::format($product->vat_rate) }}%, chưa gồm phí giao hàng</div>
        </div>

        @if ($product->short_description)
          <p class="mt-5 text-[15px] leading-7 text-slate-700">{{ $product->short_description }}</p>
        @endif

        {{-- Thêm vào giỏ / Mua ngay (buy_now=1 → sang thanh toán) --}}
        <form method="POST" action="{{ route('shop.cart.store') }}" class="mt-6" data-buy-form>
          @csrf
          <input type="hidden" name="product_id" value="{{ $product->id }}" />
          <div class="flex flex-wrap items-center gap-3">
            <label for="buy-qty" class="text-sm font-medium text-slate-700">Số lượng</label>
            <input id="buy-qty" type="number" name="qty" value="1" min="1" max="{{ \App\Support\Cart::MAX_QTY }}"
              class="w-24 rounded-lg border border-slate-300 px-3 py-2 text-center text-sm outline-none focus:border-brand-500 focus:ring-2 focus:ring-brand-100" />
          </div>
          <div class="mt-4 flex flex-col gap-3 sm:flex-row">
            <button type="submit" class="flex-1 rounded-full border-2 border-brand-600 px-6 py-3 text-sm font-semibold text-brand-700 hover:bg-brand-50">Thêm vào giỏ</button>
            <button type="submit" name="buy_now" value="1" class="flex-1 rounded-full bg-brand-600 px-6 py-3 text-sm font-semibold text-white shadow hover:bg-brand-700">Mua ngay</button>
          </div>
        </form>
        @if ($hotline)
          <p class="mt-3 text-sm text-slate-500">Cần tư vấn? Gọi <a href="tel:{{ preg_replace('/[^0-9+]/', '', $hotline) }}" class="font-semibold text-brand-700 hover:underline">{{ $hotline }}</a></p>
        @endif
        <ul class="mt-6 space-y-2 text-sm text-slate-600">
          <li class="flex gap-2"><span class="text-brand-600">✓</span>Thanh toán chuyển khoản qua mã QR</li>
          <li class="flex gap-2"><span class="text-brand-600">✓</span>Giao tận nơi hoặc nhận tại văn phòng tỉnh gần bạn</li>
        </ul>
      </div>
    </div>

    @if ($description !== '')
      <section class="mt-12 rounded-2xl border border-slate-200 bg-white p-6 md:p-8">
        <h2 class="text-lg font-bold text-slate-900">Mô tả sản phẩm</h2>
        <div class="rich-text mt-4">{!! $description !!}</div>
      </section>
    @endif

    @if ($related->isNotEmpty())
      <section class="mt-12">
        <h2 class="text-xl font-bold text-slate-900">Sản phẩm cùng danh mục</h2>
        <div class="mt-5 grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
          @foreach ($related as $item)
            @include('frontend.partials.product-card', ['product' => $item])
          @endforeach
        </div>
      </section>
    @endif
  </div>
@endsection
