{{-- Logo website. Biến: $siteName, $logo (ảnh logo trong Cài đặt website, có thể null), $size = header | drawer | footer.
     Không có ảnh: ô chữ cái đầu (nền chuyển màu thương hiệu, bóng đổ) + tên; phần đuôi sau dấu chấm cuối (VD ".vn")
     tô màu thương hiệu. --}}
@php
    $size ??= 'header';
    $styles = [
        // Điện thoại: giới hạn bề ngang để còn chỗ cho ☰ / giỏ / tài khoản (ảnh thu nhỏ theo tỉ lệ)
        'header' => ['img' => 'h-8 max-w-[46vw] object-contain object-left md:h-[43px] md:max-w-none', 'mark' => 'size-10 rounded-xl text-xl md:size-12 md:rounded-2xl md:text-2xl', 'name' => 'text-lg sm:text-xl md:text-[26px]', 'gap' => 'gap-2.5'],
        'drawer' => ['img' => 'h-10', 'mark' => 'size-10 rounded-xl text-xl', 'name' => 'text-xl', 'gap' => 'gap-2.5'],
        'footer' => ['img' => 'h-12', 'mark' => 'size-11 rounded-xl text-xl', 'name' => 'text-2xl', 'gap' => 'gap-3'],
    ][$size];
    $dot = mb_strrpos($siteName, '.');
    [$main, $tail] = $dot !== false && $dot > 0 ? [mb_substr($siteName, 0, $dot), mb_substr($siteName, $dot)] : [$siteName, ''];
@endphp
@if ($logo)
  <img src="{{ $logo }}" alt="{{ $siteName }}" class="{{ $styles['img'] }} w-auto" />
@else
  <span class="flex min-w-0 items-center {{ $styles['gap'] }}">
    <span class="flex shrink-0 items-center justify-center bg-gradient-to-br from-brand-500 to-brand-700 font-extrabold text-white shadow-md shadow-brand-600/30 ring-1 ring-white/40 ring-inset {{ $styles['mark'] }}" aria-hidden="true">{{ mb_strtoupper(mb_substr($siteName, 0, 1)) }}</span>
    <span class="truncate leading-none font-extrabold tracking-tight text-slate-900 {{ $styles['name'] }}">{{ $main }}@if ($tail !== '')<span class="text-brand-600">{{ $tail }}</span>@endif</span>
  </span>
@endif
