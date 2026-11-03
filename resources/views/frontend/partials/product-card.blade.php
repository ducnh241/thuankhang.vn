{{-- Thẻ sản phẩm trong lưới (trang chủ, danh mục, tìm kiếm, sản phẩm liên quan). Biến: $product (đã nạp cover). --}}
@php $cover = $product->cover?->url(); @endphp
<a href="{{ $product->url() }}" class="group flex flex-col overflow-hidden rounded-2xl border border-slate-200 bg-white transition hover:-translate-y-0.5 hover:border-brand-500 hover:shadow-lg" data-product="{{ $product->id }}">
  <div class="relative aspect-square overflow-hidden bg-slate-100">
    @if ($cover)
      <img src="{{ $cover }}" alt="{{ $product->name }}" loading="lazy" class="size-full object-cover transition duration-300 group-hover:scale-105" />
    @else
      <div class="flex size-full items-center justify-center text-slate-300">
        <svg class="size-12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2" /><circle cx="9" cy="10" r="2" /><path d="m21 16-5-5-8 9" /></svg>
      </div>
    @endif
    @if ($product->is_featured)
      <span class="absolute top-3 left-3 rounded-full bg-amber-400 px-2.5 py-0.5 text-xs font-semibold text-amber-950">Nổi bật</span>
    @endif
  </div>
  <div class="flex flex-1 flex-col gap-1 p-4">
    <h3 class="line-clamp-2 text-sm font-medium text-slate-900 group-hover:text-brand-700">{{ $product->name }}</h3>
    <div class="mt-auto pt-2 text-base font-bold text-brand-700">{{ \App\Support\Money::format($product->price) }}đ</div>
  </div>
</a>
