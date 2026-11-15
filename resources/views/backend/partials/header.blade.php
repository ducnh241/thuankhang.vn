{{-- Header theo layout Admin Pro của template (nhánh admin-pro: src/partials/pro-header.html): thanh trắng cao 48px ở đầu
     vùng nội dung. Bên trái là nút thu gọn / mở sidebar (trên mobile < 992px: logo + nút mở sidebar dạng drawer), bên phải
     là avatar + tên người đang đăng nhập, bấm mở menu tài khoản (partials/account-menu). --}}
@php
    $user = auth()->user();
    $collapsed = request()->cookie('backend_sider') === 'collapsed';
@endphp
<header class="app-header">
  <a class="app-header-logo lg:hidden" href="{{ route('backend.dashboard') }}" title="{{ config('app.name') }}"
    >@include('backend.partials.logo', ['class' => 'size-7', 'textClass' => 'text-base'])</a
  >
  <button type="button" class="app-header-toggle" data-sider-toggle aria-controls="app-sidebar" aria-expanded="{{ $collapsed ? 'false' : 'true' }}" aria-label="Thu gọn / mở rộng menu"
    >{{ ant_icon($collapsed ? 'menu-unfold' : 'menu-fold') }}</button
  >
  <div class="app-header-spacer"></div>
  <button type="button" class="app-header-account" aria-haspopup="menu" aria-expanded="false" aria-label="Menu tài khoản" title="{{ $user->name }} · {{ $user->email }}"
    >@include('backend.partials.avatar', ['user' => $user, 'size' => 'sm', 'placeholder' => true])<span class="app-header-account-name max-sm:hidden">{{ $user->name }}</span></button
  >
</header>
