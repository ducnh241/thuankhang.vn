{{-- Sidebar theo layout Admin Pro của template (nhánh admin-pro: src/partials/pro-sidebar.html, src/css/pro.css): nền tối
     #001529 rộng 208px, trên cùng là logo + tên app, giữa là menu lấy từ config('backend.menu') đã lọc theo quyền (xếp
     liền nhau như template, không hiện tên nhóm), dưới cùng là nút thu gọn còn 48px (chỉ icon; rê chuột hiện menu con / tên mục).
     Trạng thái thu gọn nhớ bằng cookie backend_sider nên server dựng sẵn đúng bề rộng, không giật khi tải trang.
     Mục đang mở được đánh dấu từ server (pro-menu-item-selected + aria-current), menu cha có mục con đang mở thì mở sẵn.
     Trên mobile (< 992px) sidebar ẩn; resources/backend/js/layout.js chép .pro-sider-body vào drawer. --}}
@php
    $user = auth()->user();
    // Menu xếp liền nhau như template (không hiện tên nhóm; nhóm trong config chỉ để sắp xếp). Lọc theo quyền: bỏ
    // item/con không có Gate; menu cha không còn con thì bỏ
    $allowed = fn ($item) => empty($item['can']) || $user->canAny((array) $item['can']);
    $menu = collect(config('backend.menu'))->flatMap(fn ($group) => $group['items'])
        ->filter($allowed)
        ->map(function ($item) use ($allowed) {
            if (isset($item['children'])) {
                $item['children'] = array_values(array_filter($item['children'], $allowed));
            }
            return $item;
        })
        ->filter(fn ($item) => ! isset($item['children']) || count($item['children']) > 0)
        ->values();
    // Item có 'params' (vd. danh mục theo loại) chỉ active khi URL hiện tại có đúng các tham số đó
    $isActive = fn ($item) => isset($item['route']) && request()->routeIs($item['active'] ?? $item['route'])
        && collect($item['params'] ?? [])->every(fn ($value, $key) => (string) request()->route($key) === (string) $value);
    $url = fn ($item) => route($item['route'], $item['params'] ?? []);
    $collapsed = request()->cookie('backend_sider') === 'collapsed';
@endphp
<div @class(['app-sidebar max-lg:hidden', 'is-collapsed' => $collapsed]) id="app-sidebar">
  <aside class="pro-sider" aria-label="Menu chính">
    <div class="pro-sider-body">
      <div class="pro-sider-logo">
        <a href="{{ route('backend.dashboard') }}" title="{{ config('app.name') }}"
          >@include('backend.partials.logo', ['class' => 'size-8', 'textClass' => 'text-lg'])<span class="pro-sider-brand">{{ config('app.name') }}</span></a
        >
      </div>
      <nav class="pro-sider-menu">
        <ul class="pro-menu" role="menu">
          @foreach ($menu as $item)
            @if (isset($item['children']))
              @php $open = collect($item['children'])->contains($isActive); @endphp
              <li @class(['pro-menu-submenu', 'pro-menu-submenu-open pro-menu-submenu-selected' => $open]) role="none">
                <button type="button" class="pro-menu-title" role="menuitem" aria-haspopup="true" aria-expanded="{{ $open ? 'true' : 'false' }}"
                  >{{ ant_icon($item['icon']) }}<span class="pro-menu-text">{{ $item['label'] }}</span><i class="pro-menu-arrow" aria-hidden="true"></i></button
                >
                <ul class="pro-menu-sub" role="menu" @if (! $open) hidden @endif>
                  @foreach ($item['children'] as $child)
                    <li @class(['pro-menu-item', 'pro-menu-item-selected' => $isActive($child)]) role="none">
                      <a href="{{ $url($child) }}" role="menuitem" @if ($isActive($child)) aria-current="page" @endif><span class="pro-menu-text">{{ $child['label'] }}</span></a>
                    </li>
                  @endforeach
                </ul>
              </li>
            @else
              <li @class(['pro-menu-item', 'pro-menu-item-selected' => $isActive($item)]) role="none">
                <a href="{{ $url($item) }}" role="menuitem" data-label="{{ $item['label'] }}" @if ($isActive($item)) aria-current="page" @endif
                  >{{ ant_icon($item['icon']) }}<span class="pro-menu-text">{{ $item['label'] }}</span></a
                >
              </li>
            @endif
          @endforeach
        </ul>
      </nav>
    </div>
    <button type="button" class="pro-sider-toggle" data-sider-toggle aria-controls="app-sidebar" aria-expanded="{{ $collapsed ? 'false' : 'true' }}" aria-label="Thu gọn / mở rộng menu"
      >{{ ant_icon($collapsed ? 'menu-unfold' : 'menu-fold') }}</button
    >
  </aside>
</div>
