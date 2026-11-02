{{-- Header theo template ProCMS (src/partials/header.html): chỉ có trên mobile (< 992px), gồm nút mở sidebar dạng drawer
     và tên app. Trên desktop tên app và tài khoản nằm ở đầu sidebar. --}}
<div class="app-header lg:hidden">
  <header class="ant-layout-header">
    <ul class="ant-menu ant-menu-light ant-menu-root ant-menu-horizontal" role="menu">
      <li class="ant-menu-item ant-menu-item-only-child" role="menuitem">
        <a class="trigger" href="#" role="button" aria-label="Mở menu" aria-expanded="false"
          ><svg width="1em" height="1em" fill="currentColor" viewBox="0 0 14 14" aria-hidden="true">
            <rect x="4" y="0" width="0.8" height="14"></rect>
            <rect x="7" y="0" width="0.8" height="14"></rect>
            <rect x="10" y="0" width="0.8" height="9"></rect></svg
        ></a>
      </li>
      <li class="ant-menu-item ant-menu-item-only-child" role="menuitem">
        <a class="brand gap-2.5" href="{{ route('backend.dashboard') }}"
          >@include('backend.partials.logo', ['class' => 'size-5 rounded ring-1 ring-[#f0f0f0]', 'textClass' => 'text-xs'])<strong>{{ config('app.name') }}</strong></a
        >
      </li>
    </ul>
  </header>
</div>
