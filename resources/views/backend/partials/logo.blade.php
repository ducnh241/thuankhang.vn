{{-- Logo thương hiệu: ảnh từ config('app.logo') (APP_LOGO); không có ảnh hoặc ảnh lỗi thì hiện chữ cái đầu tên app.
     Tham số: $class (kích thước + bo góc, vd "size-8 rounded-md"), $textClass (cỡ chữ cái thay thế, mặc định text-base) --}}
@php
    $logo = (string) config('app.logo');
    $logoSrc = $logo !== '' && ! preg_match('#^(https?:)?//#', $logo) ? asset($logo) : $logo;
@endphp
<span class="relative inline-flex shrink-0 items-center justify-center overflow-hidden bg-white {{ $class ?? 'size-6 rounded-md' }}">
  @if ($logoSrc !== '')
    <img src="{{ $logoSrc }}" alt="{{ config('app.name') }}" class="size-full object-cover" onerror="this.hidden = true; this.nextElementSibling.hidden = false" />
  @endif
  <span class="{{ $textClass ?? 'text-base' }} font-semibold text-[#007bff]" @if ($logoSrc !== '') hidden @endif>{{ mb_strtoupper(mb_substr((string) config('app.name'), 0, 1)) }}</span>
</span>
