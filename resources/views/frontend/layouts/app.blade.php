{{-- Khung website cửa hàng: thanh liên hệ, đầu trang (tên / logo, ô tìm kiếm), thanh danh mục + menu chính, chân trang.
     Dữ liệu chung ($site, $mainMenu, $footerMenu, $shopCategories) do View::composer trong AppServiceProvider đưa vào.
     Trang con: @section('title'), @section('description'), (tuỳ chọn) @section('image'), @section('content'). --}}
@php
    use App\Support\SiteSettings;

    $siteName = $site['site_name'] ?: config('app.name');
    $logo = SiteSettings::imageUrl($site['site_logo'] ?? null);
    $favicon = SiteSettings::imageUrl($site['site_favicon'] ?? null);
    // @section('x', $giá_trị) đã mã hóa HTML sẵn → giải mã để {{ }} bên dưới chỉ mã hóa một lần (tránh "&amp;amp;")
    $yield = fn (string $name) => html_entity_decode(trim($__env->yieldContent($name)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $title = $yield('title');
    $description = $yield('description') ?: ($site['seo_description'] ?: null);
    $image = $yield('image') ?: $logo;
    $socials = collect(SiteSettings::SOCIALS)->filter(fn ($s, $key) => ($site['social_'.$key] ?? '') !== '');
    $q = is_string(request('q')) ? request('q') : '';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>{{ $title !== '' ? $title.' - '.$siteName : ($site['seo_title'] ?: $siteName) }}</title>
  @if ($description)<meta name="description" content="{{ \Illuminate\Support\Str::limit($description, 160) }}" />@endif
  @hasSection('robots')<meta name="robots" content="@yield('robots')" />@endif
  <link rel="canonical" href="{{ url()->current() }}" />
  <meta property="og:type" content="website" />
  <meta property="og:site_name" content="{{ $siteName }}" />
  <meta property="og:title" content="{{ $title !== '' ? $title : $siteName }}" />
  @if ($description)<meta property="og:description" content="{{ \Illuminate\Support\Str::limit($description, 200) }}" />@endif
  @if ($image)<meta property="og:image" content="{{ $image }}" />@endif
  @if ($favicon)<link rel="icon" href="{{ $favicon }}" />@endif
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&display=swap" rel="stylesheet" />
  {{-- Build riêng của website (vite.frontend.config.js), không dùng CSS / JS admin. Đối tượng Vite riêng: không đổi
       hot file / thư mục build của Vite dùng chung (admin) --}}
  {{ (new \Illuminate\Foundation\Vite)->useHotFile(public_path('hot-frontend'))->useBuildDirectory('assets/frontend/build')->withEntryPoints(['resources/frontend/css/app.css', 'resources/frontend/js/app.js']) }}
  {!! $site['code_head'] !!}
</head>
<body class="flex min-h-screen flex-col pb-16 md:pb-0">
  {!! $site['code_body'] !!}

  {{-- Thanh liên hệ --}}
  @if ($site['site_hotline'] || $site['site_email'])
    <div class="hidden bg-brand-900 text-xs text-brand-50 sm:block">
      <div class="mx-auto flex max-w-7xl items-center justify-end gap-6 px-4 py-2">
        @if ($site['site_hotline'])<a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="hover:text-white">Hotline: <strong>{{ $site['site_hotline'] }}</strong></a>@endif
        @if ($site['site_email'])<a href="mailto:{{ $site['site_email'] }}" class="hover:text-white">{{ $site['site_email'] }}</a>@endif
      </div>
    </div>
  @endif

  <header class="sticky top-0 z-30 border-b border-slate-200 bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3">
      <button type="button" class="-ml-1 rounded-lg p-2 text-slate-600 hover:bg-slate-100 md:hidden" data-nav-toggle aria-controls="mobile-nav" aria-expanded="false" aria-label="Mở menu">
        <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16" /></svg>
      </button>
      <a href="{{ route('home') }}" class="flex min-w-0 shrink items-center" aria-label="{{ $siteName }} — Trang chủ">
        @include('frontend.partials.logo', ['size' => 'header'])
      </a>
      {{-- Tài khoản thành viên (guard "member") --}}
      <div class="ml-auto flex shrink-0 items-center gap-2 text-sm md:order-last md:ml-0">
        {{-- Giỏ hàng: số sản phẩm trong giỏ (session) --}}
        @php $cartCount = request()->hasSession() ? app(\App\Support\Cart::class)->count() : 0; @endphp
        <a href="{{ route('shop.cart') }}" class="relative rounded-full p-2 text-slate-600 hover:bg-slate-100 hover:text-brand-700" aria-label="Giỏ hàng{{ $cartCount ? " ($cartCount sản phẩm)" : '' }}" data-cart-link>
          <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L20 8H6.2" /><circle cx="9.5" cy="20" r="1.3" /><circle cx="17" cy="20" r="1.3" /></svg>
          @if ($cartCount)
            <span class="absolute -top-0.5 -right-0.5 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[11px] font-bold text-white" data-cart-count>{{ $cartCount > 99 ? '99+' : $cartCount }}</span>
          @endif
        </a>
        @if ($currentMember = auth('member')->user())
          {{-- Điện thoại: chỉ hình tròn chữ cái đầu (tên ẩn); từ sm: viên thuốc có tên --}}
          <a href="{{ route('member.account') }}" class="flex items-center gap-2 rounded-full font-medium text-slate-700 hover:text-brand-700 sm:border sm:border-slate-200 sm:py-1.5 sm:pr-4 sm:pl-1.5 sm:hover:border-brand-500" aria-label="Tài khoản: {{ $currentMember->full_name }}" data-member-menu>
            <span class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-sm font-bold text-white sm:size-7 sm:text-xs">{{ mb_strtoupper(mb_substr($currentMember->full_name, 0, 1)) }}</span>
            <span class="max-w-32 truncate max-sm:hidden">{{ $currentMember->full_name }}</span>
          </a>
        @else
          {{-- Điện thoại: một nút biểu tượng (trang đăng nhập có link sang đăng ký) --}}
          <a href="{{ route('member.login') }}" class="rounded-full p-2 text-slate-600 hover:bg-slate-100 sm:hidden" aria-label="Đăng nhập">
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="8" r="4" /><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5" /></svg>
          </a>
          <a href="{{ route('member.login') }}" class="rounded-full px-3 py-2 font-medium text-slate-700 hover:text-brand-700 max-sm:hidden">Đăng nhập</a>
          <a href="{{ route('member.register') }}" class="rounded-full bg-brand-600 px-4 py-2 font-semibold text-white hover:bg-brand-700 max-sm:hidden">Đăng ký</a>
        @endif
      </div>
      {{-- Điện thoại: ô tìm xuống dòng riêng, đủ rộng --}}
      <form action="{{ route('shop.search') }}" method="GET" role="search" class="order-last w-full md:order-none md:ml-auto md:w-auto md:min-w-0 md:max-w-md md:flex-1">
        <label class="relative block">
          <span class="sr-only">Tìm sản phẩm</span>
          <svg class="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="m20 20-3.5-3.5" /></svg>
          <input type="search" name="q" value="{{ $q }}" placeholder="Tìm sản phẩm…" maxlength="100"
            class="w-full rounded-full border border-slate-200 bg-slate-50 py-2 pr-4 pl-10 text-sm outline-none transition focus:border-brand-500 focus:bg-white focus:ring-2 focus:ring-brand-100" />
        </label>
      </form>
    </div>
    {{-- Thanh danh mục + menu chính; điện thoại: xổ xuống khi bấm nút menu --}}
    {{-- Máy tính: thanh danh mục dưới header. Điện thoại: dùng ngăn kéo #mobile-nav bên dưới --}}
    <nav id="site-nav" class="hidden border-t border-slate-100 md:block" aria-label="Danh mục">
      <ul class="mx-auto flex max-w-7xl flex-col gap-1 px-4 py-2 text-sm font-medium md:flex-row md:flex-wrap md:items-center md:gap-6 md:py-0">
        @foreach ($shopCategories as $category)
          <li><a href="{{ $category->url() }}" @class(['block py-2 md:py-3 hover:text-brand-700', 'text-brand-700' => request()->is(ltrim($category->url(), '/'))])>{{ $category->name }}</a></li>
        @endforeach
        {{-- Menu chính (chỉ mục cấp 1) đứng bên phải thanh danh mục --}}
        @foreach ($mainMenu as $item)
          @php [$href, $external] = \App\Models\Menu::safeLink($item['url']); @endphp
          <li @class(['md:ml-auto' => $loop->first]) data-menu-item>
            <a href="{{ $href }}" class="block py-2 text-slate-500 hover:text-brand-700 md:py-3"@if ($external) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a>
          </li>
        @endforeach
      </ul>
    </nav>
  </header>

  {{-- Điện thoại: ngăn kéo menu trượt từ trái (đặt ngoài header: header có backdrop-blur nên "fixed" bên trong bị giới hạn).
       JS [data-nav-toggle] / [data-nav-close] trong frontend/js/app.js --}}
  <div class="fixed inset-0 z-50 md:hidden" id="mobile-nav-root" data-mobile-nav hidden>
    <div class="absolute inset-0 bg-slate-900/40 opacity-0 transition-opacity duration-300 [[data-mobile-nav].is-open_&]:opacity-100" data-nav-close></div>
    <nav id="mobile-nav" class="absolute inset-y-0 left-0 flex w-[82%] max-w-xs -translate-x-full flex-col bg-white shadow-2xl transition-transform duration-300 ease-out [[data-mobile-nav].is-open_&]:translate-x-0" aria-label="Menu">
      <div class="flex items-center justify-between gap-3 border-b border-slate-100 px-4 py-3">
        <a href="{{ route('home') }}" class="flex min-w-0 items-center" aria-label="{{ $siteName }} — Trang chủ">
          @include('frontend.partials.logo', ['size' => 'drawer'])
        </a>
        <button type="button" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100" data-nav-close aria-label="Đóng menu">
          <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" d="M6 6l12 12M18 6 6 18" /></svg>
        </button>
      </div>
      <div class="flex-1 overflow-y-auto px-2 py-3 text-[15px]">
        @if ($shopCategories->isNotEmpty())
          <div class="px-3 pb-1 text-xs font-semibold tracking-wide text-slate-400 uppercase">Danh mục</div>
          <ul>
            @foreach ($shopCategories as $category)
              <li><a href="{{ $category->url() }}" @class(['block rounded-lg px-3 py-2.5 font-medium hover:bg-slate-50 hover:text-brand-700', 'bg-brand-50 text-brand-700' => request()->is(ltrim($category->url(), '/')), 'text-slate-800' => ! request()->is(ltrim($category->url(), '/'))])>{{ $category->name }}</a></li>
            @endforeach
          </ul>
        @endif
        @if (count($mainMenu))
          <ul class="mt-2 border-t border-slate-100 pt-2">
            @foreach ($mainMenu as $item)
              @php [$href, $external] = \App\Models\Menu::safeLink($item['url']); @endphp
              <li><a href="{{ $href }}" class="block rounded-lg px-3 py-2.5 text-slate-600 hover:bg-slate-50 hover:text-brand-700"@if ($external) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a></li>
            @endforeach
          </ul>
        @endif
      </div>
      <div class="border-t border-slate-100 p-4 text-sm">
        @if ($currentMember)
          <div class="flex gap-2">
            <a href="{{ route('member.orders.index') }}" class="flex-1 rounded-full border border-slate-200 py-2 text-center font-semibold text-slate-700">Đơn hàng</a>
            <a href="{{ route('member.account') }}" class="flex-1 rounded-full bg-brand-600 py-2 text-center font-semibold text-white">Tài khoản</a>
          </div>
        @else
          <div class="flex gap-2">
            <a href="{{ route('member.login') }}" class="flex-1 rounded-full border border-slate-200 py-2 text-center font-semibold text-slate-700">Đăng nhập</a>
            <a href="{{ route('member.register') }}" class="flex-1 rounded-full bg-brand-600 py-2 text-center font-semibold text-white">Đăng ký</a>
          </div>
        @endif
        @if ($site['site_hotline'] ?? '')
          <a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="mt-3 block text-center text-slate-500">Hotline: <span class="font-semibold text-brand-700">{{ $site['site_hotline'] }}</span></a>
        @endif
      </div>
    </nav>
  </div>

  <main class="flex-1">
    @include('frontend.partials.flash')
    @yield('content')
  </main>

  <footer class="mt-16 border-t border-slate-200 bg-white">
    <div class="mx-auto grid max-w-7xl gap-8 px-4 py-10 sm:grid-cols-2 lg:grid-cols-4">
      <div class="lg:col-span-2">
        <a href="{{ route('home') }}" class="inline-flex" aria-label="{{ $siteName }} — Trang chủ">@include('frontend.partials.logo', ['size' => 'footer'])</a>
        @if ($site['site_address'])<p class="mt-2 text-sm text-slate-600">{{ $site['site_address'] }}</p>@endif
        @if ($site['site_hotline'])<p class="mt-1 text-sm text-slate-600">Hotline: <a href="tel:{{ preg_replace('/[^0-9+]/', '', $site['site_hotline']) }}" class="font-medium text-brand-700">{{ $site['site_hotline'] }}</a></p>@endif
        @if ($site['site_email'])<p class="mt-1 text-sm text-slate-600">Email: <a href="mailto:{{ $site['site_email'] }}" class="text-brand-700">{{ $site['site_email'] }}</a></p>@endif
        @if ($socials->isNotEmpty())
          <div class="mt-4 flex flex-wrap gap-3 text-sm">
            @foreach ($socials as $key => [$label])
              <a href="{{ \App\Models\Menu::safeLink($site['social_'.$key])[0] }}" target="_blank" rel="noopener" class="rounded-full border border-slate-200 px-3 py-1 text-slate-600 hover:border-brand-500 hover:text-brand-700">{{ $label }}</a>
            @endforeach
          </div>
        @endif
      </div>
      <div>
        <div class="text-sm font-semibold text-slate-900">Danh mục</div>
        <ul class="mt-3 space-y-2 text-sm text-slate-600">
          @foreach ($shopCategories as $category)
            <li><a href="{{ $category->url() }}" class="hover:text-brand-700">{{ $category->name }}</a></li>
          @endforeach
        </ul>
      </div>
      @if ($footerMenu)
        <div>
          <div class="text-sm font-semibold text-slate-900">Thông tin</div>
          <ul class="mt-3 space-y-2 text-sm text-slate-600">
            @foreach ($footerMenu as $item)
              @php [$href, $external] = \App\Models\Menu::safeLink($item['url']); @endphp
              <li><a href="{{ $href }}" class="hover:text-brand-700"@if ($external) target="_blank" rel="noopener"@endif>{{ $item['label'] }}</a></li>
            @endforeach
          </ul>
        </div>
      @endif
    </div>
    <div class="border-t border-slate-100 py-4 text-center text-xs text-slate-500">
      @if ($site['site_footer']){!! \App\Support\RichText::clean($site['site_footer']) !!}@else© {{ date('Y') }} {{ $siteName }}@endif
    </div>
  </footer>

  {{-- Điện thoại: thanh điều hướng dưới kiểu app (Shoplife) — Trang chủ, Giỏ hàng, Đơn hàng, Tài khoản --}}
  @php
      $tabs = [
          ['route' => 'home', 'label' => 'Trang chủ', 'active' => request()->routeIs('home', 'shop.category', 'shop.product', 'shop.search'),
              'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z" />'],
          ['route' => 'shop.cart', 'label' => 'Giỏ hàng', 'active' => request()->routeIs('shop.cart', 'shop.checkout'), 'badge' => $cartCount,
              'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 4h2l2.4 11.2a1 1 0 0 0 1 .8h9.2a1 1 0 0 0 1-.8L20 8H6.2" /><circle cx="9.5" cy="20" r="1.3" /><circle cx="17" cy="20" r="1.3" />'],
          ['route' => 'member.orders.index', 'label' => 'Đơn hàng', 'active' => request()->routeIs('member.orders.*'),
              'icon' => '<rect x="5" y="3" width="14" height="18" rx="2" /><path stroke-linecap="round" d="M9 8h6M9 12h6M9 16h3" />'],
          ['route' => 'member.account', 'label' => 'Tài khoản', 'active' => request()->routeIs('member.account', 'member.login', 'member.register'),
              'icon' => '<circle cx="12" cy="8" r="4" /><path stroke-linecap="round" d="M4 20c1.5-3.5 4.5-5 8-5s6.5 1.5 8 5" />'],
      ];
  @endphp
  <nav class="fixed inset-x-0 bottom-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur md:hidden" aria-label="Điều hướng nhanh" data-bottom-nav>
    <ul class="grid grid-cols-4">
      @foreach ($tabs as $tab)
        <li>
          <a href="{{ route($tab['route']) }}" @class(['relative flex flex-col items-center gap-0.5 py-2 text-[11px] font-medium', 'text-brand-700' => $tab['active'], 'text-slate-500' => ! $tab['active']]) @if ($tab['active']) aria-current="page" @endif>
            <svg class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">{!! $tab['icon'] !!}</svg>
            {{ $tab['label'] }}
            @if (! empty($tab['badge']))
              <span class="absolute top-1 left-1/2 ml-1.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold text-white">{{ $tab['badge'] > 99 ? '99+' : $tab['badge'] }}</span>
            @endif
          </a>
        </li>
      @endforeach
    </ul>
  </nav>
  {!! $site['code_bottom'] !!}
</body>
</html>
