{{-- Logo thương hiệu: ảnh từ config('app.logo') (APP_LOGO), hiện nguyên ảnh, không nền / bo góc (như logo đầu sidebar của
     template Admin Pro); không có ảnh hoặc ảnh lỗi thì hiện chữ cái đầu tên app trên ô trắng bo góc.
     Tham số: $class (kích thước, vd "size-8"), $textClass (cỡ chữ cái thay thế, mặc định text-base) --}}
@php
    $logo = (string) config('app.logo');
    $logoSrc = $logo !== '' && ! preg_match('#^(https?:)?//#', $logo) ? asset($logo) : $logo;
@endphp
<span class="app-logo {{ $class ?? 'size-6' }}">
  @if ($logoSrc !== '')
    <img src="{{ $logoSrc }}" alt="{{ config('app.name') }}" onerror="this.hidden = true; this.nextElementSibling.hidden = false" />
  @endif
  <span class="app-logo-letter {{ $textClass ?? 'text-base' }}" @if ($logoSrc !== '') hidden @endif>{{ mb_strtoupper(mb_substr((string) config('app.name'), 0, 1)) }}</span>
</span>
