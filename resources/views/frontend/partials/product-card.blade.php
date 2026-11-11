{{-- Thẻ sản phẩm trong lưới (trang chủ, danh mục, tìm kiếm, sản phẩm liên quan), theo kiểu Shoplife: ảnh, tên, lượt bán,
     giá, nút "+" thêm nhanh 1 sản phẩm vào giỏ (form riêng, không lồng trong link). Biến: $product (nạp qua scope forCard). --}}
@php
    $cover = $product->cover?->url();
    $sold = (int) ($product->sold_qty ?? 0);
    $ratingCount = (int) ($product->rating_count ?? 0);
    $soldOut = $product->isOutOfStock();
    $hasVariants = $product->hasVariants();
    [$minPrice, $maxPrice] = $product->priceRange();
@endphp
<div class="group relative flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-lg" data-product="{{ $product->id }}">
  <a href="{{ $product->url() }}" class="relative block aspect-square overflow-hidden bg-white" tabindex="-1" aria-hidden="true">
    @if ($cover)
      <img src="{{ $cover }}" alt="" loading="lazy" class="size-full object-contain transition duration-300 group-hover:scale-105" />
    @else
      <span class="flex size-full items-center justify-center text-slate-300">
        <svg class="size-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="m21 16-5-5-8 9" /></svg>
      </span>
    @endif
    @if ($soldOut)
      <span class="absolute inset-0 flex items-center justify-center bg-white/55"><span class="rounded-full bg-slate-900/80 px-3 py-1 text-xs font-semibold text-white">Hết hàng</span></span>
    @endif
    @if ($product->is_featured)
      <span class="absolute top-2.5 left-2.5 rounded-full bg-amber-400 px-2.5 py-0.5 text-xs font-semibold text-amber-950">Nổi bật</span>
    @endif
  </a>
  <div class="flex flex-1 flex-col p-3 md:p-4">
    <h3 class="line-clamp-2 min-h-10 text-sm font-medium text-slate-900"><a href="{{ $product->url() }}" class="hover:text-brand-700">{{ $product->name }}</a></h3>
    <div class="mt-1 flex items-center gap-2 text-xs text-slate-500">
      @if ($ratingCount)<span class="flex items-center gap-0.5 font-medium text-amber-600" data-rating><span aria-hidden="true">★</span>{{ number_format((float) $product->rating_avg, 1, ',', '') }}</span>@endif
      <span data-sold>{{ $sold > 0 ? 'Đã bán '.\App\Support\Money::format($sold) : 'Mới' }}</span>
    </div>
    <div class="mt-auto flex items-end justify-between gap-2 pt-2">
      <span class="text-base font-bold text-brand-700">@if ($minPrice !== $maxPrice)<span class="text-xs font-medium text-slate-500">Từ </span>@endif{{ \App\Support\Money::format($minPrice) }}đ</span>
      @if ($hasVariants && ! $soldOut)
        {{-- Có biến thể: phải chọn ở trang sản phẩm --}}
        <a href="{{ $product->url() }}" class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white shadow-sm transition hover:bg-brand-700" aria-label="Chọn {{ mb_strtolower($product->variant_label ?: 'phân loại') }} cho {{ $product->name }}" data-choose-variant>
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
        </a>
      @else
      <form method="POST" action="{{ route('shop.cart.store') }}">
        @csrf
        <input type="hidden" name="product_id" value="{{ $product->id }}" />
        <button type="submit" @disabled($soldOut) class="flex size-8 shrink-0 disabled:cursor-not-allowed disabled:bg-slate-300 items-center justify-center rounded-full bg-brand-600 text-white shadow-sm transition hover:bg-brand-700 focus-visible:ring-2 focus-visible:ring-brand-300 focus-visible:outline-none" aria-label="{{ $soldOut ? $product->name.' đã hết hàng' : 'Thêm '.$product->name.' vào giỏ' }}" data-quick-add>
          <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path stroke-linecap="round" d="M12 5v14M5 12h14" /></svg>
        </button>
      </form>
      @endif
    </div>
  </div>
</div>
