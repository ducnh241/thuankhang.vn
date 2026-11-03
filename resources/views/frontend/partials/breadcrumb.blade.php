{{-- Đường dẫn: Trang chủ › danh mục cha › … Biến: $trail (danh mục), $current (chữ cuối, tuỳ chọn). --}}
<nav aria-label="Đường dẫn" class="text-sm text-slate-500">
  <ol class="flex flex-wrap items-center gap-1.5">
    <li><a href="{{ route('home') }}" class="hover:text-brand-700">Trang chủ</a></li>
    @foreach ($trail as $item)
      <li aria-hidden="true">›</li>
      @if ($loop->last && empty($current))
        <li class="font-medium text-slate-800" aria-current="page">{{ $item->name }}</li>
      @else
        <li><a href="{{ $item->url() }}" class="hover:text-brand-700">{{ $item->name }}</a></li>
      @endif
    @endforeach
    @if (! empty($current))
      <li aria-hidden="true">›</li>
      <li class="line-clamp-1 font-medium text-slate-800" aria-current="page">{{ $current }}</li>
    @endif
  </ol>
</nav>
