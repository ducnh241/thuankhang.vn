{{-- Sidebar theo template ProCMS (src/partials/sidebar.html): nền tối, trên cùng là avatar (mở menu tài khoản) + tên app,
     bên dưới là menu lấy từ config('backend.menu') đã lọc theo quyền. Mục đang mở được đánh dấu ngay từ server
     (ant-menu-item-selected + aria-current), menu cha có mục con đang mở thì mở sẵn. Trên mobile resources/admin/js/layout.js
     chép phần ruột sidebar vào drawer. --}}
@php
    $user = auth()->user();
    // Lọc menu theo quyền: bỏ item/con không có Gate; menu cha không còn con thì bỏ; group rỗng thì bỏ
    $allowed = fn ($item) => empty($item['can']) || $user->canAny((array) $item['can']);
    $menu = collect(config('backend.menu'))->map(function ($group) use ($allowed) {
        $group['items'] = collect($group['items'])
            ->filter($allowed)
            ->map(function ($item) use ($allowed) {
                if (isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], $allowed));
                }
                return $item;
            })
            ->filter(fn ($item) => ! isset($item['children']) || count($item['children']) > 0)
            ->values()->all();
        return $group;
    })->filter(fn ($group) => count($group['items']) > 0);
    // Item có 'params' (vd. danh mục theo loại) chỉ active khi URL hiện tại có đúng các tham số đó
    $isActive = fn ($item) => isset($item['route']) && request()->routeIs($item['active'] ?? $item['route'])
        && collect($item['params'] ?? [])->every(fn ($value, $key) => (string) request()->route($key) === (string) $value);
    $url = fn ($item) => route($item['route'], $item['params'] ?? []);
@endphp
<div class="app-sidebar">
  <aside class="ant-layout-sider ant-layout-sider-light max-lg:hidden" style="flex: 0 0 220px; max-width: 220px; min-width: 220px; width: 220px">
    <div class="ant-layout-sider-children">
      <div class="sidebar-user">
        <button type="button" class="sidebar-user-button" aria-haspopup="menu" aria-expanded="false" aria-label="Menu tài khoản" title="{{ $user->name }} · {{ $user->email }}">
          @include('backend.partials.avatar', ['user' => $user, 'placeholder' => true])
        </button>
        <a class="sidebar-user-brand" href="{{ route('backend.dashboard') }}">{{ config('app.name') }}</a>
      </div>
      <ul class="ant-menu ant-menu-light border-0 overflow-x-hidden overflow-y-auto sidebar ant-menu-root ant-menu-inline" role="menu" style="flex: 1 1 0%; height: 100%">
        @foreach ($menu as $group)
          <li class="ant-menu-item-group" role="presentation">
            <div class="ant-menu-item-group-title" title="{{ $group['label'] }}">{{ $group['label'] }}</div>
            <ul class="ant-menu-item-group-list" role="group">
              @foreach ($group['items'] as $item)
                @if (isset($item['children']))
                  @php $open = collect($item['children'])->contains($isActive); @endphp
                  <li @class(['ant-menu-submenu ant-menu-submenu-inline', 'ant-menu-submenu-open ant-menu-submenu-selected' => $open]) role="menuitem">
                    <div class="ant-menu-submenu-title" role="button" tabindex="0" aria-expanded="{{ $open ? 'true' : 'false' }}" aria-haspopup="true" style="padding-left: 24px"
                      >{{ ant_icon($item['icon'], '', 'font-size: 16px') }}<span><span>{{ $item['label'] }}</span></span><i class="ant-menu-submenu-arrow"></i></div>
                    <ul @class(['ant-menu ant-menu-sub ant-menu-inline', 'ant-menu-hidden' => ! $open]) role="menu">
                      @foreach ($item['children'] as $child)
                        <li @class(['ant-menu-item ant-menu-item-only-child', 'ant-menu-item-selected' => $isActive($child)]) role="menuitem" style="padding-left: 48px">
                          <a href="{{ $url($child) }}" @if ($isActive($child)) aria-current="page" @endif><span class="mr-auto">{{ $child['label'] }}</span></a>
                        </li>
                      @endforeach
                    </ul>
                  </li>
                @else
                  <li @class(['ant-menu-item', 'ant-menu-item-selected' => $isActive($item)]) role="menuitem" style="padding-left: 24px">
                    {{ ant_icon($item['icon'], 'ant-menu-item-icon', 'font-size: 16px') }}<span
                      ><a href="{{ $url($item) }}" @if ($isActive($item)) aria-current="page" @endif><span class="mr-auto">{{ $item['label'] }}</span></a></span
                    >
                  </li>
                @endif
              @endforeach
            </ul>
          </li>
        @endforeach
      </ul>
    </div>
  </aside>
</div>
