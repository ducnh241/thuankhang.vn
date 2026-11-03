{{-- Phân trang website. Dùng: $paginator->links('frontend.partials.pagination') --}}
@if ($paginator->hasPages())
  <nav class="mt-10 flex flex-wrap items-center justify-center gap-1.5 text-sm" aria-label="Phân trang">
    @if ($paginator->onFirstPage())
      <span class="rounded-lg px-3 py-2 text-slate-300">‹ Trước</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-white hover:text-brand-700">‹ Trước</a>
    @endif
    @foreach ($elements as $element)
      @if (is_string($element))
        <span class="px-2 text-slate-400">…</span>
      @else
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="min-w-10 rounded-lg bg-brand-600 px-3 py-2 text-center font-semibold text-white" aria-current="page">{{ $page }}</span>
          @else
            <a href="{{ $url }}" class="min-w-10 rounded-lg px-3 py-2 text-center text-slate-600 hover:bg-white hover:text-brand-700">{{ $page }}</a>
          @endif
        @endforeach
      @endif
    @endforeach
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="rounded-lg px-3 py-2 text-slate-600 hover:bg-white hover:text-brand-700">Sau ›</a>
    @else
      <span class="rounded-lg px-3 py-2 text-slate-300">Sau ›</span>
    @endif
  </nav>
@endif
