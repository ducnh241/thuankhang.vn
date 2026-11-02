{{-- Avatar tròn như template (src/js/users.js avatarHtml): ảnh của user; chưa có ảnh thì 2 chữ cái đầu trên nền xanh nhạt
     (.user-initials), hoặc ảnh người mặc định của template nếu $placeholder (avatar đầu sidebar).
     Tham số: $user, $size (sm 24px | default 32px | lg 40px | xl 64px), $placeholder (bool, mặc định false) --}}
@php
    $size = $size ?? 'default';
    $sizeClass = ['sm' => ' ant-avatar-sm', 'lg' => ' ant-avatar-lg'][$size] ?? '';
    $style = $size === 'xl' ? ' style="width: 64px; height: 64px; line-height: 64px; font-size: 22px"' : '';
    $src = $user->avatarUrl() ?: (($placeholder ?? false) ? asset('assets/admin/img/avatar.svg') : null);
@endphp
@if ($src)
<span class="ant-avatar ant-avatar-circle ant-avatar-image{{ $sizeClass }}"{!! $style !!}><img src="{{ $src }}" alt="" /></span>
@else
<span class="ant-avatar ant-avatar-circle user-initials{{ $sizeClass }}"{!! $style !!}>{{ $user->initials() }}</span>
@endif
