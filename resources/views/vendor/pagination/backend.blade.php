{{-- Footer bảng theo product-list.html: tóm tắt + "Trang x / y" + nút trước/sau --}}
@php
    $btn = "group/button inline-flex shrink-0 items-center justify-center gap-2 rounded-lg border font-medium whitespace-nowrap transition-all outline-none select-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50 active:translate-y-px disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0 [&_svg:not([class*='size-'])]:size-4 h-8 px-2.5 text-sm border-border bg-background hover:bg-muted hover:text-foreground size-8 px-0";
@endphp
<div class="flex flex-wrap items-center justify-between gap-3 px-(--card-spacing)">
  <p class="text-sm text-muted-foreground">
    @if ($paginator->total())
      Hiển thị {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} / {{ $paginator->total() }}
    @else
      0 kết quả
    @endif
  </p>
  <div class="flex items-center gap-2">
    <span class="text-sm text-muted-foreground">Trang {{ $paginator->currentPage() }} / {{ max($paginator->lastPage(), 1) }}</span>
    @if ($paginator->onFirstPage())
      <button type="button" class="{{ $btn }}" aria-label="Trang trước" disabled><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg></button>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $btn }}" aria-label="Trang trước"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"></path></svg></a>
    @endif
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $btn }}" aria-label="Trang sau"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg></a>
    @else
      <button type="button" class="{{ $btn }}" aria-label="Trang sau" disabled><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 18 6-6-6-6"></path></svg></button>
    @endif
  </div>
</div>
