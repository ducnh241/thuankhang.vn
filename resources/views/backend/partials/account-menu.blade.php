{{-- Menu tài khoản (dropdown Ant Design) mở từ avatar + tên ở góc phải header: resources/backend/js/layout.js chép nội dung
     <template> này ra popup. Đăng xuất gửi form POST #logout-form (có CSRF). "Xem website" là nút riêng trên header. --}}
@php $user = auth()->user(); @endphp
<template id="account-menu">
  <div class="ant-dropdown account-menu">
    <ul class="ant-dropdown-menu ant-dropdown-menu-root ant-dropdown-menu-vertical ant-dropdown-menu-light" role="menu">
      <li class="account-menu-user" role="presentation">
        <div class="account-menu-name">{{ $user->name }}</div>
        <div class="account-menu-email">{{ $user->email }}</div>
      </li>
      <li class="ant-dropdown-menu-item-divider" role="separator"></li>
      <li class="ant-dropdown-menu-item" role="presentation">
        <a href="{{ route('backend.profile.edit') }}" role="menuitem">Tài khoản</a>
      </li>
      <li class="ant-dropdown-menu-item-divider" role="separator"></li>
      <li class="ant-dropdown-menu-item" role="presentation">
        <a href="#" data-submit="logout-form" role="menuitem">Đăng xuất</a>
      </li>
    </ul>
  </div>
</template>
<form id="logout-form" method="POST" action="{{ route('backend.logout') }}" class="hidden">@csrf</form>
